<?php

namespace App\Listeners;

use App\Events\DisputeResolutionExecuted;
use App\Mail\DisputeResolutionExecutedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class NotifyDisputeResolutionExecuted implements ShouldQueue
{
    public function handle(DisputeResolutionExecuted $event): void
    {
        $dispute  = $event->dispute->load(['contract', 'milestone']);
        $contract = $dispute->contract;

        $client     = User::find($contract->client_id);
        $freelancer = User::find($contract->freelancer_id);

        foreach (array_filter([$client, $freelancer]) as $recipient) {
            Mail::to($recipient->email)->queue(new DisputeResolutionExecutedMail(
                dispute:         $dispute,
                recipient:       $recipient,
                action:          $event->action,
                stripeReference: $event->stripeReference,
            ));
        }
    }
}
