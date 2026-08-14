<?php

use App\Models\Contract;
use Illuminate\Support\Facades\Broadcast;

/**
 * Private channel authorisation.
 *
 * Channel: private-contract.{contractId}
 * Authorised: the client, the freelancer, or any admin on that contract.
 *
 * Laravel Echo subscribes to `private-contract.{id}` which maps here.
 */
Broadcast::channel('contract.{contractId}', function ($user, string $contractId): bool {
    $contract = Contract::find($contractId);

    if (! $contract) {
        return false;
    }

    return $user->isAdmin()
        || (int) $user->id === (int) $contract->client_id
        || (int) $user->id === (int) $contract->freelancer_id;
});
