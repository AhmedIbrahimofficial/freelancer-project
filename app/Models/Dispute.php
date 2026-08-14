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
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
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
}
