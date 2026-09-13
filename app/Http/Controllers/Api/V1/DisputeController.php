<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DisputeResolutionExecuted;
use App\Events\DisputeResolved;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\Milestone;
use App\Models\Transaction;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DisputeController extends Controller
{
    public function __construct(private readonly StripeService $stripe) {}
    /**
     * POST /api/v1/disputes/{id}/evidence
     * Either party submits a message or file as evidence.
     */
    public function submitEvidence(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'message' => 'nullable|string|max:10000',
            'file'    => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,gif,webp,mp4,zip,doc,docx',
        ]);

        $user = $request->user();

        abort_if(
            ! in_array($user->id, [$dispute->contract->client_id, $dispute->contract->freelancer_id]) && ! $user->isAdmin(),
            403,
            'You are not a party to this dispute.'
        );
        abort_if($dispute->isResolved(), 422, 'Cannot add evidence to a resolved dispute.');
        abort_if(! $request->filled('message') && ! $request->hasFile('file'), 422, 'Provide a message or a file.');

        $filePath  = null;
        $fileName  = null;
        $fileMime  = null;
        $fileSize  = null;

        if ($request->hasFile('file')) {
            $file      = $request->file('file');
            $filePath  = $file->store("disputes/{$dispute->id}/evidence", 'local');
            $fileName  = $file->getClientOriginalName();
            $fileMime  = $file->getMimeType();
            $fileSize  = $file->getSize();
        }

        $evidence = DisputeEvidence::create([
            'dispute_id' => $dispute->id,
            'user_id'    => $user->id,
            'message'    => $request->message,
            'file_path'  => $filePath,
            'file_name'  => $fileName,
            'file_mime'  => $fileMime,
            'file_size'  => $fileSize,
        ]);

        return response()->json([
            'message'  => 'Evidence submitted.',
            'evidence' => $evidence->load('user:id,name,email'),
        ], 201);
    }

    /**
     * PATCH /api/v1/disputes/{id}/resolve
     * Mediator or admin sets the resolution decision.
     *
     * For resolved_split, `freelancer_percent` (0–100) is required.
     * This is the percentage of the available escrow that goes to the freelancer.
     * The remainder is refunded to the client.
     *
     * Examples:
     *   freelancer_percent: 70  → freelancer gets 70%, client gets 30%
     *   freelancer_percent: 0   → equivalent to resolved_client (use that instead)
     *   freelancer_percent: 100 → equivalent to resolved_freelancer (use that instead)
     */
    public function resolve(Request $request, Dispute $dispute): JsonResponse
    {
        $request->validate([
            'status'             => 'required|in:resolved_client,resolved_freelancer,resolved_split,closed',
            'resolution_notes'   => 'required|string|max:10000',
            // freelancer_percent is required when status = resolved_split.
            // Must be 1–99: 0% means full refund (use resolved_client),
            // 100% means full transfer (use resolved_freelancer).
            // Supports decimals up to 2 places matching DECIMAL(5,2) storage.
            // 'present_if' ensures null/absent is explicitly rejected for resolved_split.
            'freelancer_percent' => [
                'present_if:status,resolved_split',
                'exclude_unless:status,resolved_split',
                'numeric',
                'min:1',
                'max:99',
            ],
        ]);

        $user = $request->user();

        abort_if(! $user->isAdmin(), 403, 'Only admins and mediators can resolve disputes.');
        abort_if($dispute->isResolved(), 422, 'This dispute is already resolved.');

        $updateData = [
            'status'           => $request->status,
            'resolution_notes' => $request->resolution_notes,
            'resolved_at'      => now(),
        ];

        if ($request->status === 'resolved_split') {
            $updateData['split_freelancer_percent'] = round((float) $request->freelancer_percent, 2);
            $updateData['split_state']              = 'pending';
        }

        $dispute->update($updateData);

        activity('disputes')
            ->performedOn($dispute)
            ->causedBy($user)
            ->withProperties([
                'status'             => $request->status,
                'freelancer_percent' => $request->freelancer_percent,
            ])
            ->log('resolved');

        DisputeResolved::dispatch($dispute->fresh());

        return response()->json([
            'message' => 'Dispute resolved.',
            'dispute' => $dispute->fresh()->load(['raisedBy:id,name,email', 'mediator:id,name,email']),
        ]);
    }

    /**
     * POST /api/v1/disputes/{id}/execute-resolution
     *
     * Translates a resolved dispute into a Stripe financial action.
     * This is explicitly separate from PATCH /resolve — the admin first sets the
     * decision, then separately executes the money movement.
     *
     * Guards:
     *  - Only admins/mediators
     *  - Dispute must already be resolved (status is resolved_*)
     *  - Execution is idempotent — second call returns the first result, no second Stripe call
     *  - Freelancer must have a connected Stripe account for transfer resolutions
     *  - Stripe failure rolls back all local state changes (DB transaction wraps everything
     *    except the Stripe call, which is placed last to minimise rollback window)
     *  - Webhook (transfer.created / charge.refunded) provides final confirmation,
     *    but we record 'pending' immediately and promote to 'completed' via webhook
     */
    public function executeResolution(Request $request, Dispute $dispute): JsonResponse
    {
        $admin = $request->user();

        // ── Guard: only admins ─────────────────────────────────────────────────
        abort_if(! $admin->isAdmin(), 403, 'Only admins can execute dispute resolutions.');

        // ── Guard: must be resolved first ──────────────────────────────────────
        abort_if(
            ! $dispute->isResolved(),
            422,
            'Dispute must be resolved (status: resolved_*) before execution. Call PATCH /resolve first.'
        );

        // ── Atomic execution claim ─────────────────────────────────────────────
        //
        // Production-grade concurrency pattern:
        //   Step 1: Atomically claim execution in a SHORT DB transaction.
        //           Lock the row, check state, write 'claiming', commit.
        //           Lock is released immediately after commit — minimises lock contention.
        //   Step 2: Stripe call happens OUTSIDE any transaction or lock.
        //   Step 3: Write final 'complete' state to DB.
        //
        // Why this is better than lock-then-check-then-Stripe:
        //   If we held the lock across the Stripe call (which can take 1-5s), any
        //   concurrent request would be blocked for that entire duration. Worse, if
        //   the process crashes mid-Stripe, the lock is released on disconnect and
        //   a second process would find "not executed" and call Stripe again.
        //
        //   With the 'claiming' state persisted atomically:
        //   - The lock is held for <1ms (just a read + write)
        //   - A concurrent request sees 'claiming' and returns 409 immediately
        //   - If the first process crashes after 'claiming', the state is visible
        //     and an admin can investigate/manually reconcile
        //   - On partial_failure resume, the claim is re-acquired correctly

        $claimResult = DB::transaction(function () use ($dispute) {
            /** @var \App\Models\Dispute $fresh */
            $fresh = \App\Models\Dispute::lockForUpdate()->findOrFail($dispute->id);

            // Already fully executed — return cached result
            if ($fresh->isExecuted() && ! $fresh->isSplitPartialFailure()) {
                return ['status' => 'already_executed', 'dispute' => $fresh];
            }

            // Another process is currently running Stripe — do not proceed
            if ($fresh->isClaiming()) {
                return ['status' => 'claiming'];
            }

            // Atomically claim: write 'claiming' and commit — lock releases here
            $fresh->update(['execution_state' => 'claiming']);

            return ['status' => 'claimed', 'dispute' => $fresh];
        });

        if ($claimResult['status'] === 'already_executed') {
            $fresh = $claimResult['dispute'];
            return response()->json([
                'message'          => 'Resolution already executed. No action taken.',
                'action'           => $fresh->resolutionAction(),
                'stripe_reference' => $fresh->resolution_stripe_reference,
                'executed_at'      => $fresh->resolution_executed_at,
            ]);
        }

        if ($claimResult['status'] === 'claiming') {
            return response()->json([
                'error'   => 'Resolution execution is already in progress by another request. Retry in a few seconds.',
                'status'  => 'claiming',
            ], 409);
        }

        // Refresh local $dispute instance with the persisted 'claiming' state
        $dispute->refresh();

        $action    = $dispute->resolutionAction();
        $contract  = $dispute->contract;
        $escrow    = $contract->escrowBalance;
        $milestone = $dispute->milestone;

        abort_if(! $escrow, 422, 'No escrow balance found for this contract.');
        abort_if(! $milestone, 422, 'No milestone linked to this dispute.');

        // ── Action: none (closed without financial movement) ───────────────────
        if ($action === 'none') {
            $dispute->update([
                'resolution_executed_at'      => now(),
                'executed_by'                 => $admin->id,
                'resolution_stripe_reference' => 'no_action',
                'execution_state'             => 'complete',
            ]);

            activity('disputes')
                ->performedOn($dispute)
                ->causedBy($admin)
                ->withProperties(['action' => 'none'])
                ->log('resolution_executed');

            DisputeResolutionExecuted::dispatch($dispute->fresh(), 'none', 'no_action');

            return response()->json([
                'message' => 'Dispute closed. No financial action required.',
                'action'  => 'none',
            ]);
        }

        // ── Action: refund → resolved_client ──────────────────────────────────
        if ($action === 'refund') {
            abort_if(
                ! $escrow->stripe_payment_intent_id,
                422,
                'No Stripe PaymentIntent on record — cannot issue refund.'
            );

            $amountCents    = (int) bcmul($escrow->availableAmount(), '100', 0);
            $idempotencyKey = "dispute-refund-{$dispute->id}";

            // Stripe call OUTSIDE the DB transaction intentionally:
            // If Stripe succeeds but DB write fails, we catch and log for manual reconciliation.
            // If Stripe fails, nothing is written to DB — clean state.
            try {
                $refund = $this->stripe->refundPaymentIntent(
                    $escrow->stripe_payment_intent_id,
                    $amountCents > 0 ? $amountCents : null,
                    $idempotencyKey,
                );
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                Log::error("Stripe refund failed for dispute {$dispute->id}", [
                    'error'              => $e->getMessage(),
                    'payment_intent_id'  => $escrow->stripe_payment_intent_id,
                ]);
                // Reset claim so admin can retry after fixing the issue
                $dispute->update(['execution_state' => 'idle']);
                return response()->json([
                    'error'   => 'Stripe refund failed. No funds moved. Check Stripe Dashboard.',
                    'detail'  => $e->getMessage(),
                ], 502);
            }

            // Capture available amount as string BEFORE the DB write changes the balance
            $refundAmount = $escrow->availableAmount();

            // DB write after Stripe confirms
            try {
                DB::transaction(function () use ($dispute, $contract, $milestone, $escrow, $refund, $admin, $refundAmount) {
                    $dispute->update([
                        'resolution_executed_at'      => now(),
                        'executed_by'                 => $admin->id,
                        'resolution_stripe_reference' => $refund->id,
                        'execution_state'             => 'complete',
                    ]);

                    $milestone->update(['status' => 'released']); // closed out, no further action

                    Transaction::create([
                        'contract_id'      => $contract->id,
                        'milestone_id'     => $milestone->id,
                        'initiated_by'     => $admin->id,
                        'type'             => 'refund',
                        'amount'           => $refundAmount,
                        'currency'         => $escrow->currency,
                        'stripe_reference' => $refund->id,
                        'status'           => 'pending', // promoted to completed via webhook
                        'notes'            => "Dispute {$dispute->id} resolved in client favour.",
                    ]);

                    $escrow->increment('refunded_amount', $refundAmount);
                });
            } catch (\Throwable $e) {
                // Stripe money moved but DB write failed — critical: needs manual reconciliation
                Log::critical('Stripe refund succeeded but DB write failed — manual reconciliation required.', [
                    'dispute_id' => $dispute->id,
                    'refund_id'  => $refund->id,
                    'error'      => $e->getMessage(),
                ]);
                return response()->json([
                    'error'     => 'Stripe refund was issued but our database could not be updated. Contact support immediately.',
                    'refund_id' => $refund->id,
                ], 500);
            }

            activity('disputes')
                ->performedOn($dispute)
                ->causedBy($admin)
                ->withProperties(['action' => 'refund', 'refund_id' => $refund->id])
                ->log('resolution_executed');

            DisputeResolutionExecuted::dispatch($dispute->fresh(), 'refund', $refund->id);

            return response()->json([
                'message'   => 'Refund issued to client.',
                'action'    => 'refund',
                'refund_id' => $refund->id,
                'amount'    => $escrow->fresh()->refunded_amount,
                'currency'  => $escrow->currency,
            ]);
        }

        // ── Action: transfer → resolved_freelancer (100% to freelancer) ──────
        if ($action === 'transfer') {
            $freelancer = $contract->freelancer;

            abort_if(
                ! $freelancer?->paymentAccount?->stripe_account_id,
                422,
                'Freelancer has not connected a Stripe account. They must complete onboarding before funds can be transferred.'
            );

            abort_if(
                ! $freelancer->paymentAccount->isActive(),
                422,
                "Freelancer's Stripe account is not active yet (payout not enabled). Check their onboarding status."
            );

            $amountCents    = (int) bcmul($escrow->availableAmount(), '100', 0);
            $idempotencyKey = "dispute-transfer-{$dispute->id}";

            abort_if($amountCents <= 0, 422, 'No available escrow balance to transfer.');

            try {
                $transfer = $this->stripe->createTransfer(
                    $amountCents,
                    $escrow->currency,
                    $freelancer->paymentAccount->stripe_account_id,
                    $milestone->id,
                    $idempotencyKey,
                );
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                Log::error("Stripe transfer failed for dispute {$dispute->id}", [
                    'error'       => $e->getMessage(),
                    'destination' => $freelancer->paymentAccount->stripe_account_id,
                ]);
                // Reset claim so admin can retry
                $dispute->update(['execution_state' => 'idle']);
                return response()->json([
                    'error'  => 'Stripe transfer failed. No funds moved. Check Stripe Dashboard.',
                    'detail' => $e->getMessage(),
                ], 502);
            }

            // Capture amount before DB write changes the balance
            $transferAmount = $escrow->availableAmount();

            try {
                DB::transaction(function () use ($dispute, $contract, $milestone, $escrow, $transfer, $admin, $transferAmount) {
                    $dispute->update([
                        'resolution_executed_at'      => now(),
                        'executed_by'                 => $admin->id,
                        'resolution_stripe_reference' => $transfer->id,
                        'execution_state'             => 'complete',
                    ]);

                    $milestone->update(['status' => 'released']);

                    Transaction::create([
                        'contract_id'        => $contract->id,
                        'milestone_id'       => $milestone->id,
                        'initiated_by'       => $admin->id,
                        'type'               => 'release',
                        'amount'             => $transferAmount,
                        'currency'           => $escrow->currency,
                        'stripe_transfer_id' => $transfer->id,
                        'status'             => 'pending', // promoted to completed via webhook
                        'notes'              => "Dispute {$dispute->id} resolved in freelancer favour.",
                    ]);

                    $escrow->increment('released_amount', $transferAmount);
                });
            } catch (\Throwable $e) {
                Log::critical('Stripe transfer succeeded but DB write failed — manual reconciliation required.', [
                    'dispute_id'  => $dispute->id,
                    'transfer_id' => $transfer->id,
                    'error'       => $e->getMessage(),
                ]);
                return response()->json([
                    'error'       => 'Stripe transfer was issued but our database could not be updated. Contact support immediately.',
                    'transfer_id' => $transfer->id,
                ], 500);
            }

            activity('disputes')
                ->performedOn($dispute)
                ->causedBy($admin)
                ->withProperties(['action' => 'transfer', 'transfer_id' => $transfer->id])
                ->log('resolution_executed');

            DisputeResolutionExecuted::dispatch($dispute->fresh(), 'transfer', $transfer->id);

            return response()->json([
                'message'     => 'Funds transferred to freelancer.',
                'action'      => 'transfer',
                'transfer_id' => $transfer->id,
                'amount'      => $escrow->fresh()->released_amount,
                'currency'    => $escrow->currency,
            ]);
        }

        // ── Action: split → resolved_split (percent to freelancer + refund to client) ──
        if ($action === 'split') {
            $freelancer = $contract->freelancer;

            abort_if(
                ! $freelancer?->paymentAccount?->stripe_account_id,
                422,
                'Freelancer has not connected a Stripe account. They must complete onboarding before funds can be transferred.'
            );
            abort_if(
                ! $freelancer->paymentAccount->isActive(),
                422,
                "Freelancer's Stripe account is not active yet. Check their onboarding status."
            );
            abort_if(
                ! $escrow->stripe_payment_intent_id,
                422,
                'No Stripe PaymentIntent on record — cannot issue client refund portion.'
            );

            $totalCents = (int) bcmul($escrow->availableAmount(), '100', 0);

            // Resumable: if partial_failure, skip the leg that already succeeded
            $transferAlreadyDone = $dispute->split_state === 'transfer_done'
                || $dispute->split_transfer_id !== null;
            $refundAlreadyDone   = $dispute->split_state === 'refund_done'
                || $dispute->split_refund_id !== null;

            // If the transfer leg already completed, the escrow available amount has already
            // been reduced by the freelancer's share. We must use the ORIGINAL total
            // (before any leg ran) to compute the correct client refund amount.
            // The original total = current available + already released/refunded from THIS split.
            if ($transferAlreadyDone) {
                // Recover original total from the split transfer transaction amount
                $transferTxn = Transaction::where('stripe_transfer_id', $dispute->split_transfer_id)->first();
                if ($transferTxn) {
                    $freelancerCents = (int) bcmul((string) $transferTxn->amount, '100', 0);
                    $clientCents     = $totalCents; // remaining available = client portion
                } else {
                    // Fallback: re-derive from held_amount if transaction not found
                    $originalTotalCents = (int) bcmul((string) $escrow->held_amount, '100', 0);
                    $split              = $dispute->splitAmounts($originalTotalCents);
                    $freelancerCents    = $split['freelancer_cents'];
                    $clientCents        = $split['client_cents'];
                }
            } else {
                abort_if($totalCents <= 0, 422, 'No available escrow balance to split.');
                ['freelancer_cents' => $freelancerCents, 'client_cents' => $clientCents]
                    = $dispute->splitAmounts($totalCents);
            }

            $transferId = $dispute->split_transfer_id;
            $refundId   = $dispute->split_refund_id;

            // ── Leg 1: Stripe transfer to freelancer ──────────────────────────
            if (! $transferAlreadyDone && $freelancerCents > 0) {
                $transferIdempotencyKey = "dispute-split-transfer-{$dispute->id}";

                try {
                    $transfer   = $this->stripe->createTransfer(
                        $freelancerCents,
                        $escrow->currency,
                        $freelancer->paymentAccount->stripe_account_id,
                        $milestone->id,
                        $transferIdempotencyKey,
                    );
                    $transferId = $transfer->id;

                    // Record transfer leg immediately so partial state is visible
                    DB::transaction(function () use ($dispute, $contract, $milestone, $escrow, $transfer, $admin, $freelancerCents) {
                        $freelancerAmount = round($freelancerCents / 100, 2);
                        $dispute->update([
                            'split_transfer_id' => $transfer->id,
                            'split_state'       => 'transfer_done',
                            'executed_by'       => $admin->id,
                        ]);
                        Transaction::create([
                            'contract_id'        => $contract->id,
                            'milestone_id'       => $milestone->id,
                            'initiated_by'       => $admin->id,
                            'type'               => 'release',
                            'amount'             => $freelancerAmount,
                            'currency'           => $escrow->currency,
                            'stripe_transfer_id' => $transfer->id,
                            'status'             => 'pending',
                            'notes'              => "Split dispute {$dispute->id}: freelancer portion ({$dispute->split_freelancer_percent}%).",
                        ]);
                        $escrow->increment('released_amount', $freelancerAmount);
                    });
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    Log::error("Stripe split-transfer failed for dispute {$dispute->id}", [
                        'error'            => $e->getMessage(),
                        'freelancer_cents' => $freelancerCents,
                    ]);
                    // Leg 1 failed cleanly — no money moved, reset claim so admin can retry
                    $dispute->update(['execution_state' => 'idle']);
                    return response()->json([
                        'error'  => 'Stripe transfer (freelancer portion) failed. No funds moved. Check Stripe Dashboard.',
                        'detail' => $e->getMessage(),
                    ], 502);
                } catch (\Throwable $e) {
                    Log::critical('Split transfer succeeded but DB write failed.', [
                        'dispute_id'  => $dispute->id,
                        'transfer_id' => $transferId,
                        'error'       => $e->getMessage(),
                    ]);
                    // Money moved but DB failed — leave as 'claiming' so it shows up as stuck
                    // Admin must reconcile manually using the transfer_id
                    return response()->json([
                        'error'       => 'Transfer issued but database not updated. Contact support.',
                        'transfer_id' => $transferId,
                    ], 500);
                }
            }

            // ── Leg 2: Stripe refund to client ────────────────────────────────
            if (! $refundAlreadyDone && $clientCents > 0) {
                $refundIdempotencyKey = "dispute-split-refund-{$dispute->id}";

                try {
                    $refund   = $this->stripe->refundPaymentIntent(
                        $escrow->stripe_payment_intent_id,
                        $clientCents,
                        $refundIdempotencyKey,
                    );
                    $refundId = $refund->id;

                    DB::transaction(function () use ($dispute, $contract, $milestone, $escrow, $refund, $admin, $clientCents) {
                        $clientAmount = round($clientCents / 100, 2);
                        $dispute->update([
                            'split_refund_id' => $refund->id,
                            'split_state'     => 'refund_done',
                        ]);
                        Transaction::create([
                            'contract_id'      => $contract->id,
                            'milestone_id'     => $milestone->id,
                            'initiated_by'     => $admin->id,
                            'type'             => 'refund',
                            'amount'           => $clientAmount,
                            'currency'         => $escrow->currency,
                            'stripe_reference' => $refund->id,
                            'status'           => 'pending',
                            'notes'            => "Split dispute {$dispute->id}: client portion (" . (100 - $dispute->split_freelancer_percent) . "%).",
                        ]);
                        $escrow->increment('refunded_amount', $clientAmount);
                    });
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Transfer already succeeded — this is a partial failure
                    Log::critical("Stripe split-refund FAILED after transfer succeeded for dispute {$dispute->id}", [
                        'error'        => $e->getMessage(),
                        'transfer_id'  => $transferId,
                        'client_cents' => $clientCents,
                    ]);
                    $dispute->update(['split_state' => 'partial_failure', 'execution_state' => 'idle']);

                    return response()->json([
                        'error'       => 'PARTIAL FAILURE: Transfer to freelancer succeeded but client refund failed. Manual action required.',
                        'transfer_id' => $transferId,
                        'detail'      => $e->getMessage(),
                        'action'      => 'Call execute-resolution again to retry the refund leg only.',
                    ], 502);
                } catch (\Throwable $e) {
                    Log::critical('Split refund succeeded but DB write failed.', [
                        'dispute_id' => $dispute->id,
                        'refund_id'  => $refundId,
                        'error'      => $e->getMessage(),
                    ]);
                    return response()->json([
                        'error'     => 'Refund issued but database not updated. Contact support.',
                        'refund_id' => $refundId,
                    ], 500);
                }
            }

            // ── Both legs done — mark complete ────────────────────────────────
            $splitReference = implode(',', array_filter([$transferId, $refundId]));

            DB::transaction(function () use ($dispute, $milestone, $admin, $splitReference) {
                $dispute->update([
                    'resolution_executed_at'      => now(),
                    'executed_by'                 => $admin->id,
                    'resolution_stripe_reference' => $splitReference,
                    'split_state'                 => 'complete',
                    'execution_state'             => 'complete',
                ]);
                $milestone->update(['status' => 'released']);
            });

            activity('disputes')
                ->performedOn($dispute)
                ->causedBy($admin)
                ->withProperties([
                    'action'             => 'split',
                    'transfer_id'        => $transferId,
                    'refund_id'          => $refundId,
                    'freelancer_percent' => $dispute->split_freelancer_percent,
                    'freelancer_cents'   => $freelancerCents,
                    'client_cents'       => $clientCents,
                ])
                ->log('resolution_executed');

            DisputeResolutionExecuted::dispatch($dispute->fresh(), 'split', $splitReference);

            $escrow->refresh();
            return response()->json([
                'message'            => 'Split resolution executed.',
                'action'             => 'split',
                'freelancer_percent' => $dispute->split_freelancer_percent,
                'transfer_id'        => $transferId,
                'refund_id'          => $refundId,
                'freelancer_amount'  => round($freelancerCents / 100, 2),
                'client_amount'      => round($clientCents / 100, 2),
                'currency'           => $escrow->currency,
            ]);
        }

        // Should never reach here — resolutionAction() is exhaustive
        return response()->json(['error' => 'Unknown resolution action.'], 500);
    }

    /**
     * POST /api/v1/disputes/{id}/reconcile-claiming
     *
     * Resolves a stuck 'claiming' execution state by querying Stripe to determine
     * whether the financial operation actually happened.
     *
     * When to use:
     *   A dispute stuck in 'claiming' means either:
     *   (a) The process crashed between writing 'claiming' and completing the Stripe call, OR
     *   (b) The Stripe call completed but the DB write of 'complete' failed
     *
     * This endpoint:
     *   1. Checks Stripe using the deterministic idempotency key
     *   2. If Stripe operation EXISTS → marks dispute as complete, persists the reference
     *   3. If Stripe operation does NOT exist → resets to 'idle' so admin can retry
     *
     * Why NOT auto-retry:
     *   We do not automatically re-execute because:
     *   (a) The Stripe call may have partially succeeded (e.g. split leg 1 done)
     *   (b) Human verification is safer than automated retry with real money
     *   (c) Admin must confirm the Stripe state before proceeding
     *
     * Security: admin-only. Idempotent — safe to call multiple times.
     */
    public function reconcileClaiming(Request $request, Dispute $dispute): JsonResponse
    {
        $admin = $request->user();

        abort_if(! $admin->isAdmin(), 403, 'Only admins can reconcile dispute execution state.');

        if (! $dispute->isClaiming()) {
            return response()->json([
                'message'         => 'Dispute is not in claiming state. No reconciliation needed.',
                'execution_state' => $dispute->execution_state,
            ]);
        }

        $action   = $dispute->resolutionAction();
        $contract = $dispute->contract;
        $escrow   = $contract->escrowBalance;

        $result = match ($action) {

            // ── Refund path ──────────────────────────────────────────────────
            'refund' => (function () use ($dispute, $contract, $escrow, $admin) {
                $idempotencyKey = "dispute-refund-{$dispute->id}";
                $refund         = $this->stripe->findRefundByIdempotencyKey($idempotencyKey);

                if ($refund) {
                    // Stripe refund exists — operation completed, just DB write was missed
                    DB::transaction(function () use ($dispute, $escrow, $refund, $admin, $contract) {
                        $refundAmount = $escrow->availableAmount();
                        $dispute->update([
                            'resolution_executed_at'      => $dispute->resolution_executed_at ?? now(),
                            'executed_by'                 => $dispute->executed_by ?? $admin->id,
                            'resolution_stripe_reference' => $refund->id,
                            'execution_state'             => 'complete',
                        ]);
                        if (! Transaction::where('stripe_reference', $refund->id)->exists()) {
                            $milestone = $dispute->milestone;
                            Transaction::create([
                                'contract_id'      => $contract->id,
                                'milestone_id'     => $milestone?->id,
                                'initiated_by'     => $admin->id,
                                'type'             => 'refund',
                                'amount'           => $refundAmount,
                                'currency'         => $escrow->currency,
                                'stripe_reference' => $refund->id,
                                'status'           => $refund->status === 'succeeded' ? 'completed' : 'pending',
                                'notes'            => "Reconciled from stuck claiming state. Dispute {$dispute->id}.",
                            ]);
                            $escrow->increment('refunded_amount', $refundAmount);
                        }
                    });

                    Log::info("Reconciled claiming dispute {$dispute->id}: refund {$refund->id} found on Stripe → marked complete.");
                    return ['outcome' => 'complete', 'stripe_reference' => $refund->id, 'action' => 'refund'];
                }

                // No Stripe operation found — safe to reset
                $dispute->update(['execution_state' => 'idle']);
                Log::info("Reconciled claiming dispute {$dispute->id}: no refund found on Stripe → reset to idle.");
                return ['outcome' => 'reset_to_idle', 'action' => 'refund'];
            })(),

            // ── Transfer path ────────────────────────────────────────────────
            'transfer' => (function () use ($dispute, $contract, $escrow, $admin) {
                $idempotencyKey = "dispute-transfer-{$dispute->id}";
                $transfer       = $this->stripe->findTransferByIdempotencyKey($idempotencyKey);

                if ($transfer) {
                    DB::transaction(function () use ($dispute, $escrow, $transfer, $admin, $contract) {
                        $transferAmount = $escrow->availableAmount();
                        $dispute->update([
                            'resolution_executed_at'      => $dispute->resolution_executed_at ?? now(),
                            'executed_by'                 => $dispute->executed_by ?? $admin->id,
                            'resolution_stripe_reference' => $transfer->id,
                            'execution_state'             => 'complete',
                        ]);
                        if (! Transaction::where('stripe_transfer_id', $transfer->id)->exists()) {
                            $milestone = $dispute->milestone;
                            Transaction::create([
                                'contract_id'        => $contract->id,
                                'milestone_id'       => $milestone?->id,
                                'initiated_by'       => $admin->id,
                                'type'               => 'release',
                                'amount'             => $transferAmount,
                                'currency'           => $escrow->currency,
                                'stripe_transfer_id' => $transfer->id,
                                'status'             => 'pending',
                                'notes'              => "Reconciled from stuck claiming state. Dispute {$dispute->id}.",
                            ]);
                            $escrow->increment('released_amount', $transferAmount);
                        }
                    });

                    Log::info("Reconciled claiming dispute {$dispute->id}: transfer {$transfer->id} found on Stripe → marked complete.");
                    return ['outcome' => 'complete', 'stripe_reference' => $transfer->id, 'action' => 'transfer'];
                }

                $dispute->update(['execution_state' => 'idle']);
                Log::info("Reconciled claiming dispute {$dispute->id}: no transfer found on Stripe → reset to idle.");
                return ['outcome' => 'reset_to_idle', 'action' => 'transfer'];
            })(),

            // ── Split path ───────────────────────────────────────────────────
            'split' => (function () use ($dispute, $contract, $escrow, $admin) {
                $transferIdempotencyKey = "dispute-split-transfer-{$dispute->id}";
                $refundIdempotencyKey   = "dispute-split-refund-{$dispute->id}";

                $transfer = $this->stripe->findTransferByIdempotencyKey($transferIdempotencyKey);
                $refund   = $this->stripe->findRefundByIdempotencyKey($refundIdempotencyKey);

                // Neither leg happened — safe reset
                if (! $transfer && ! $refund) {
                    $dispute->update(['execution_state' => 'idle', 'split_state' => 'pending']);
                    Log::info("Reconciled claiming split dispute {$dispute->id}: no Stripe operations found → reset to idle.");
                    return ['outcome' => 'reset_to_idle', 'action' => 'split'];
                }

                // Both legs found — mark complete
                if ($transfer && $refund) {
                    DB::transaction(function () use ($dispute, $contract, $escrow, $transfer, $refund, $admin) {
                        $dispute->update([
                            'resolution_executed_at'      => $dispute->resolution_executed_at ?? now(),
                            'executed_by'                 => $dispute->executed_by ?? $admin->id,
                            'resolution_stripe_reference' => $transfer->id . ',' . $refund->id,
                            'split_transfer_id'           => $transfer->id,
                            'split_refund_id'             => $refund->id,
                            'split_state'                 => 'complete',
                            'execution_state'             => 'complete',
                        ]);
                        $milestone = $dispute->milestone;
                        if (! Transaction::where('stripe_transfer_id', $transfer->id)->exists()) {
                            Transaction::create([
                                'contract_id'        => $contract->id,
                                'milestone_id'       => $milestone?->id,
                                'initiated_by'       => $admin->id,
                                'type'               => 'release',
                                'amount'             => bcdiv((string) $transfer->amount, '100', 2),
                                'currency'           => $escrow->currency,
                                'stripe_transfer_id' => $transfer->id,
                                'status'             => 'pending',
                                'notes'              => "Reconciled split transfer. Dispute {$dispute->id}.",
                            ]);
                            $escrow->increment('released_amount', bcdiv((string) $transfer->amount, '100', 2));
                        }
                        if (! Transaction::where('stripe_reference', $refund->id)->exists()) {
                            Transaction::create([
                                'contract_id'      => $contract->id,
                                'milestone_id'     => $milestone?->id,
                                'initiated_by'     => $admin->id,
                                'type'             => 'refund',
                                'amount'           => bcdiv((string) $refund->amount, '100', 2),
                                'currency'         => $escrow->currency,
                                'stripe_reference' => $refund->id,
                                'status'           => 'pending',
                                'notes'            => "Reconciled split refund. Dispute {$dispute->id}.",
                            ]);
                            $escrow->increment('refunded_amount', bcdiv((string) $refund->amount, '100', 2));
                        }
                    });
                    Log::info("Reconciled claiming split dispute {$dispute->id}: both legs found → complete.");
                    return ['outcome' => 'complete', 'action' => 'split', 'transfer_id' => $transfer->id, 'refund_id' => $refund->id];
                }

                // Transfer done, refund missing — reset to idle so execute-resolution retries refund leg
                if ($transfer && ! $refund) {
                    $milestone = $dispute->milestone;
                    if (! Transaction::where('stripe_transfer_id', $transfer->id)->exists()) {
                        Transaction::create([
                            'contract_id'        => $contract->id,
                            'milestone_id'       => $milestone?->id,
                            'initiated_by'       => $admin->id,
                            'type'               => 'release',
                            'amount'             => bcdiv((string) $transfer->amount, '100', 2),
                            'currency'           => $escrow->currency,
                            'stripe_transfer_id' => $transfer->id,
                            'status'             => 'pending',
                            'notes'              => "Reconciled split transfer (refund pending). Dispute {$dispute->id}.",
                        ]);
                        $escrow->increment('released_amount', bcdiv((string) $transfer->amount, '100', 2));
                    }
                    $dispute->update([
                        'split_transfer_id' => $transfer->id,
                        'split_state'       => 'transfer_done',
                        'execution_state'   => 'idle',
                    ]);
                    Log::warning("Reconciled claiming split dispute {$dispute->id}: transfer found, no refund → reset to idle for refund retry.");
                    return ['outcome' => 'partial_transfer_reconciled', 'action' => 'split', 'transfer_id' => $transfer->id, 'next' => 'Call execute-resolution to complete the refund leg.'];
                }

                // Refund found but no transfer — critical anomaly, do not auto-resolve
                Log::critical("Reconciled claiming split dispute {$dispute->id}: refund found but NO transfer — manual review required.", ['refund_id' => $refund->id]);
                return ['outcome' => 'manual_review_required', 'action' => 'split', 'refund_id' => $refund->id, 'reason' => 'Client refunded but freelancer transfer missing.'];
            })(),

            // ── None / closed ────────────────────────────────────────────────
            default => (function () use ($dispute, $admin) {
                $dispute->update(['execution_state' => 'complete', 'resolution_executed_at' => now(), 'executed_by' => $admin->id]);
                return ['outcome' => 'complete', 'action' => 'none'];
            })(),
        };

        activity('disputes')
            ->performedOn($dispute->fresh())
            ->causedBy($admin)
            ->withProperties($result)
            ->log('reconcile_claiming');

        return response()->json([
            'message' => 'Reconciliation complete.',
            'dispute' => $dispute->fresh(),
            ...$result,
        ]);
    }

    /**
     * GET /api/v1/disputes/{id}
     * Full dispute thread with evidence.
     */
    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $user = $request->user();

        abort_if(
            ! in_array($user->id, [$dispute->contract->client_id, $dispute->contract->freelancer_id]) && ! $user->isAdmin(),
            403,
            'Access denied.'
        );

        $dispute->load([
            'contract:id,title,status',
            'milestone:id,title,amount',
            'raisedBy:id,name,email',
            'mediator:id,name,email',
            'evidence.user:id,name,email',
            'latestAiSummary',
        ]);

        return response()->json($dispute);
    }
}
