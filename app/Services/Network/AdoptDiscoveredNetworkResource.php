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
    public function handle(DiscoveredNetworkResource $resource, CustomerConnection $connection, User $user): DiscoveredNetworkResource
    {
        abort_unless($resource->tenant_id === $user->tenant_id && $connection->tenant_id === $user->tenant_id && $resource->router_id === $connection->router_id, 403);
        abort_unless($resource->resource_type === 'pppoe_account' && $resource->management_state === 'DISCOVERED', 422);

        return DB::transaction(function () use ($resource, $connection, $user) {
            $data = $resource->normalized_data;
            $account = NetworkAccount::firstOrCreate(['tenant_id' => $resource->tenant_id, 'router_id' => $resource->router_id, 'username' => $data['username']], ['profile' => $data['profile'] ?? 'unknown', 'status' => ($data['enabled'] ?? true) ? 'active' : 'disabled', 'metadata' => ['adopted_from_discovery' => true]]);
            $connection->update(['network_account_id' => $account->id]);
            $resource->update(['management_state' => 'ADOPTED', 'customer_connection_id' => $connection->id, 'network_account_id' => $account->id]);
            NetworkDiscoveryAudit::create(['tenant_id' => $resource->tenant_id, 'router_id' => $resource->router_id, 'discovered_network_resource_id' => $resource->id, 'initiated_by_user_id' => $user->id, 'action' => 'ADOPTION', 'details' => ['previous_state' => 'DISCOVERED', 'new_state' => 'ADOPTED', 'customer_connection_id' => $connection->id], 'occurred_at' => now()]);

            return $resource->fresh();
        });
    }
}
