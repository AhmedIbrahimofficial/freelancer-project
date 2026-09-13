<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Dispute extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'contract_id',
        'milestone_id',
        'raised_by',
        'assigned_mediator_id',
        'status',
        'reason',
        'resolution_notes',
        'resolved_at',
        'resolution_executed_at',
        'executed_by',
        'resolution_stripe_reference',
        'split_freelancer_percent',
        'split_transfer_id',
        'split_refund_id',
        'split_state',
        'execution_state',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at'              => 'datetime',
            'resolution_executed_at'   => 'datetime',
            'split_freelancer_percent' => 'decimal:2',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function mediator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_mediator_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class)->orderBy('created_at');
    }

    public function aiSummaries(): HasMany
    {
        return $this->hasMany(AiDisputeSummary::class);
    }

    public function latestAiSummary(): HasOne
    {
        return $this->hasOne(AiDisputeSummary::class)->where('type', 'summary')->latestOfMany();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved_client', 'resolved_freelancer', 'resolved_split', 'closed']);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'under_review', 'awaiting_evidence']);
    }

    /**
     * Whether the financial action (Stripe transfer/refund) has already been executed.
     * Once true, executeResolution must be idempotent — no second Stripe call.
     */
    public function isExecuted(): bool
    {
        return $this->resolution_executed_at !== null
            || $this->execution_state === 'complete';
    }

    /**
     * Whether another process has atomically claimed execution.
     * A 'claiming' state means Stripe is being called right now by another process.
     * Any concurrent request must treat this as "already in progress" and return early.
     */
    public function isClaiming(): bool
    {
        return $this->execution_state === 'claiming';
    }

    /**
     * Returns the Stripe action type implied by this resolution status.
     *   resolved_freelancer → 'transfer'   (100% to freelancer)
     *   resolved_split      → 'split'      (percent to freelancer + remainder refunded to client)
     *   resolved_client     → 'refund'     (100% back to client)
     *   closed              → 'none'
     */
    public function resolutionAction(): string
    {
        return match ($this->status) {
            'resolved_freelancer' => 'transfer',
            'resolved_split'      => 'split',
            'resolved_client'     => 'refund',
            default               => 'none',
        };
    }

    /**
     * For a split resolution, compute how many cents go to the freelancer
     * and how many go back to the client, given the total available escrow in cents.
     *
     * Rules:
     *  - freelancer_percent stored as DECIMAL(5,2), e.g. 70.00 = 70%
     *  - freelancer gets floor() of their share (never overpaid by rounding)
     *  - client gets the remainder (total - freelancer_cents), ensuring no cent is lost
     *  - minimum freelancer share: 1 cent (if percent > 0)
     *  - if percent = 0 → full refund to client (no transfer)
     *  - if percent = 100 → full transfer to freelancer (no refund)
     *
     * Returns ['freelancer_cents' => int, 'client_cents' => int]
     */
    public function splitAmounts(int $totalAvailableCents): array
    {
        $percent = (float) ($this->split_freelancer_percent ?? 0);
        $percent = max(0.0, min(100.0, $percent)); // clamp to [0, 100]

        // Freelancer receives floor() of their percentage share.
        // Client receives the remainder (total - freelancer_cents).
        // This guarantees: freelancer_cents + client_cents === totalAvailableCents always.
        //
        // Rounding rule: floor() on freelancer share means any fractional cent goes to client.
        // Example: 1% of 1 cent = floor(0.01) = 0 → freelancer=0, client=1
        // Example: 70% of 10000 = floor(7000.0) = 7000 → freelancer=7000, client=3000
        //
        // NOTE: If percent > 0 but totalAvailableCents is so small that floor gives 0,
        // the freelancer receives 0 cents. This is mathematically correct — the admin should
        // choose resolved_freelancer or a higher percentage for micro-amounts.
        // There is NO minimum-cent override; that would violate the stated percentage.
        $freelancerCents = (int) floor($totalAvailableCents * $percent / 100);
        $clientCents     = $totalAvailableCents - $freelancerCents;

        return [
            'freelancer_cents' => $freelancerCents,
            'client_cents'     => $clientCents,
        ];
    }

    /**
     * Whether the split is in a partial failure state
     * (transfer succeeded but refund failed, or vice versa).
     */
    public function isSplitPartialFailure(): bool
    {
        return $this->split_state === 'partial_failure';
    }
}
