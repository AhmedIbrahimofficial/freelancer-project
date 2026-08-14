<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'stripe_account_id',
        'account_type',
        'status',
        'payout_enabled',
        'charges_enabled',
        'default_currency',
        'capabilities',
    ];

    protected function casts(): array
    {
        return [
            'payout_enabled'  => 'boolean',
            'charges_enabled' => 'boolean',
            'capabilities'    => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->payout_enabled;
    }
}
