<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscrowBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'held_amount',
        'released_amount',
        'refunded_amount',
        'currency',
        'status',
        'stripe_payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'held_amount'     => 'decimal:2',
            'released_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function availableAmount(): float
    {
        return (float) $this->held_amount - (float) $this->released_amount - (float) $this->refunded_amount;
    }
}
