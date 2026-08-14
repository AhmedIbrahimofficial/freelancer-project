<?php

namespace App\Events;

use App\Models\Dispute;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DisputeResolved implements ShouldBroadcast
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
        return 'DisputeResolved';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id'      => $this->dispute->contract_id,
            'dispute_id'       => $this->dispute->id,
            'status'           => $this->dispute->status,
            'resolution_notes' => $this->dispute->resolution_notes,
            'resolved_at'      => $this->dispute->resolved_at?->toISOString(),
        ];
    }
}
