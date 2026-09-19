<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAccount;
use App\Models\NetworkDiscoveryAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdoptDiscoveredNetworkResource
{
    public function __construct(private readonly ManagedTargetIdentityService $targetIdentity) {}

    public function handle(DiscoveredNetworkResource $resource, CustomerConnection $connection, User $user): DiscoveredNetworkResource
    {
        abort_unless($resource->tenant_id === $user->tenant_id && $connection->tenant_id === $user->tenant_id && $resource->router_id === $connection->router_id, 403);
        abort_unless($resource->resource_type === 'pppoe_account', 422);

        return DB::transaction(function () use ($resource, $connection, $user) {
            $resource = DiscoveredNetworkResource::query()->lockForUpdate()->findOrFail($resource->id);
            $connection = CustomerConnection::query()->lockForUpdate()->with('networkAccount')->findOrFail($connection->id);
            abort_unless($resource->tenant_id === $user->tenant_id && $connection->tenant_id === $user->tenant_id, 403);

            if ($resource->management_state === 'ADOPTED') {
                abort_unless($resource->customer_connection_id === $connection->id, 422, 'Resource is already adopted by another connection.');

                return $resource->fresh();
            }
            abort_unless($resource->management_state === 'DISCOVERED', 422);
            abort_unless($resource->last_seen_at && $resource->last_seen_at->gte(now()->subSeconds((int) config('network.discovery_freshness_seconds', 86400))), 409, 'Discovery evidence is stale. Refresh discovery before adopting.');

            $data = $resource->normalized_data;
            $username = trim((string) ($data['username'] ?? $resource->name));
            abort_unless($username !== '', 422, 'Discovered resource has no adoptable username.');
            $account = NetworkAccount::query()->where('tenant_id', $resource->tenant_id)->where('router_id', $resource->router_id)->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->lockForUpdate()->first();
            if (! $account) {
                $account = NetworkAccount::create(['tenant_id' => $resource->tenant_id, 'router_id' => $resource->router_id, 'username' => $username, 'profile' => $data['profile'] ?? 'unknown', 'status' => ($data['enabled'] ?? true) ? 'active' : 'disabled', 'encrypted_secret' => null, 'metadata' => ['adopted_from_discovery' => true]]);
            }
            abort_unless($account->tenant_id === $connection->tenant_id && $account->router_id === $connection->router_id, 403);
            $account->update(['metadata' => array_merge($account->metadata ?? [], ['adopted_from_discovery' => true])]);
            if ($connection->network_account_id && $connection->network_account_id !== $account->id) {
                abort(422, 'Connection already has an incompatible NetworkAccount.');
            }
            $conflict = CustomerConnection::query()->where('tenant_id', $connection->tenant_id)->where('network_account_id', $account->id)->whereKeyNot($connection->id)->lockForUpdate()->exists();
            abort_unless(! $conflict, 422, 'NetworkAccount is already attached to another connection.');
            $connection->update(['network_account_id' => $account->id]);
            $resource->update(['management_state' => 'ADOPTED', 'customer_connection_id' => $connection->id, 'network_account_id' => $account->id]);
            // Phase 6i Task 2.8: the adopted row is the only evidence the controlled
            // lifecycle may address this target by, so its RouterOS identity context
            // is projected here (references and digests only) in the same transaction.
            $this->targetIdentity->recordAdoptionContext($account, $resource);
            NetworkDiscoveryAudit::create(['tenant_id' => $resource->tenant_id, 'router_id' => $resource->router_id, 'discovered_network_resource_id' => $resource->id, 'initiated_by_user_id' => $user->id, 'action' => 'ADOPTION', 'details' => ['previous_state' => 'DISCOVERED', 'new_state' => 'ADOPTED', 'customer_connection_id' => $connection->id, 'network_account_id' => $account->id, 'username' => $account->username, 'snapshot_id' => $resource->discovery_snapshot_id], 'occurred_at' => now()]);

            return $resource->fresh();
        });
    }

    public function unadopt(DiscoveredNetworkResource $resource, User $user, ?string $reason = null): DiscoveredNetworkResource
    {
        abort_unless($resource->tenant_id === $user->tenant_id, 403);

        return DB::transaction(function () use ($resource, $user, $reason) {
            $resource = DiscoveredNetworkResource::query()->lockForUpdate()->findOrFail($resource->id);
            abort_unless($resource->management_state === 'ADOPTED', 422);
            $connection = $resource->customer_connection_id ? CustomerConnection::query()->lockForUpdate()->find($resource->customer_connection_id) : null;
            $accountId = $resource->network_account_id;
            if ($connection && $connection->network_account_id === $accountId) {
                $connection->update(['network_account_id' => null]);
            }
            $resource->update(['management_state' => 'DISCOVERED', 'customer_connection_id' => null, 'network_account_id' => null]);
            // Phase 6i Task 2.8: an unadopted target must stop looking addressable.
            if ($accountId) {
                $account = NetworkAccount::query()->find($accountId);

                if ($account && (int) $account->router_identity_resource_id === (int) $resource->id) {
                    $this->targetIdentity->clearIdentityContext($account);
                }
            }
            NetworkDiscoveryAudit::create(['tenant_id' => $resource->tenant_id, 'router_id' => $resource->router_id, 'discovered_network_resource_id' => $resource->id, 'initiated_by_user_id' => $user->id, 'action' => 'UNADOPTION', 'details' => ['previous_state' => 'ADOPTED', 'new_state' => 'DISCOVERED', 'customer_connection_id' => $connection?->id, 'network_account_id' => $accountId, 'reason' => $reason], 'occurred_at' => now()]);

            return $resource->fresh();
        });
    }
}
