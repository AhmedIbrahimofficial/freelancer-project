<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'client_id',
        'freelancer_id',
        'title',
        'scope',
        'status',
        'total_amount',
        'currency',
        'start_date',
        'end_date',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'start_date'   => 'date',
            'end_date'     => 'date',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('order');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function escrowBalance(): HasOne
    {
        return $this->hasOne(EscrowBalance::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    public function isSignedBy(User $user): bool
    {
        return $this->signatures()->where('user_id', $user->id)->exists();
    }

    public function isFullySigned(): bool
    {
        return $this->signatures()->whereIn('user_id', [$this->client_id, $this->freelancer_id])->count() === 2;
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('client_id', $userId)->orWhere('freelancer_id', $userId);
    }
}
