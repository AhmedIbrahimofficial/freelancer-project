<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Milestone extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'contract_id',
        'title',
        'description',
        'amount',
        'due_date',
        'order',
        'status',
        'submitted_at',
        'approved_at',
        'submission_notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'due_date'     => 'date',
            'submitted_at' => 'datetime',
            'approved_at'  => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function activeDispute(): HasOne
    {
        return $this->hasOne(Dispute::class)->whereNotIn('status', ['resolved_client', 'resolved_freelancer', 'resolved_split', 'closed']);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && ! in_array($this->status, ['approved', 'released']);
    }

    public function canBeSubmitted(): bool
    {
        return in_array($this->status, ['pending', 'in_progress']);
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'submitted';
    }
}
