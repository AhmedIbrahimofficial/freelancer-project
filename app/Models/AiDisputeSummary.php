<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDisputeSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'dispute_id',
        'type',
        'summary_text',
        'suggested_resolution',
        'model_version',
        'input_tokens',
        'output_tokens',
        'status',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
