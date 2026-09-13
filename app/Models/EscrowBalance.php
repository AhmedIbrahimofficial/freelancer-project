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

    /**
     * Returns the available (unreleased, unrefunded) amount as a string
     * using BCMath for exact decimal arithmetic.
     *
     * Never use PHP float arithmetic on financial values — floats cannot
     * exactly represent most decimal fractions, which causes rounding drift
     * (e.g. 100.00 - 70.00 - 30.00 can produce 4.547e-13 instead of 0.00).
     *
     * Callers that need cents: (int) bcmul($escrow->availableAmount(), '100', 0)
     */
    public function availableAmount(): string
    {
        $held     = (string) $this->held_amount;
        $released = (string) $this->released_amount;
        $refunded = (string) $this->refunded_amount;

        $available = bcsub(bcsub($held, $released, 2), $refunded, 2);

        // Never return a negative balance — defensive against data inconsistency
        return bccomp($available, '0', 2) < 0 ? '0.00' : $available;
    }
}
