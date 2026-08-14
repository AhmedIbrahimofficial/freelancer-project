<?php

namespace App\Listeners;

use App\Events\MilestoneApproved;
use App\Mail\MilestoneApprovedMail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyMilestoneApproved implements ShouldQueue
{
    public function __construct(private readonly StripeService $stripe) {}

    public function handle(MilestoneApproved $event): void
    {
        $milestone  = $event->milestone->load(['contract.client', 'contract.freelancer']);
        $contract   = $milestone->contract;
        $freelancer = User::find($contract->freelancer_id);

        // ── Notify freelancer by email ────────────────────────────────────────
        if ($freelancer) {
            Mail::to($freelancer->email)->queue(new MilestoneApprovedMail($milestone, $freelancer));
        }

        // ── Auto-release funds if freelancer has a connected Stripe account ──
        $paymentAccount = $freelancer?->paymentAccount;
        if (! $paymentAccount?->stripe_account_id) {
            Log::info("Milestone {$milestone->id} approved but freelancer has no Stripe account — skipping auto-release.");
            return;
        }

        // Skip Stripe transfer if the service is not configured (e.g. test environment)
        if (! $this->stripe->isConfigured()) {
            Log::info("Stripe not configured — skipping auto-release for milestone {$milestone->id}.");
            return;
        }

        $amountCents    = (int) round((float) $milestone->amount * 100);
        $idempotencyKey = "auto-release-{$milestone->id}";

        try {
            DB::transaction(function () use ($milestone, $contract, $paymentAccount, $amountCents, $idempotencyKey) {
                $transfer = $this->stripe->createTransfer(
                    $amountCents,
                    $contract->currency,
                    $paymentAccount->stripe_account_id,
                    $milestone->id,
                    $idempotencyKey,
                );

                $milestone->update(['status' => 'released']);

                Transaction::create([
                    'contract_id'        => $contract->id,
                    'milestone_id'       => $milestone->id,
                    'initiated_by'       => null,
                    'type'               => 'release',
                    'amount'             => $milestone->amount,
                    'currency'           => $contract->currency,
                    'stripe_transfer_id' => $transfer->id,
                    'status'             => 'completed',
                    'notes'              => 'Auto-released on milestone approval.',
                ]);

                $escrow = $contract->escrowBalance;
                if ($escrow) {
                    $escrow->increment('released_amount', $milestone->amount);
                }
            });

            Log::info("Auto-released {$milestone->amount} {$contract->currency} for milestone {$milestone->id}.");
        } catch (\Throwable $e) {
            Log::error("Auto-release failed for milestone {$milestone->id}: {$e->getMessage()}");
            // Job will be retried by the queue — do not swallow the exception
            throw $e;
        }
    }
}
