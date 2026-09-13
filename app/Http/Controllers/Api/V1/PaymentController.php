<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\EscrowBalance;
use App\Models\Milestone;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

class PaymentController extends Controller
{
    public function __construct(private readonly StripeService $stripe) {}

    // ── Stripe Connect onboarding ─────────────────────────────────────────────

    /**
     * POST /api/v1/connect/onboard
     * Create (or retrieve) a Stripe Express account and return the onboarding URL.
     */
    public function onboard(Request $request): JsonResponse
    {
        $user = $request->user();

        // Reuse existing account if already created
        $account = $user->paymentAccount ?? PaymentAccount::create(['user_id' => $user->id]);

        if (! $account->stripe_account_id) {
            $stripeAccount = $this->stripe->createConnectAccount($user->email);
            $account->update(['stripe_account_id' => $stripeAccount->id]);
        }

        $link = $this->stripe->createAccountLink(
            $account->stripe_account_id,
            url('/api/v1/connect/refresh'),
            url('/api/v1/connect/return'),
        );

        return response()->json(['onboarding_url' => $link->url]);
    }

    /**
     * GET /api/v1/connect/refresh
     * Stripe redirects here when the onboarding link expires — re-generate a fresh one.
     */
    public function connectRefresh(Request $request): JsonResponse
    {
        $user    = $request->user();
        $account = $user->paymentAccount;

        abort_if(! $account?->stripe_account_id, 422, 'No Stripe account found. Start onboarding first.');

        $link = $this->stripe->createAccountLink(
            $account->stripe_account_id,
            url('/api/v1/connect/refresh'),
            url('/api/v1/connect/return'),
        );

        return response()->json(['onboarding_url' => $link->url]);
    }

    /**
     * GET /api/v1/connect/return
     * Stripe redirects here after onboarding completes.
     */
    public function connectReturn(Request $request): JsonResponse
    {
        $user    = $request->user();
        $account = $user->paymentAccount;

        if ($account?->stripe_account_id) {
            // Refresh account capabilities from Stripe
            $stripeAccount = (new \Stripe\StripeClient(config('services.stripe.secret')))
                ->accounts->retrieve($account->stripe_account_id);

            $account->update([
                'status'          => 'active',
                'payout_enabled'  => $stripeAccount->payouts_enabled,
                'charges_enabled' => $stripeAccount->charges_enabled,
                'capabilities'    => $stripeAccount->capabilities->toArray(),
            ]);
        }

        return response()->json(['message' => 'Stripe Connect account updated.', 'account' => $account->fresh()]);
    }

    // ── Fund Escrow ───────────────────────────────────────────────────────────

    /**
     * POST /api/v1/contracts/{id}/fund
     * Client funds the escrow — creates a Stripe PaymentIntent (manual capture).
     * Status stays "held" until the webhook payment_intent.succeeded fires.
     *
     * If the freelancer has already connected their Stripe account, the PaymentIntent
     * is created on_behalf_of that account so funds can be transferred without an
     * extra transfer step. Platform fee (STRIPE_PLATFORM_FEE_PERCENT) is applied.
     */
    public function fund(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        abort_if($user->id !== $contract->client_id, 403, 'Only the client can fund a contract.');
        abort_if($contract->status !== 'active', 422, 'Contract must be active before funding.');
        abort_if($contract->escrowBalance?->status === 'funded', 422, 'Contract is already funded.');

        $amountCents       = (int) round((float) $contract->total_amount * 100);
        $idempotencyKey    = "fund-{$contract->id}";

        // Optional: route directly to freelancer's connected account if already onboarded
        $freelancerAccount = $contract->freelancer?->paymentAccount?->stripe_account_id;
        $feePercent        = (float) config('services.stripe.platform_fee_percent', 0);
        $feeCents          = $freelancerAccount && $feePercent > 0
            ? (int) round($amountCents * ($feePercent / 100))
            : 0;

        $intent = $this->stripe->createPaymentIntent(
            $amountCents,
            $contract->currency,
            $contract->id,
            $idempotencyKey,
            $freelancerAccount,
            $feeCents,
        );

        DB::transaction(function () use ($contract, $intent, $feeCents) {
            EscrowBalance::updateOrCreate(
                ['contract_id' => $contract->id],
                [
                    'held_amount'              => $contract->total_amount,
                    'currency'                 => $contract->currency,
                    'status'                   => 'funded',
                    'stripe_payment_intent_id' => $intent->id,
                ]
            );

            Transaction::create([
                'contract_id'      => $contract->id,
                'initiated_by'     => $contract->client_id,
                'type'             => 'deposit',
                'amount'           => $contract->total_amount,
                'currency'         => $contract->currency,
                'stripe_reference' => $intent->id,
                'status'           => 'pending', // updated to 'completed' via webhook
                'notes'            => $feeCents > 0
                    ? "Platform fee: {$feeCents} cents applied."
                    : null,
            ]);
        });

        return response()->json([
            'message'       => 'Escrow funding initiated.',
            'client_secret' => $intent->client_secret, // for Stripe.js on the frontend
            'escrow'        => $contract->fresh()->escrowBalance,
        ]);
    }

