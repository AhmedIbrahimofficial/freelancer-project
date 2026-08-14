<?php

namespace App\Providers;

use App\Events\ContractSigned;
use App\Events\DisputeRaised;
use App\Events\DisputeResolved;
use App\Events\MilestoneApproved;
use App\Events\MilestoneSubmitted;
use App\Listeners\NotifyContractSigned;
use App\Listeners\NotifyDisputeRaised;
use App\Listeners\NotifyDisputeResolved;
use App\Listeners\NotifyMilestoneApproved;
use App\Listeners\NotifyMilestoneSubmitted;
use App\Listeners\TriggerReputationRecompute;
use App\Services\StripeService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind StripeService as a singleton so it initialises the SDK once
        $this->app->singleton(StripeService::class);
    }

    public function boot(): void
    {
        // ── Event → Listener bindings ────────────────────────────────
        Event::listen(ContractSigned::class,    NotifyContractSigned::class);
        Event::listen(MilestoneSubmitted::class, NotifyMilestoneSubmitted::class);
        Event::listen(MilestoneApproved::class,  NotifyMilestoneApproved::class);
        Event::listen(DisputeRaised::class,      NotifyDisputeRaised::class);
        Event::listen(DisputeResolved::class,    NotifyDisputeResolved::class);
        Event::listen(DisputeResolved::class,    TriggerReputationRecompute::class);
    }
}
