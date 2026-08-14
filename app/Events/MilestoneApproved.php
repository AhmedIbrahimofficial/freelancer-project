<?php

namespace App\Events;

use App\Models\Milestone;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MilestoneApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Milestone $milestone,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("contract.{$this->milestone->contract_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MilestoneApproved';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id'   => $this->milestone->contract_id,
            'milestone_id'  => $this->milestone->id,
            'milestone_title' => $this->milestone->title,
            'status'        => $this->milestone->status,
            'approved_at'   => $this->milestone->approved_at?->toISOString(),
        ];
    }
}