    // ── Release Funds ─────────────────────────────────────────────────────────

    /**
     * POST /api/v1/milestones/{id}/release
     * Release held funds to freelancer after milestone approval.
     * Wrapped in a DB transaction — DB record + Stripe transfer must both succeed.
     */
    public function release(Request $request, Milestone $milestone): JsonResponse
    {
        $user     = $request->user();
        $contract = $milestone->contract;

        abort_if($user->id !== $contract->client_id, 403, 'Only the client can release funds.');
        abort_if($milestone->status !== 'approved', 422, 'Milestone must be approved before releasing funds.');

        // Safety boundary: never release funds if ANY dispute exists on this milestone,
        // even if the dispute is already resolved. Resolved disputes require an explicit
        // admin action — not a client-triggered release. This prevents accidental double-
        // payment or release after a refund decision.
        $dispute = $milestone->disputes()->first();
        abort_if(
            $dispute !== null,
            422,
            'This milestone has a dispute on record. Funds can only be released by an admin after dispute resolution.'
        );

        $freelancer = $contract->freelancer;
        abort_if(
            ! $freelancer?->paymentAccount?->stripe_account_id,
            422,
            'The freelancer has not connected a payout account yet.'
        );

        $amountCents    = (int) round((float) $milestone->amount * 100);
        $idempotencyKey = "release-{$milestone->id}";

        // Stripe call is OUTSIDE the DB transaction intentionally.
        // Pattern: call Stripe first (idempotent key protects against duplicates),
        // then persist to DB. If DB write fails after Stripe succeeds, we log critical
        // and the idempotency key prevents double-transfer on retry.
        try {
            $transfer = $this->stripe->createTransfer(
                $amountCents,
                $contract->currency,
                $freelancer->paymentAccount->stripe_account_id,
                $milestone->id,
                $idempotencyKey,
            );
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            Log::error("Stripe transfer failed for milestone release {$milestone->id}", [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'error'  => 'Stripe transfer failed. No funds moved.',
                'detail' => $e->getMessage(),
            ], 502);
        }

        try {
            DB::transaction(function () use ($milestone, $contract, $transfer) {
                $milestone->update(['status' => 'released']);

                Transaction::create([
                    'contract_id'        => $contract->id,
                    'milestone_id'       => $milestone->id,
                    'initiated_by'       => $contract->client_id,
                    'type'               => 'release',
                    'amount'             => $milestone->amount,
                    'currency'           => $contract->currency,
                    'stripe_transfer_id' => $transfer->id,
                    'status'             => 'pending', // promoted to completed via webhook
                ]);

                $escrow = $contract->escrowBalance;
                if ($escrow) {
                    $escrow->increment('released_amount', $milestone->amount);
                }
            });
        } catch (\Throwable $e) {
            Log::critical('Stripe transfer succeeded but DB write failed — manual reconciliation required.', [
                'milestone_id' => $milestone->id,
                'transfer_id'  => $transfer->id,
                'error'        => $e->getMessage(),
            ]);
            return response()->json([
                'error'       => 'Transfer issued but database not updated. Contact support immediately.',
                'transfer_id' => $transfer->id,
            ], 500);
        }

        return response()->json([
            'message'   => 'Funds released to freelancer.',
            'milestone' => $milestone->fresh(),
        ]);
    }

    // ── Transaction ledger ────────────────────────────────────────────────────

