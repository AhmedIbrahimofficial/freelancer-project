<?php

namespace App\Events;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractSigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly User $signedBy,
    ) {}

    /**
     * Broadcast on a private channel scoped to the contract.
     * Only the two contract parties are authorised to listen (see routes/channels.php).
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("contract.{$this->contract->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ContractSigned';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id'   => $this->contract->id,
            'status'        => $this->contract->status,
            'signed_by_id'  => $this->signedBy->id,
            'signed_by_name' => $this->signedBy->name,
            'fully_signed'  => $this->contract->isFullySigned(),
        ];
    }
}
