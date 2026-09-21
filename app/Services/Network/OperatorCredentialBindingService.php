<?php

namespace App\Services\Network;

use App\Models\NetworkAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Binds an existing Agent OPERATOR credential reference to a managed network
 * account. Reference metadata only: no credential secret is ever sent to or
 * stored by Laravel - the secret stays inside the Agent's local vault. The
 * binding requires an OPERATOR reference that shares the exact
 * tenant, router, Agent, and installation scope the router's OBSERVER
 * reference was synced with, so one mutation job can never mix credentials
 * across Agent installations. Reference metadata has no credential status;
 * vault availability and revocation must be checked by the Agent at execution.
 */
class OperatorCredentialBindingService
{
    public function bind(NetworkAccount $account, User $actor, array $reference): array
    {
        return DB::transaction(function () use ($account, $actor, $reference): array {
            $account = NetworkAccount::query()->lockForUpdate()->findOrFail($account->id);
            $router = $account->router()->firstOrFail();
            if ($actor->tenant_id !== $account->tenant_id || ! $actor->isNetworkOperator()) {
                throw new InvalidArgumentException('Operator credential binding requires a same-tenant network operator.');
            }
            if ($account->management_state !== 'MANAGED') {
                throw new InvalidArgumentException('Operator credentials can only be bound to MANAGED accounts.');
            }

            $operator = CredentialReference::fromArray($reference);
            if ($operator->purpose !== CredentialPurpose::OPERATOR) {
                throw new InvalidArgumentException('Only an OPERATOR credential reference can be bound as the operator credential.');
            }
            $operator->assertScope((string) $account->tenant_id, (string) $account->router_id, (string) $router->observer_agent_ref, (string) $router->observer_installation_id);

            $metadata = is_array($account->metadata) ? $account->metadata : [];
            $metadata['operator_credential'] = $operator->toArray();
            $account->update(['metadata' => $metadata]);

            return $operator->toArray();
        });
    }
}