    /**
     * GET /api/v1/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();

        $transactions = Transaction::with(['contract:id,title', 'milestone:id,title'])
            ->whereHas('contract', fn ($q) => $q->forUser($user->id))
            ->when($request->contract_id, fn ($q, $id) => $q->where('contract_id', $id))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->latest()
            ->paginate(20);

        return response()->json($transactions);
    }

    // ── Withdrawal ────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/payouts/withdraw
     * Freelancer withdraws available (released) balance via Stripe Connect payout.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate([
            'amount'   => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
        ]);

        $user = $request->user();

        abort_if(! $user->isFreelancer(), 403, 'Only freelancers can withdraw funds.');
        abort_if(! $user->paymentAccount?->isActive(), 422, 'No active payout account. Connect your Stripe account first.');

        $amountCents    = (int) round((float) $request->amount * 100);
        $idempotencyKey = "payout-{$user->id}-" . now()->format('YmdHis');

        DB::transaction(function () use ($user, $request, $amountCents, $idempotencyKey) {
            $payout = $this->stripe->createPayout(
                $amountCents,
                $request->currency,
                $user->paymentAccount->stripe_account_id,
                $idempotencyKey,
            );

            Transaction::create([
                'contract_id'      => null, // payouts are not contract-scoped
                'initiated_by'     => $user->id,
                'type'             => 'payout',
                'amount'           => $request->amount,
                'currency'         => $request->currency,
                'stripe_reference' => $payout->id,
                'status'           => 'processing',
                'notes'            => "Payout to connected account {$user->paymentAccount->stripe_account_id}",
            ]);
        });

        return response()->json(['message' => 'Withdrawal request submitted. Funds arrive within 1–2 business days.']);
    }

    // ── Stripe Webhooks ───────────────────────────────────────────────────────

    /**
     * POST /api/v1/webhooks/stripe
     * All payment state changes are driven by webhooks, never by initial API responses.
     */
    public function stripeWebhook(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');
        $secret    = config('services.stripe.webhook_secret');

        // Verify signature — reject anything that doesn't match
        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        match ($event->type) {
            'payment_intent.succeeded'  => $this->handlePaymentIntentSucceeded($event->data->object),
            'transfer.created'          => $this->handleTransferCreated($event->data->object),
            'charge.dispute.created'    => $this->handleChargeDisputeCreated($event->data->object),
            'charge.refunded'           => $this->handleChargeRefunded($event->data->object),
            'account.updated'           => $this->handleAccountUpdated($event->data->object),
            default                     => null, // ignore unhandled event types
        };

        return response()->json(['received' => true]);
    }

    // ── Webhook handlers ──────────────────────────────────────────────────────

    private function handlePaymentIntentSucceeded(\Stripe\PaymentIntent $intent): void
    {
        Transaction::where('stripe_reference', $intent->id)
            ->where('type', 'deposit')
            ->update(['status' => 'completed']);

        Log::info("PaymentIntent {$intent->id} succeeded — deposit marked completed.");
    }

    private function handleTransferCreated(\Stripe\Transfer $transfer): void
    {
        Transaction::where('stripe_transfer_id', $transfer->id)
            ->update(['status' => 'completed']);

        Log::info("Transfer {$transfer->id} confirmed — release marked completed.");
    }

    private function handleChargeRefunded(\Stripe\Charge $charge): void
    {
        // A charge.refunded event fires when a refund is confirmed by Stripe.
        // Match the refund back to our Transaction via the refund ID in the charge's refunds list.
        foreach ($charge->refunds->data as $refund) {
            $updated = Transaction::where('stripe_reference', $refund->id)
                ->where('type', 'refund')
                ->where('status', 'pending')
                ->update(['status' => 'completed']);

            if ($updated) {
                Log::info("Refund {$refund->id} confirmed via webhook — transaction marked completed.");
            }
        }
    }

    private function handleChargeDisputeCreated(\Stripe\Dispute $dispute): void
    {
        // A chargeback was filed against a payment — flag the related transaction
        $paymentIntentId = $dispute->payment_intent ?? null;

        if ($paymentIntentId) {
            Transaction::where('stripe_reference', $paymentIntentId)
                ->update(['status' => 'reversed', 'notes' => "Stripe chargeback: {$dispute->id}"]);
        }

        Log::warning("Stripe chargeback created: {$dispute->id}", [
            'payment_intent' => $paymentIntentId,
            'reason'         => $dispute->reason,
        ]);
    }

    /**
     * account.updated — Stripe notifies us when a connected account's capabilities change.
     * Keeps PaymentAccount in sync without requiring the freelancer to hit /connect/return again.
     */
    private function handleAccountUpdated(\Stripe\Account $account): void
    {
        $paymentAccount = PaymentAccount::where('stripe_account_id', $account->id)->first();

        if (! $paymentAccount) {
            return; // Unknown account — ignore
        }

        $paymentAccount->update([
            'status'          => $account->charges_enabled ? 'active' : 'restricted',
            'payout_enabled'  => $account->payouts_enabled,
            'charges_enabled' => $account->charges_enabled,
            'capabilities'    => $account->capabilities->toArray(),
        ]);

        Log::info("Connected account {$account->id} updated via webhook.", [
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
        ]);
    }
}
