<?php

namespace App\Services\Network;

use App\Models\DiscoveredNetworkResource;
use App\Models\Router;
use Illuminate\Support\Collection;

class NetworkReconciliationService
{
    public function reconcile(Router $router): Collection
    {
        $latestSnapshotId = $router->discoverySnapshots()->where('status', 'success')->latest('id')->value('id');

        return DiscoveredNetworkResource::where('tenant_id', $router->tenant_id)->where('router_id', $router->id)->get()->map(function ($resource) use ($latestSnapshotId) {
            if ($resource->resource_type !== 'pppoe_account') {
                return ['resource' => $resource, 'status' => 'NEW'];
            }
            if ($resource->management_state === 'ADOPTED' && $latestSnapshotId && $resource->discovery_snapshot_id !== $latestSnapshotId) {
                return ['resource' => $resource, 'status' => 'MISSING'];
            }
            if (! $resource->customer_connection_id) {
                return ['resource' => $resource, 'status' => 'NEW'];
            }
            if (! $resource->networkAccount) {
                return ['resource' => $resource, 'status' => 'CONFLICT'];
            }
            $data = $resource->normalized_data;

            return $resource->networkAccount->username !== ($data['username'] ?? null) ? ['resource' => $resource, 'status' => 'CONFLICT'] : ($resource->networkAccount->profile !== ($data['profile'] ?? null) ? ['resource' => $resource, 'status' => 'CHANGED'] : ['resource' => $resource, 'status' => 'MATCHED']);
        });
    }
}
