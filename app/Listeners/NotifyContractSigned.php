<?php

namespace App\Listeners;

use App\Events\ContractSigned;
use App\Mail\ContractFullySigned;
use App\Mail\ContractSignatureRequested;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class NotifyContractSigned implements ShouldQueue
{
    public function handle(ContractSigned $event): void
    {
        $contract = $event->contract;
        $signedBy = $event->signedBy;

        if ($contract->isFullySigned()) {
            // Both parties have signed — notify both
            $client     = User::find($contract->client_id);
            $freelancer = User::find($contract->freelancer_id);

            if ($client) {
                Mail::to($client->email)->queue(new ContractFullySigned($contract, $client));
            }
            if ($freelancer) {
                Mail::to($freelancer->email)->queue(new ContractFullySigned($contract, $freelancer));
            }
        } else {
            // Only one signature so far — notify the party who hasn't signed yet
            $otherPartyId = $signedBy->id === $contract->client_id
                ? $contract->freelancer_id
                : $contract->client_id;

            $otherParty = User::find($otherPartyId);

            if ($otherParty) {
                Mail::to($otherParty->email)->queue(
                    new ContractSignatureRequested($contract->load('milestones'), $otherParty, $signedBy)
                );
            }
        }
    }
}
