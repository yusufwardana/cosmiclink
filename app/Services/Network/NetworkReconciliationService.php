<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAccount;
use App\Models\Router;
use Illuminate\Support\Collection;

class NetworkReconciliationService
{
    public function reconcile(Router $router): Collection
    {
        $latestSnapshotId = $router->discoverySnapshots()->where('status', 'success')->latest('id')->value('id');

        return DiscoveredNetworkResource::where('tenant_id', $router->tenant_id)->where('router_id', $router->id)->get()->map(function ($resource) use ($latestSnapshotId, $router) {
            if ($resource->resource_type !== 'pppoe_account') {
                return ['resource' => $resource, 'status' => 'NEW'];
            }
            if ($resource->management_state === 'ADOPTED' && $latestSnapshotId && $resource->discovery_snapshot_id !== $latestSnapshotId) {
                return ['resource' => $resource, 'status' => 'MISSING'];
            }
            if (! $resource->customer_connection_id) {
                $suggestion = $this->suggestion($resource, $router);

                return ['resource' => $resource, 'status' => 'NEW', 'suggestion' => $suggestion];
            }
            if (! $resource->networkAccount) {
                return ['resource' => $resource, 'status' => 'CONFLICT'];
            }
            $data = $resource->normalized_data;

            return $resource->networkAccount->username !== ($data['username'] ?? null) ? ['resource' => $resource, 'status' => 'CONFLICT'] : ($resource->networkAccount->profile !== ($data['profile'] ?? null) ? ['resource' => $resource, 'status' => 'CHANGED'] : ['resource' => $resource, 'status' => 'MATCHED']);
        });
    }

    private function suggestion(DiscoveredNetworkResource $resource, Router $router): ?array
    {
        $username = $this->normalize($resource->normalized_data['username'] ?? $resource->name);
        if ($username === '') {
            return null;
        }

        $account = NetworkAccount::where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->get()
            ->first(fn (NetworkAccount $account) => $this->normalize($account->username) === $username);
        $connection = $account?->connections()->where('tenant_id', $router->tenant_id)->first();

        if (! $connection) {
            $connection = CustomerConnection::where('tenant_id', $router->tenant_id)
                ->where('router_id', $router->id)
                ->with('networkAccount')
                ->get()
                ->first(fn (CustomerConnection $candidate) => $this->normalize($candidate->networkAccount?->username) === $username);
        }

        if (! $connection) {
            return null;
        }

        return [
            'customer_connection_id' => $connection->id,
            'username' => $connection->networkAccount?->username ?? $resource->normalized_data['username'] ?? $resource->name,
            'customer' => $connection->customer?->name,
            'reason' => 'Exact PPPoE username + same router',
        ];
    }

    private function normalize(?string $username): string
    {
        return mb_strtolower(trim((string) $username));
    }
}
