<?php

namespace App\Listeners;

use App\Events\MilestoneSubmitted;
use App\Mail\MilestoneSubmittedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class NotifyMilestoneSubmitted implements ShouldQueue
{
    public function handle(MilestoneSubmitted $event): void
    {
        $milestone = $event->milestone->load(['contract.client', 'contract.freelancer']);
        $client    = User::find($milestone->contract->client_id);

        if ($client) {
            Mail::to($client->email)->queue(new MilestoneSubmittedMail($milestone, $client));
        }
    }
}
