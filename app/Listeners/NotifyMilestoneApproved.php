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

        // Safety boundary: never auto-release if any dispute exists on this milestone,
        // even if resolved. Disputed milestones must be released explicitly by an admin
        // via execute-resolution. This mirrors the guard in PaymentController::release().
        if ($milestone->disputes()->exists()) {
            Log::warning("Milestone {$milestone->id} has a dispute record — skipping auto-release. Admin must execute resolution.");
            return;
        }

        $amountCents    = (int) round((float) $milestone->amount * 100);
        $idempotencyKey = "auto-release-{$milestone->id}";

        // Stripe call is OUTSIDE the DB transaction intentionally.
        // Pattern: Stripe first (idempotent key protects against duplicates on queue retry),
        // then persist to DB. If DB write fails after Stripe succeeds, the queue will retry
        // with the same idempotency key — Stripe returns the same transfer, DB write succeeds.
        // If Stripe fails, nothing is written to DB — clean state, queue retries correctly.
        try {
            $transfer = $this->stripe->createTransfer(
                $amountCents,
                $contract->currency,
                $paymentAccount->stripe_account_id,
                $milestone->id,
                $idempotencyKey,
            );
        } catch (\Throwable $e) {
            Log::error("Auto-release Stripe transfer failed for milestone {$milestone->id}: {$e->getMessage()}");
            // Re-throw so the queue retries this job.
            throw $e;
        }

        try {
            DB::transaction(function () use ($milestone, $contract, $transfer) {
                $milestone->update(['status' => 'released']);

                Transaction::create([
                    'contract_id'        => $contract->id,
                    'milestone_id'       => $milestone->id,
                    'initiated_by'       => null,
                    'type'               => 'release',
                    'amount'             => $milestone->amount,
                    'currency'           => $contract->currency,
                    'stripe_transfer_id' => $transfer->id,
                    'status'             => 'pending', // promoted to completed via webhook
                    'notes'              => 'Auto-released on milestone approval.',
                ]);

                $escrow = $contract->escrowBalance;
                if ($escrow) {
                    $escrow->increment('released_amount', $milestone->amount);
                }
            });
        } catch (\Throwable $e) {
            // Stripe money moved but DB write failed.
            // The queue will retry — the same idempotency key means Stripe returns the
            // same transfer object, so Stripe will NOT charge twice.
            // The DB write will succeed on retry.
            Log::critical('Auto-release: Stripe transfer succeeded but DB write failed — will retry.', [
                'milestone_id' => $milestone->id,
                'transfer_id'  => $transfer->id,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info("Auto-released {$milestone->amount} {$contract->currency} for milestone {$milestone->id}.");
    }
}
