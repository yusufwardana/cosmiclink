<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAccount;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\ReconciliationEvidence;
use App\Models\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NetworkReconciliationService
{
    /**
     * Classify every discovered resource of a router against its latest
     * successful snapshot and return the status/suggestion rows.
     *
     * Evidence is append-only audit material produced by explicit or background
     * reconciliation. A caller that only needs the in-memory classification to
     * render or answer a read must pass `$persistEvidence = false`, so that
     * viewing, filtering, paging, or polling a console never writes evidence.
     */
    public function reconcile(Router $router, bool $persistEvidence = true): Collection
    {
        return DB::transaction(function () use ($router, $persistEvidence) {
            $router = Router::query()->lockForUpdate()->findOrFail($router->id);
            $latestSnapshot = NetworkDiscoverySnapshot::query()
                ->where('tenant_id', $router->tenant_id)
                ->where('router_id', $router->id)
                ->where('status', 'success')
                ->orderByDesc('discovered_at')
                ->orderByDesc('id')
                ->first();

            return DiscoveredNetworkResource::query()
                ->where('tenant_id', $router->tenant_id)
                ->where('router_id', $router->id)
                ->get()
                ->map(function (DiscoveredNetworkResource $resource) use ($latestSnapshot, $router, $persistEvidence) {
                    $comparedResource = $this->currentResource($resource, $latestSnapshot);
                    $row = $this->reconcileResource($resource, $comparedResource, $latestSnapshot, $router);

                    // Evidence is append-only audit material. It is written by explicit
                    // or background reconciliation, never by a GET that only needs the
                    // in-memory classification — otherwise paging the Discovery console
                    // or refreshing the API would fan out one evidence row per resource.
                    if ($persistEvidence) {
                        $this->persistEvidence($resource, $comparedResource, $latestSnapshot, $row['status']);
                    }

                    return $row;
                });
        });
    }

    private function reconcileResource(DiscoveredNetworkResource $resource, ?DiscoveredNetworkResource $comparedResource, ?NetworkDiscoverySnapshot $latestSnapshot, Router $router): array
    {
        if ($resource->resource_type !== 'pppoe_account') {
            return ['resource' => $resource, 'status' => 'NEW'];
        }
        if ($resource->management_state === 'ADOPTED' && $latestSnapshot && $resource->discovery_snapshot_id !== $latestSnapshot->id) {
            return ['resource' => $resource, 'status' => 'MISSING'];
        }
        if (! $resource->customer_connection_id) {
            return ['resource' => $resource, 'status' => 'NEW', 'suggestion' => $this->suggestion($resource, $router)];
        }
        if (! $resource->networkAccount) {
            return ['resource' => $resource, 'status' => 'CONFLICT'];
        }
        $data = $resource->normalized_data;

        return $resource->networkAccount->username !== ($data['username'] ?? null)
            ? ['resource' => $resource, 'status' => 'CONFLICT']
            : ($resource->networkAccount->profile !== ($data['profile'] ?? null)
                ? ['resource' => $resource, 'status' => 'CHANGED']
                : ['resource' => $resource, 'status' => 'MATCHED']);
    }

    private function currentResource(DiscoveredNetworkResource $resource, ?NetworkDiscoverySnapshot $snapshot): ?DiscoveredNetworkResource
    {
        if (! $snapshot) {
            return null;
        }

        return DiscoveredNetworkResource::query()
            ->where('tenant_id', $resource->tenant_id)
            ->where('router_id', $resource->router_id)
            ->where('resource_type', $resource->resource_type)
            ->where('discovery_snapshot_id', $snapshot->id)
            ->where('external_ref', $resource->external_ref)
            ->latest('id')
            ->first();
    }

    private function persistEvidence(DiscoveredNetworkResource $resource, ?DiscoveredNetworkResource $comparedResource, ?NetworkDiscoverySnapshot $snapshot, string $outcome): void
    {
        ReconciliationEvidence::create([
            'tenant_id' => $resource->tenant_id,
            'router_id' => $resource->router_id,
            'adopted_resource_id' => $resource->id,
            'compared_resource_id' => $comparedResource?->id,
            'discovery_snapshot_id' => $snapshot?->id,
            'network_account_id' => $resource->network_account_id,
            'customer_connection_id' => $resource->customer_connection_id,
            'outcome' => $outcome,
            'discovered_at' => $snapshot?->discovered_at,
            'reconciled_at' => now(),
            'adopted_fingerprint' => $resource->fingerprint,
            'compared_fingerprint' => $comparedResource?->fingerprint,
            'relationship_fingerprint' => ReconciliationEvidence::relationshipFingerprint($resource, $comparedResource, $resource->network_account_id, $resource->customer_connection_id),
        ]);
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
