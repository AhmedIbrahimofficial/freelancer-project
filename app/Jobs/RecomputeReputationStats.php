<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\ReputationStat;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeReputationStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(): void
    {
        $user = User::findOrFail($this->userId);

        $contracts = Contract::forUser($user->id)
            ->whereIn('status', ['completed', 'cancelled', 'disputed'])
            ->get();

        $completed  = $contracts->where('status', 'completed')->count();
        $disputed   = $contracts->where('status', 'disputed')->count();
        $cancelled  = $contracts->where('status', 'cancelled')->count();

        // On-time rate: milestones approved before or on due_date / total milestones with a due_date
        $milestonesWithDueDate = $user->isFreelancer()
            ? \App\Models\Milestone::whereIn('contract_id', $contracts->pluck('id'))
                ->whereNotNull('due_date')
                ->whereIn('status', ['approved', 'released'])
                ->get()
            : collect();

        $onTimeCount = $milestonesWithDueDate->filter(
            fn ($m) => $m->approved_at && $m->approved_at->lte($m->due_date->endOfDay())
        )->count();

        $onTimeRate = $milestonesWithDueDate->count() > 0
            ? round(($onTimeCount / $milestonesWithDueDate->count()) * 100, 2)
            : 0.00;

        $totalEarned = $user->isFreelancer()
            ? \App\Models\Transaction::whereHas('contract', fn ($q) => $q->where('freelancer_id', $user->id))
                ->where('type', 'release')->where('status', 'completed')->sum('amount')
            : 0;

        $totalSpent = $user->isClient()
            ? \App\Models\Transaction::whereHas('contract', fn ($q) => $q->where('client_id', $user->id))
                ->where('type', 'deposit')->where('status', 'completed')->sum('amount')
            : 0;

        ReputationStat::updateOrCreate(
            ['user_id' => $user->id],
            [
                'completed_count'  => $completed,
                'disputed_count'   => $disputed,
                'cancelled_count'  => $cancelled,
                'on_time_rate'     => $onTimeRate,
                'total_earned'     => $totalEarned,
                'total_spent'      => $totalSpent,
                'last_computed_at' => now(),
            ]
        );
    }
}
