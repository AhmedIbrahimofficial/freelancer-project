<?php

namespace App\Events;

use App\Models\Dispute;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after the financial action (Stripe transfer OR refund) for a resolved
 * dispute has been successfully executed. This is separate from DisputeResolved,
 * which fires when the admin sets the decision. Execution and decision are two
 * distinct steps.
 */
class DisputeResolutionExecuted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Dispute $dispute,
        public readonly string  $action,          // 'transfer' | 'refund' | 'none'
        public readonly string  $stripeReference, // transfer_id or refund_id
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("contract.{$this->dispute->contract_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DisputeResolutionExecuted';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id'       => $this->dispute->contract_id,
            'dispute_id'        => $this->dispute->id,
            'resolution_status' => $this->dispute->status,
            'action'            => $this->action,
            'stripe_reference'  => $this->stripeReference,
            'executed_at'       => $this->dispute->resolution_executed_at?->toISOString(),
        ];
    }
}
