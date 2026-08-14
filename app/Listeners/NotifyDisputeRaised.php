<?php

namespace App\Listeners;

use App\Events\DisputeRaised;
use App\Mail\DisputeRaisedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class NotifyDisputeRaised implements ShouldQueue
{
    public function handle(DisputeRaised $event): void
    {
        $dispute  = $event->dispute->load(['contract', 'milestone', 'raisedBy']);
        $contract = $dispute->contract;

        // Notify the party who did NOT raise the dispute
        $otherPartyId = $dispute->raised_by === $contract->client_id
            ? $contract->freelancer_id
            : $contract->client_id;

        $otherParty = User::find($otherPartyId);

        if ($otherParty) {
            Mail::to($otherParty->email)->queue(new DisputeRaisedMail($dispute, $otherParty));
        }
    }
}
