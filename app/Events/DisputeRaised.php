<?php

namespace App\Events;

use App\Models\Dispute;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DisputeRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Dispute $dispute,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("contract.{$this->dispute->contract_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DisputeRaised';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id'  => $this->dispute->contract_id,
            'dispute_id'   => $this->dispute->id,
            'milestone_id' => $this->dispute->milestone_id,
            'status'       => $this->dispute->status,
            'reason'       => $this->dispute->reason,
            'raised_by_id' => $this->dispute->raised_by,
        ];
    }
}
