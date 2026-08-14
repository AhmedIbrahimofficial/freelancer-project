<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'contract_id',
        'milestone_id',
        'initiated_by',
        'type',
        'amount',
        'currency',
        'stripe_reference',
        'stripe_transfer_id',
        'status',
        'notes',
        'stripe_metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'stripe_metadata'  => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
