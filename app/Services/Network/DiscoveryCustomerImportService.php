<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkDiscoveryAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DiscoveryCustomerImportService
{
    public function __construct(private readonly CreateMonthlyCustomer $creator, private readonly GoNetworkMonitoringClient $monitoringClient) {}

    public function candidates(int $tenantId, bool $refreshHotspotValidation = false): array
    {
        $resources = DiscoveredNetworkResource::query()
            ->where('tenant_id', $tenantId)
            ->where('resource_type', 'queue')
            ->with('router')
            ->orderBy('router_id')
            ->orderBy('id')
            ->get();

        $candidates = [];
        $identityOwners = [];
        $hotspotAccounts = $this->hotspotAccounts($resources, $refreshHotspotValidation);
        foreach ($resources as $resource) {
            $data = (array) $resource->normalized_data;
            $name = (string) ($data['name'] ?? $resource->name);
            $target = (string) ($data['target'] ?? '');
            $username = $this->hotspotUsername($name);
            $isHotspot = $username !== '' && strcasecmp($username, $name) !== 0;
            $identity = $isHotspot ? $username : $target;
            $identityKey = $resource->router_id.':'.($isHotspot ? 'hotspot' : 'simple_queue').':'.strtolower($identity);
            $candidateKey = ($isHotspot ? 'hotspot:' : 'simple:').$resource->id;

            if ($isHotspot) {
                $existing = collect($candidates)->first(fn (array $candidate) => $candidate['identity_key'] === $identityKey);
                if ($existing) {
                    $index = collect($candidates)->search(fn (array $candidate) => $candidate['key'] === $existing['key']);
                    $candidates[$index]['resource_ids'][] = $resource->id;
                    $candidates[$index]['discovery_snapshot_ids'][] = $resource->discovery_snapshot_id;
                    $candidates[$index]['discovery_evidence'][] = $target;
                    $candidates[$index]['device_session_evidence'] = $this->sessionEvidence($resource, $username, $candidates[$index]['device_session_evidence']);
                    continue;
                }
            }

            $state = 'READY';
            $reason = null;
            if ($resource->management_state === 'ADOPTED' || $resource->customer_connection_id !== null) {
                $state = 'ALREADY ADOPTED';
                $reason = 'Discovery resource is already adopted or linked.';
            } elseif (! $isHotspot && ! $this->singleIpv4Target($target)) {
                $state = 'EXCLUDED';
                $reason = 'Only individual IPv4 /32 targets are eligible for static IP import.';
            } elseif ($isHotspot && ! $this->authoritativeHotspotEvidence($data, $hotspotAccounts[$resource->router_id][strtolower($username)] ?? null)) {
                $state = 'NEEDS VALIDATION';
                $reason = 'Authoritative Hotspot account evidence is unavailable.';
            } elseif (isset($identityOwners[$identityKey])) {
                $state = 'DUPLICATE';
                $reason = 'Stable identity is duplicated in Discovery.';
            } elseif ($this->existingConnection($tenantId, $resource->router_id, $isHotspot, $identity)) {
                $state = 'DUPLICATE';
                $reason = 'A CustomerConnection already uses this stable identity.';
            }

            $candidate = [
                'key' => $candidateKey,
                'kind' => $isHotspot ? 'hotspot' : 'simple_queue',
                'access_mode' => $isHotspot ? 'hotspot' : 'static_ip',
                'network_mechanism' => $isHotspot ? 'hotspot' : 'simple_queue',
                'name' => $isHotspot ? $username : $name,
                'default_name' => $isHotspot ? $username : $name,
                'router_id' => $resource->router_id,
                'network_identity' => $identity,
                'resource_ids' => [$resource->id],
                'discovery_snapshot_ids' => [$resource->discovery_snapshot_id],
                'discovery_evidence' => [$target],
                'device_session_evidence' => $this->sessionEvidence($resource, $username, []),
                'hotspot_validation' => $isHotspot ? $this->hotspotValidation($data, $hotspotAccounts[$resource->router_id][strtolower($username)] ?? null) : null,
                'state' => $state,
                'reason' => $reason,
                'identity_key' => $identityKey,
            ];
            $candidates[] = $candidate;
            if (! isset($identityOwners[$identityKey]) && $state !== 'EXCLUDED' && $state !== 'NEEDS VALIDATION') {
                $identityOwners[$identityKey] = true;
            }
        }

        $candidates = collect($candidates)->map(function (array $candidate): array {
            unset($candidate['identity_key']);
            return $candidate;
        })->values()->all();
        $summary = collect(['STATIC IP READY', 'HOTSPOT READY', 'NEEDS VALIDATION', 'ALREADY ADOPTED', 'DUPLICATE', 'EXCLUDED', 'TOTAL READY'])
            ->mapWithKeys(fn (string $label) => [$label => 0])->all();
        foreach ($candidates as $candidate) {
            if ($candidate['state'] === 'READY') {
                $summary[$candidate['access_mode'] === 'hotspot' ? 'HOTSPOT READY' : 'STATIC IP READY']++;
                $summary['TOTAL READY']++;
            } else {
                $summary[$candidate['state']]++;
            }
        }

        return ['candidates' => $candidates, 'simple' => collect($candidates)->where('kind', 'simple_queue')->values()->all(), 'hotspot' => collect($candidates)->where('kind', 'hotspot')->values()->all(), 'summary' => $summary];
    }

    public function selected(array $keys, int $tenantId, bool $refreshHotspotValidation = false): array
    {
        $candidates = $this->candidates($tenantId, $refreshHotspotValidation);
        $all = collect($candidates['candidates'])
            ->keyBy('key');

        return collect($keys)->unique()->map(function ($key) use ($all) {
            if (! is_string($key) || ! $all->has($key)) {
                throw ValidationException::withMessages(['candidates' => 'One or more selected Discovery candidates are no longer available.']);
            }
            $candidate = $all->get($key);
            if ($candidate['state'] !== 'READY') {
                throw ValidationException::withMessages(['candidates' => 'Only READY candidates may be selected for a future import.']);
            }
            return $candidate;
        })->values()->all();
    }

    public function import(User $user, array $items): array
    {
        return DB::transaction(function () use ($user, $items): array {
            $seen = [];
            foreach ($items as $item) {
                abort_unless(count(array_unique($item['discovery_snapshot_ids'] ?? [])) === 1, 422, 'All resources in one imported candidate must belong to the same Discovery snapshot.');
                $identityKey = $item['router_id'].':'.$item['kind'].':'.strtolower((string) $item['network_identity']);
                if (isset($seen[$identityKey])) {
                    throw ValidationException::withMessages(['candidates' => 'The selected import contains a duplicate stable network identity.']);
                }
                $seen[$identityKey] = true;
                $name = trim((string) ($item['names'][$item['key']] ?? $item['default_name']));
                Validator::validate(['name' => $name], ['name' => ['required', 'string', 'max:255']]);
                $this->assertNoDuplicate($user->tenant_id, $item);
            }

            $created = [];
            foreach ($items as $item) {
                $name = trim((string) ($item['names'][$item['key']] ?? $item['default_name']));
                $created[] = $this->creator->handle($user, [
                    'name' => $name,
                    'status' => 'active',
                    'router_id' => $item['router_id'],
                    'connection_mode' => $item['kind'] === 'hotspot' ? 'hotspot' : 'simple_queue',
                    'network_identity' => $item['network_identity'],
                    'metadata' => [
                        'imported_from_discovery' => true,
                        'discovery_snapshot_id' => $item['discovery_snapshot_ids'][0] ?? null,
                        'discovery_resource_ids' => $item['resource_ids'],
                        'runtime_targets' => $item['discovery_evidence'],
                    ],
                ]);
                $connection = CustomerConnection::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('customer_id', $created[array_key_last($created)]->id)
                    ->where('router_id', $item['router_id'])
                    ->where('metadata->connection_mode', $item['kind'] === 'hotspot' ? 'hotspot' : 'simple_queue')
                    ->whereRaw("LOWER(metadata->>'network_identity') = ?", [strtolower((string) $item['network_identity'])])
                    ->latest('id')
                    ->firstOrFail();
                $this->adoptResources($user, $connection, $item['resource_ids'], $item);
                $this->linkObservations($user, $connection, $item);
            }

            return $created;
        });
    }

    public function reconcileExisting(User $user, CustomerConnection $connection, array $resourceIds, array $expected): void
    {
        DB::transaction(function () use ($user, $connection, $resourceIds, $expected): void {
            $connection = CustomerConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $this->adoptResources($user, $connection, $resourceIds, $expected);
        });
    }

    private function adoptResources(User $user, CustomerConnection $connection, array $resourceIds, array $expected): void
    {
        foreach ($resourceIds as $resourceId) {
            $resource = DiscoveredNetworkResource::query()->lockForUpdate()->findOrFail($resourceId);
            abort_unless($resource->tenant_id === $user->tenant_id && $resource->router_id === $connection->router_id, 403);
            abort_unless(in_array($resource->discovery_snapshot_id, $expected['discovery_snapshot_ids'] ?? [], true) && $resource->resource_type === 'queue', 422, 'Import provenance does not match the reviewed Discovery snapshot.');
            abort_unless($resource->management_state === 'DISCOVERED', 422, 'Discovery resource is already adopted.');
            abort_unless($resource->customer_connection_id === null, 422, 'Discovery resource is already linked.');

            $data = (array) $resource->normalized_data;
            $target = (string) ($data['target'] ?? '');
            $name = (string) ($data['name'] ?? $resource->name);
            $expectedIdentity = (string) $expected['network_identity'];
            $matchesIdentity = $expected['kind'] === 'hotspot'
                ? strcasecmp((string) $expected['network_identity'], $this->hotspotUsername($name)) === 0
                : $target === $expectedIdentity;
            abort_unless($matchesIdentity, 422, 'Discovery identity does not match the imported connection.');

            $resource->update([
                'management_state' => 'ADOPTED',
                'customer_connection_id' => $connection->id,
            ]);
            NetworkDiscoveryAudit::create([
                'tenant_id' => $resource->tenant_id,
                'router_id' => $resource->router_id,
                'discovered_network_resource_id' => $resource->id,
                'initiated_by_user_id' => $user->id,
                'action' => 'CUSTOMER_IMPORT_ADOPTION',
                'details' => [
                    'previous_state' => 'DISCOVERED',
                    'new_state' => 'ADOPTED',
                    'customer_connection_id' => $connection->id,
                    'snapshot_id' => $resource->discovery_snapshot_id,
                    'connection_mode' => $expected['kind'],
                    'network_identity' => $expectedIdentity,
                ],
                'occurred_at' => now(),
            ]);
        }
    }

    private function linkObservations(User $user, CustomerConnection $connection, array $item): void
    {
        if ($item['access_mode'] === 'hotspot') {
            DeviceObservation::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('router_id', $connection->router_id)
                ->whereRaw('LOWER(TRIM(network_identity)) = ?', [strtolower(trim((string) $item['network_identity']))])
                ->update(['customer_connection_id' => $connection->id]);

            return;
        }

        DeviceObservation::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('router_id', $connection->router_id)
            ->where('source', 'arp')
            ->where('ip_address', substr((string) $item['network_identity'], 0, -3))
            ->update(['customer_connection_id' => $connection->id]);
    }

    private function hotspotUsername(string $name): string
    {
        return trim((string) preg_replace('/\s*-hotspot\s*->\s*\d{1,3}(?:\.\d{1,3}){3}\s*$/i', '', $name));
    }

    private function assertNoDuplicate(int $tenantId, array $item): void
    {
        $exists = CustomerConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('router_id', $item['router_id'])
            ->where('metadata->connection_mode', $item['kind'] === 'hotspot' ? 'hotspot' : 'simple_queue')
            ->whereRaw("LOWER(metadata->>'network_identity') = ?", [strtolower((string) $item['network_identity'])])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['candidates' => 'A Customer already exists for '.$item['network_identity'].' on this router.']);
        }
    }

    private function singleIpv4Target(string $target): ?string
    {
        if (! preg_match('/^([^,\/]+)\/32$/', $target, $match)) {
            return null;
        }
        return filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $target : null;
    }

    private function hotspotAccounts($resources, bool $refresh = false): array
    {
        if (config('monitoring.driver') !== 'engine') {
            return [];
        }

        $accounts = [];
        foreach ($resources->groupBy('router_id') as $routerResources) {
            $router = $routerResources->first()->router;
            if (! $router) {
                continue;
            }
            $cacheKey = 'discovery-hotspot-accounts:'.$router->tenant_id.':'.$router->id;
            $rawAccounts = $refresh ? null : Cache::get($cacheKey);
            if (! is_array($rawAccounts)) {
                $response = $this->monitoringClient->validateHotspotAccounts($router);
                $rawAccounts = array_values(array_filter((array) ($response['hotspot_users'] ?? []), 'is_array'));
                Cache::put($cacheKey, $rawAccounts, now()->addMinutes(10));
            }
            foreach ($rawAccounts as $account) {
                $username = strtolower(trim((string) ($account['username'] ?? '')));
                if ($username !== '') {
                    $accounts[$router->id][$username] = array_intersect_key($account, array_flip(['username', 'profile', 'disabled', 'comment', 'validated_at']));
                }
            }
        }

        return $accounts;
    }

    private function authoritativeHotspotEvidence(array $data, ?array $account): bool
    {
        return isset($data['hotspot_account_validated']) && $data['hotspot_account_validated'] === true
            || isset($data['hotspot_account']['username']) && trim((string) $data['hotspot_account']['username']) !== ''
            || $account !== null && trim((string) ($account['username'] ?? '')) !== '';
    }

    private function hotspotValidation(array $data, ?array $account): ?array
    {
        $evidence = $account ?? ($data['hotspot_account'] ?? null);
        if (! is_array($evidence)) {
            return ['state' => 'NOT VALIDATED'];
        }
        return array_intersect_key($evidence, array_flip(['username', 'profile', 'disabled', 'comment', 'source', 'validated_at'])) + ['state' => 'VALIDATED'];
    }

    private function sessionEvidence(DiscoveredNetworkResource $resource, string $username, array $existing): array
    {
        $data = (array) $resource->normalized_data;
        $evidence = array_merge($existing, (array) ($data['session_evidence'] ?? []));
        foreach (DeviceObservation::query()->where('tenant_id', $resource->tenant_id)->where('router_id', $resource->router_id)->where('network_identity', strtolower($username))->get() as $observation) {
            $evidence[] = array_filter(['ip_address' => $observation->ip_address, 'mac_address' => $observation->mac_address, 'source' => $observation->source]);
        }
        return array_values(array_unique(array_map('serialize', $evidence))) === [] ? [] : array_map('unserialize', array_values(array_unique(array_map('serialize', $evidence))));
    }

    private function existingConnection(int $tenantId, int $routerId, bool $hotspot, string $identity): bool
    {
        return CustomerConnection::query()->where('tenant_id', $tenantId)->where('router_id', $routerId)->where('metadata->connection_mode', $hotspot ? 'hotspot' : 'simple_queue')->whereRaw("LOWER(metadata->>'network_identity') = ?", [strtolower($identity)])->exists();
    }
}