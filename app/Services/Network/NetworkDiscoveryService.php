<?php

namespace App\Services\Network;

use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkDiscoveryAudit;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\Router;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NetworkDiscoveryService
{
    public function __construct(private readonly NetworkDiscoveryClient $client) {}

    public function discover(Router $router, User $user): NetworkDiscoverySnapshot
    {
        $result = $this->client->discover($router);

        return $this->persist($router, $user, $result);
    }

    public function persist(Router $router, ?User $user, DiscoveryResult $result): NetworkDiscoverySnapshot
    {
        return DB::transaction(function () use ($router, $user, $result) {
            $normalizedSnapshot = $this->sanitize($result->data['snapshot'] ?? []);
            $snapshot = NetworkDiscoverySnapshot::create(['tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'initiated_by_user_id' => $user?->id, 'provider' => $result->data['provider'] ?? 'unknown', 'status' => $result->successful ? 'success' : 'failed', 'discovered_at' => $result->data['discovered_at'] ?? now(), 'summary' => $result->successful ? $this->summary($normalizedSnapshot) : [], 'snapshot' => $result->successful ? $normalizedSnapshot : null, 'error' => $result->successful ? null : $result->message]);
            if ($result->successful) {
                foreach (['profiles' => 'pppoe_profile', 'accounts' => 'pppoe_account', 'address_pools' => 'address_pool', 'queues' => 'queue'] as $section => $type) {
                    foreach (($normalizedSnapshot[$section] ?? []) as $data) {
                        $data = $this->sanitize($data);
                        $fingerprint = hash('sha256', json_encode($this->canonical($data)));
                        $resource = DiscoveredNetworkResource::firstOrNew(['tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'resource_type' => $type, 'fingerprint' => $fingerprint]);
                        $resource->fill(['discovery_snapshot_id' => $snapshot->id, 'external_ref' => (string) ($data['external_ref'] ?? $data['name'] ?? $fingerprint), 'name' => (string) ($data['username'] ?? $data['name'] ?? $data['external_ref'] ?? $fingerprint), 'normalized_data' => $data, 'last_seen_at' => now()]);
                        if (! $resource->exists) {
                            $resource->first_seen_at = now();
                        }
                        $resource->save();
                    }
                }
            }
            NetworkDiscoveryAudit::create(['tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'initiated_by_user_id' => $user?->id, 'action' => 'DISCOVERY', 'details' => $this->sanitize(['status' => $snapshot->status, 'provider' => $snapshot->provider, 'counts' => $snapshot->summary, 'device_identity' => $normalizedSnapshot['device']['name'] ?? null, 'routeros_version' => $normalizedSnapshot['device']['routeros_version'] ?? null]), 'occurred_at' => now()]);

            return $snapshot;
        });
    }

    private function summary(array $snapshot): array
    {
        return ['profiles' => count($snapshot['profiles'] ?? []), 'accounts' => count($snapshot['accounts'] ?? []), 'address_pools' => count($snapshot['address_pools'] ?? []), 'queues' => count($snapshot['queues'] ?? [])];
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), ['password', 'pass', 'secret', 'token', 'authorization', 'credential', 'credentials'], true)) {
                unset($data[$k]);
            } elseif (is_array($v)) {
                $data[$k] = $this->sanitize($v);
            }
        }

        return $data;
    }

    private function canonical(array $data): array
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                $v = $this->canonical($v);
            }
        }

        return $data;
    }
}
