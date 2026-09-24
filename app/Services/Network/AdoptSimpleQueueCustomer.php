<?php

namespace App\Services\Network;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkDiscoveryAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdoptSimpleQueueCustomer
{
    public function handle(DiscoveredNetworkResource $resource, User $user, string $name, string $status = 'active'): Customer
    {
        abort_unless($resource->tenant_id === $user->tenant_id, 403);
        abort_unless($resource->resource_type === 'queue', 422);
        abort_unless($this->isSingleHostTarget($resource->normalized_data['target'] ?? null), 422, 'Aggregate queue targets cannot become individual customers.');

        return DB::transaction(function () use ($resource, $user, $name, $status) {
            $resource = DiscoveredNetworkResource::query()->lockForUpdate()->findOrFail($resource->id);
            abort_unless($resource->tenant_id === $user->tenant_id, 403);
            abort_unless($resource->management_state === 'DISCOVERED', 422, 'This Discovery identity has already been adopted.');
            abort_unless($resource->last_seen_at?->gte(now()->subSeconds((int) config('network.discovery_freshness_seconds', 86400))), 409, 'Discovery evidence is stale.');

            $customer = Customer::create(['tenant_id' => $resource->tenant_id, 'name' => $name, 'status' => $status]);
            $connection = CustomerConnection::create([
                'tenant_id' => $resource->tenant_id,
                'customer_id' => $customer->id,
                'internet_package_id' => null,
                'router_id' => $resource->router_id,
                'status' => 'active',
                'metadata' => [
                    'connection_mode' => 'simple_queue',
                    'network_identity' => $resource->normalized_data['target'],
                    'adopted_from_discovery' => true,
                    'discovery_resource_id' => $resource->id,
                ],
            ]);
            $resource->update(['management_state' => 'ADOPTED', 'customer_connection_id' => $connection->id]);
            NetworkDiscoveryAudit::create([
                'tenant_id' => $resource->tenant_id,
                'router_id' => $resource->router_id,
                'discovered_network_resource_id' => $resource->id,
                'initiated_by_user_id' => $user->id,
                'action' => 'CUSTOMER_ADOPTION',
                'details' => ['customer_id' => $customer->id, 'connection_id' => $connection->id, 'resource_type' => 'queue', 'management_state' => 'ADOPTED'],
                'occurred_at' => now(),
            ]);

            return $customer->fresh();
        });
    }

    private function isSingleHostTarget(?string $target): bool
    {
        if (! is_string($target) || $target === '' || str_contains($target, ',') || ! str_ends_with($target, '/32')) return false;
        $slash = strrpos($target, '/');
        return is_int($slash) && filter_var(substr($target, 0, $slash), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}