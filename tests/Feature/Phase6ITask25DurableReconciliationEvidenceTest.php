<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAccount;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\NetworkOperationLog;
use App\Models\ReconciliationEvidence;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\AdoptDiscoveredNetworkResource;
use App\Services\Network\DiscoveryResult;
use App\Services\Network\NetworkDiscoveryClient;
use App\Services\Network\NetworkDiscoveryService;
use App\Services\Network\NetworkReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Phase6ITask25DurableReconciliationEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matched_reconciliation_persists_exact_durable_evidence(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->adoptedFixture();
        $snapshot = $resource->fresh()->discoverySnapshot;

        $result = app(NetworkReconciliationService::class)->reconcile($router);
        $evidence = ReconciliationEvidence::query()->latest('id')->firstOrFail();

        $this->assertSame('MATCHED', $result->firstWhere('resource.id', $resource->id)['status']);
        $this->assertSame('MATCHED', $evidence->outcome);
        $this->assertSame($tenant->id, $evidence->tenant_id);
        $this->assertSame($router->id, $evidence->router_id);
        $this->assertSame($resource->id, $evidence->adopted_resource_id);
        $this->assertSame($resource->id, $evidence->compared_resource_id);
        $this->assertSame($snapshot->id, $evidence->discovery_snapshot_id);
        $this->assertSame($connection->networkAccount->id, $evidence->network_account_id);
        $this->assertSame($connection->id, $evidence->customer_connection_id);
        $this->assertEquals($snapshot->discovered_at, $evidence->discovered_at);
        $this->assertNotNull($evidence->reconciled_at);
        $this->assertNotEmpty($evidence->relationship_fingerprint);
        $this->assertTrue($evidence->isCurrentlyApplicable());
    }

    public function test_nullable_routeros_text_does_not_duplicate_an_adopted_stable_identity(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $discovery = app(NetworkDiscoveryService::class);
        $first = $discovery->persist($router, $user, $this->discoveryResult($router, ''));
        $resource = DiscoveredNetworkResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('router_id', $router->id)
            ->where('resource_type', 'pppoe_account')
            ->where('external_ref', '*1D')
            ->firstOrFail();
        $connection = $this->connection($tenant, $router);
        app(AdoptDiscoveredNetworkResource::class)->handle($resource, $connection, $user);

        $second = $discovery->persist($router->fresh(), $user, $this->discoveryResult($router, null));
        $rows = DiscoveredNetworkResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('router_id', $router->id)
            ->where('resource_type', 'pppoe_account')
            ->where('external_ref', '*1D')
            ->get();
        $result = app(NetworkReconciliationService::class)->reconcile($router->fresh());

        $this->assertSame(1, $rows->count());
        $this->assertSame($resource->id, $rows->first()->id);
        $this->assertSame($second->id, $rows->first()->discovery_snapshot_id);
        $this->assertSame($connection->id, $rows->first()->customer_connection_id);
        $this->assertSame($connection->fresh()->network_account_id, $rows->first()->network_account_id);
        $this->assertSame('ADOPTED', $rows->first()->management_state);
        $this->assertSame('', $rows->first()->normalized_data['comment']);
        $this->assertSame('MATCHED', $result->firstWhere('resource.id', $resource->id)['status']);
        $this->assertDatabaseHas('network_reconciliation_evidence', [
            'adopted_resource_id' => $resource->id,
            'compared_resource_id' => $resource->id,
            'discovery_snapshot_id' => $second->id,
            'outcome' => 'MATCHED',
        ]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_each_reconciliation_outcome_is_persisted_as_historical_evidence(): void
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $snapshot = $this->successfulSnapshot($tenant, $router, now());

        $newResource = $this->resource($tenant, $router, $snapshot, [
            'external_ref' => 'new-user',
            'username' => 'new-user',
            'profile' => 'HOME-20M',
        ], 'new-fingerprint');

        $account = NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'changed-user',
            'profile' => 'HOME-10M',
            'status' => 'active',
        ]);
        $connection = $this->connection($tenant, $router);
        $newSnapshot = $this->successfulSnapshot($tenant, $router, now()->addMinute());
        $changedResource = $this->resource($tenant, $router, $newSnapshot, [
            'external_ref' => 'changed-user',
            'username' => 'changed-user',
            'profile' => 'HOME-20M',
        ], 'changed-fingerprint', $account, $connection, 'ADOPTED');

        $conflictAccount = NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'conflict-account',
            'profile' => 'HOME-20M',
            'status' => 'active',
        ]);
        $conflictResource = $this->resource($tenant, $router, $newSnapshot, [
            'external_ref' => 'conflict-user',
            'username' => 'discovered-user',
            'profile' => 'HOME-20M',
        ], 'conflict-fingerprint', $conflictAccount, $connection, 'ADOPTED');

        $missingResource = $this->resource($tenant, $router, $snapshot, [
            'external_ref' => 'missing-user',
            'username' => 'missing-user',
            'profile' => 'HOME-20M',
        ], 'missing-fingerprint', NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'missing-user',
            'profile' => 'HOME-20M',
            'status' => 'active',
        ]), $this->connection($tenant, $router), 'ADOPTED');

        $matchedAccount = NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'matched-user',
            'profile' => 'HOME-20M',
            'status' => 'active',
        ]);
        $matchedResource = $this->resource($tenant, $router, $newSnapshot, [
            'external_ref' => 'matched-user',
            'username' => 'matched-user',
            'profile' => 'HOME-20M',
        ], 'matched-fingerprint', $matchedAccount, $this->connection($tenant, $router), 'ADOPTED');

        $newResource->update(['discovery_snapshot_id' => $newSnapshot->id]);

        $outcomes = app(NetworkReconciliationService::class)->reconcile($router)
            ->keyBy('resource.id')
            ->map(fn (array $row) => $row['status']);

        $this->assertSame('NEW', $outcomes[$newResource->id]);
        $this->assertSame('CHANGED', $outcomes[$changedResource->id]);
        $this->assertSame('CONFLICT', $outcomes[$conflictResource->id]);
        $this->assertSame('MISSING', $outcomes[$missingResource->id]);
        $this->assertSame('MATCHED', $outcomes[$matchedResource->id]);
        $this->assertSame(5, ReconciliationEvidence::query()->count());
        $this->assertSame(5, ReconciliationEvidence::query()->whereIn('outcome', ['MATCHED', 'NEW', 'MISSING', 'CHANGED', 'CONFLICT'])->count());
    }

    public function test_changed_fingerprint_keeps_adopted_and_current_resource_identities_distinguishable(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->adoptedFixture();
        $oldSnapshot = $resource->fresh()->discoverySnapshot;

        $newSnapshot = $this->successfulSnapshot($tenant, $router, now()->addMinute());
        $currentResource = $this->resource($tenant, $router, $newSnapshot, [
            'external_ref' => $resource->external_ref,
            'username' => 'pppoe-yusuf',
            'profile' => 'HOME-50M',
        ], 'new-content-fingerprint');

        $result = app(NetworkReconciliationService::class)->reconcile($router)->firstWhere('resource.id', $resource->id);
        $evidence = ReconciliationEvidence::query()->where('adopted_resource_id', $resource->id)->latest('id')->firstOrFail();

        $this->assertSame('MISSING', $result['status']);
        $this->assertSame($resource->id, $evidence->adopted_resource_id);
        $this->assertSame($currentResource->id, $evidence->compared_resource_id);
        $this->assertSame($newSnapshot->id, $evidence->discovery_snapshot_id);
        $this->assertNotSame($resource->id, $currentResource->id);
        $this->assertSame($newSnapshot->id, $currentResource->discovery_snapshot_id);
    }

    public function test_newer_discovery_relationship_and_freshness_changes_invalidate_old_matched_evidence(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->adoptedFixture();
        app(NetworkReconciliationService::class)->reconcile($router);
        $evidence = ReconciliationEvidence::query()->latest('id')->firstOrFail();

        $this->assertTrue($evidence->isCurrentlyApplicable());

        $connection->networkAccount->update(['profile' => 'DRIFTED-PROFILE']);
        $this->assertFalse($evidence->fresh()->isCurrentlyApplicable());
        $connection->networkAccount->update(['profile' => 'HOME-20M']);

        $newSnapshot = $this->successfulSnapshot($tenant, $router, now()->addMinute());
        $this->assertFalse($evidence->fresh()->isCurrentlyApplicable());

        $evidence->update(['discovered_at' => now()->subSeconds(config('network.discovery_freshness_seconds') + 1)]);
        $this->assertFalse($evidence->fresh()->isCurrentlyApplicable());

        $evidence->update(['discovered_at' => $newSnapshot->discovered_at]);
        $connection->networkAccount->update(['username' => 'drifted-user']);
        $this->assertFalse($evidence->fresh()->isCurrentlyApplicable());

        $resource->update(['fingerprint' => 'drifted-resource-fingerprint']);
        $this->assertFalse($evidence->fresh()->isCurrentlyApplicable());
    }

    public function test_discovery_and_reconciliation_lock_the_router_serialization_boundary(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->adoptedFixture();
        DB::enableQueryLog();

        app(NetworkReconciliationService::class)->reconcile($router);

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query) => strtolower($query));

        $this->assertTrue($queries->contains(fn (string $query) => str_contains($query, 'from "routers"') && str_contains($query, 'for update')));
    }

    public function test_adoption_uses_canonical_discovery_freshness_and_preserves_tenant_router_boundaries(): void
    {
        config()->set('network.discovery_freshness_seconds', 60);
        [$tenant, $user, $router, $resource, $connection] = $this->discoveredFixture(now()->subSeconds(61));

        $this->expectException(HttpException::class);
        app(AdoptDiscoveredNetworkResource::class)->handle($resource, $connection, $user);
    }

    public function test_evidence_contains_no_secret_material_and_discovery_does_not_create_network_operations(): void
    {
        [$tenant, $user, $router, $resource, $connection] = $this->adoptedFixture();
        app(NetworkReconciliationService::class)->reconcile($router);

        $evidence = ReconciliationEvidence::query()->latest('id')->firstOrFail();
        $serialized = json_encode($evidence->toArray());

        foreach (['password', 'encrypted_secret', 'credential', 'token', 'authorization'] as $secretKey) {
            $this->assertStringNotContainsString($secretKey, strtolower($serialized));
        }
        $this->assertSame(0, NetworkOperationLog::query()->count());
    }

    private function adoptedFixture(?Carbon $discoveredAt = null): array
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery($discoveredAt);
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();
        $resource = DiscoveredNetworkResource::query()->where('tenant_id', $tenant->id)->where('resource_type', 'pppoe_account')->firstOrFail();
        $connection = $this->connection($tenant, $router);
        app(AdoptDiscoveredNetworkResource::class)->handle($resource, $connection, $user);

        return [$tenant, $user, $router, $resource->fresh(), $connection->fresh()];
    }

    private function discoveredFixture(?Carbon $discoveredAt = null): array
    {
        [$tenant, $user, $router] = $this->tenantRouter();
        $this->fakeDiscovery($discoveredAt);
        $this->actingAs($user)->post(route('network.discovery.run', $router))->assertRedirect();
        $resource = DiscoveredNetworkResource::query()->where('tenant_id', $tenant->id)->where('resource_type', 'pppoe_account')->firstOrFail();
        $connection = $this->connection($tenant, $router);

        return [$tenant, $user, $router, $resource, $connection];
    }

    private function successfulSnapshot(Tenant $tenant, Router $router, Carbon $discoveredAt): NetworkDiscoverySnapshot
    {
        return NetworkDiscoverySnapshot::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'provider' => 'fake',
            'status' => 'success',
            'discovered_at' => $discoveredAt,
            'summary' => [],
            'snapshot' => ['device' => ['name' => 'CORE-01']],
        ]);
    }

    private function discoveryResult(Router $router, ?string $comment): DiscoveryResult
    {
        return new DiscoveryResult(true, 'Read-only discovery complete', null, [
            'provider' => 'routeros',
            'router_ref' => (string) $router->id,
            'discovered_at' => now()->toIso8601String(),
            'snapshot' => [
                'device' => ['name' => 'TP-Link', 'routeros_version' => '6.49.13 (long-term)'],
                'profiles' => [],
                'accounts' => [[
                    'external_ref' => '*1D',
                    'username' => 'cosmiclink-test',
                    'profile' => 'cosmiclink-test',
                    'service' => 'pppoe',
                    'enabled' => false,
                    'comment' => $comment,
                ]],
                'address_pools' => [],
                'queues' => [],
            ],
        ]);
    }

    private function resource(Tenant $tenant, Router $router, NetworkDiscoverySnapshot $snapshot, array $data, string $fingerprint, ?NetworkAccount $account = null, ?CustomerConnection $connection = null, string $state = 'DISCOVERED'): DiscoveredNetworkResource
    {
        return DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'discovery_snapshot_id' => $snapshot->id,
            'customer_connection_id' => $connection?->id,
            'network_account_id' => $account?->id,
            'resource_type' => 'pppoe_account',
            'external_ref' => $data['external_ref'],
            'name' => $data['username'],
            'management_state' => $state,
            'fingerprint' => $fingerprint,
            'normalized_data' => $data,
            'first_seen_at' => $snapshot->discovered_at,
            'last_seen_at' => $snapshot->discovered_at,
        ]);
    }

    private function connection(Tenant $tenant, Router $router): CustomerConnection
    {
        return CustomerConnection::factory()
            ->for(Customer::factory()->for($tenant))
            ->for($router)
            ->create(['tenant_id' => $tenant->id]);
    }

    private function tenantRouter(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();

        return [$tenant, $user, $router];
    }

    private function fakeDiscovery(?Carbon $discoveredAt = null): void
    {
        app()->bind(NetworkDiscoveryClient::class, fn () => new class($discoveredAt) implements NetworkDiscoveryClient
        {
            public function __construct(private readonly ?Carbon $discoveredAt) {}

            public function discover(Router $router): DiscoveryResult
            {
                return new DiscoveryResult(true, 'Read-only discovery complete', null, [
                    'provider' => 'fake',
                    'router_ref' => (string) $router->id,
                    'discovered_at' => ($this->discoveredAt ?? now())->toIso8601String(),
                    'snapshot' => [
                        'device' => ['name' => 'CORE-01', 'routeros_version' => '6.49.13'],
                        'profiles' => [],
                        'accounts' => [[
                            'external_ref' => 'pppoe-yusuf',
                            'username' => 'pppoe-yusuf',
                            'profile' => 'HOME-20M',
                            'enabled' => true,
                            'password' => 'never-return',
                        ]],
                        'address_pools' => [],
                        'queues' => [],
                    ],
                ]);
            }
        });
    }
}
