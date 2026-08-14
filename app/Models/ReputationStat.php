<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReputationStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'completed_count',
        'disputed_count',
        'cancelled_count',
        'on_time_rate',
        'avg_rating',
        'total_ratings',
        'total_earned',
        'total_spent',
        'last_computed_at',
    ];

    protected function casts(): array
    {
        return [
            'on_time_rate'     => 'decimal:2',
            'avg_rating'       => 'decimal:2',
            'total_earned'     => 'decimal:2',
            'total_spent'      => 'decimal:2',
            'last_computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
