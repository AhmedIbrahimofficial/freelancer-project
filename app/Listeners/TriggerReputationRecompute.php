<?php

namespace App\Listeners;

use App\Events\DisputeResolved;
use App\Jobs\RecomputeReputationStats;
use Illuminate\Contracts\Queue\ShouldQueue;

class TriggerReputationRecompute implements ShouldQueue
{
    public function handle(DisputeResolved $event): void
    {
        $dispute  = $event->dispute;
        $contract = $dispute->contract;

        // Recompute for both parties
        RecomputeReputationStats::dispatch($contract->client_id);
        RecomputeReputationStats::dispatch($contract->freelancer_id);
    }
}
