<?php

namespace App\Listeners;

use App\Events\DisputeResolved;
use App\Mail\DisputeResolvedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class NotifyDisputeResolved implements ShouldQueue
{
    public function handle(DisputeResolved $event): void
    {
        $dispute  = $event->dispute->load(['contract', 'milestone', 'mediator']);
        $contract = $dispute->contract;

        $client     = User::find($contract->client_id);
        $freelancer = User::find($contract->freelancer_id);

        if ($client) {
            Mail::to($client->email)->queue(new DisputeResolvedMail($dispute, $client));
        }
        if ($freelancer) {
            Mail::to($freelancer->email)->queue(new DisputeResolvedMail($dispute, $freelancer));
        }
    }
}
