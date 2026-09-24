<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\DiscoveredNetworkResource;
use App\Models\InternetPackage;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\DiscoveryCustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Step4SafeBulkCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_universe_classifies_static_hotspot_and_duplicate_states_without_mutation(): void
    {
        [$tenant, $user, $router] = $this->fixture();
        $customer = Customer::factory()->for($tenant)->create(['name' => 'Existing']);
        $package = InternetPackage::factory()->for($tenant)->create();
        $connection = CustomerConnection::factory()->for($customer)->for($package)->for($router)->create([
            'tenant_id' => $tenant->id,
            'metadata' => [
                'access_mode' => 'static_ip',
                'network_mechanism' => 'simple_queue',
                'connection_mode' => 'simple_queue',
                'network_identity' => '10.0.0.13/32',
            ],
        ]);

        $snapshot = $this->snapshot($tenant, $router);
        $this->resource($tenant, $router, $snapshot, 'KLENTRUK', '10.0.0.13/32', 'ADOPTED', $connection->id);
        $this->resource($tenant, $router, $snapshot, 'Static Ready', '10.0.0.20/32');
        $this->resource($tenant, $router, $snapshot, 'Aggregate', '10.0.0.0/24');
        $this->resource($tenant, $router, $snapshot, 'Invalid', 'not-an-ip');
        $this->resource($tenant, $router, $snapshot, 'Duplicate', '10.0.0.20/32');
        $this->resource($tenant, $router, $snapshot, 'alice-hotspot -> 10.0.0.30', '10.0.0.30/32');
        $this->resource($tenant, $router, $snapshot, 'alice-hotspot -> 10.0.0.31', '10.0.0.31/32');

        $before = [Customer::count(), CustomerConnection::count(), DiscoveredNetworkResource::count(), DeviceObservation::count()];
        $result = app(DiscoveryCustomerImportService::class)->candidates($tenant->id);

        $all = collect($result['candidates']);
        $this->assertSame('ALREADY ADOPTED', $all->firstWhere('name', 'KLENTRUK')['state']);
        $this->assertSame('READY', $all->firstWhere('name', 'Static Ready')['state']);
        $this->assertSame('EXCLUDED', $all->firstWhere('name', 'Aggregate')['state']);
        $this->assertSame('EXCLUDED', $all->firstWhere('name', 'Invalid')['state']);
        $this->assertSame('DUPLICATE', $all->firstWhere('name', 'Duplicate')['state']);

        $alice = $all->first(fn (array $candidate) => $candidate['network_identity'] === 'alice');
        $this->assertNotNull($alice);
        $this->assertSame('NEEDS VALIDATION', $alice['state']);
        $this->assertCount(2, $alice['resource_ids']);
        $this->assertSame(['10.0.0.30/32', '10.0.0.31/32'], $alice['discovery_evidence']);
        $this->assertSame(1, $result['summary']['TOTAL READY']);
        $this->assertSame($before, [Customer::count(), CustomerConnection::count(), DiscoveredNetworkResource::count(), DeviceObservation::count()]);
    }

    public function test_review_renders_complete_universe_and_only_ready_candidates_are_selectable(): void
    {
        [$tenant, $user, $router] = $this->fixture();
        $snapshot = $this->snapshot($tenant, $router);
        $this->resource($tenant, $router, $snapshot, 'Ready', '10.0.0.20/32');
        $this->resource($tenant, $router, $snapshot, 'Aggregate', '10.0.0.0/24');

        $response = $this->actingAs($user)->post(route('network.discovery.import.review'), [
            'candidates' => ['simple:'.$this->resourceId('Ready')],
        ]);

        $response->assertOk()
            ->assertSee('STATIC IP READY')
            ->assertSee('HOTSPOT READY')
            ->assertSee('NEEDS VALIDATION')
            ->assertSee('ALREADY ADOPTED')
            ->assertSee('DUPLICATE')
            ->assertSee('EXCLUDED')
            ->assertSee('TOTAL READY')
            ->assertSee('Aggregate')
            ->assertSee('Review / Confirm');
    }

    public function test_hotspot_canary_import_creates_one_connection_adopts_both_resources_and_links_exact_username_observations(): void
    {
        [$tenant, $user, $router] = $this->fixture();
        $snapshot = $this->snapshot($tenant, $router);
        $this->resourceWithData($tenant, $router, $snapshot, 'MAHLOR-hotspot -> 10.10.12.123', '10.10.12.123/32', [
            'hotspot_account' => ['username' => 'MAHLOR', 'profile' => 'mahlor'],
        ]);
        $firstResourceId = $this->lastResourceId;
        $this->resourceWithData($tenant, $router, $snapshot, 'MAHLOR-hotspot -> 10.10.12.181', '10.10.12.181/32', [
            'hotspot_account' => ['username' => 'MAHLOR', 'profile' => 'mahlor'],
        ]);
        $secondResourceId = $this->lastResourceId;
        $observationIds = [];
        foreach ([['10.10.12.123', 'AA:AA:AA:AA:AA:01'], ['10.10.12.181', 'AA:AA:AA:AA:AA:02']] as [$ip, $mac]) {
            $observationIds[] = DeviceObservation::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'source' => 'hotspot_active',
                'network_identity' => 'mahlor',
                'ip_address' => $ip,
                'mac_address' => $mac,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'metadata' => [],
            ])->id;
        }

        $candidate = collect(app(DiscoveryCustomerImportService::class)->candidates($tenant->id)['candidates'])
            ->firstWhere('network_identity', 'MAHLOR');
        $this->assertSame('READY', $candidate['state']);

        $created = app(DiscoveryCustomerImportService::class)->import($user, [[
            ...$candidate,
            'names' => [$candidate['key'] => 'MAHLOR'],
        ]]);

        $this->assertCount(1, $created);
        $customer = $created[0]->fresh();
        $connection = $customer->connections()->firstOrFail();
        $this->assertSame('MAHLOR', $customer->name);
        $this->assertSame('hotspot', $connection->access_mode);
        $this->assertSame('hotspot', $connection->network_mechanism);
        $this->assertSame('MAHLOR', $connection->metadata['network_identity']);
        $this->assertSame([$firstResourceId, $secondResourceId], DiscoveredNetworkResource::where('customer_connection_id', $connection->id)->orderBy('id')->pluck('id')->all());
        $this->assertSame(['ADOPTED', 'ADOPTED'], DiscoveredNetworkResource::whereIn('id', [$firstResourceId, $secondResourceId])->orderBy('id')->pluck('management_state')->all());
        $this->assertSame([$connection->id, $connection->id], DeviceObservation::whereIn('id', $observationIds)->orderBy('id')->pluck('customer_connection_id')->all());
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, CustomerConnection::count());
        $this->assertSame(2, DeviceObservation::count());
    }

    public function test_static_import_links_only_matching_arp_observation(): void
    {
        [$tenant, $user, $router] = $this->fixture();
        $snapshot = $this->snapshot($tenant, $router);
        $this->resource($tenant, $router, $snapshot, 'Static', '10.0.0.20/32');
        $candidate = collect(app(DiscoveryCustomerImportService::class)->candidates($tenant->id)['candidates'])->firstWhere('network_identity', '10.0.0.20/32');
        $matching = DeviceObservation::create(['tenant_id'=>$tenant->id,'router_id'=>$router->id,'source'=>'arp','network_identity'=>'10.0.0.20','ip_address'=>'10.0.0.20','mac_address'=>'AA:AA:AA:AA:AA:01','first_seen_at'=>now(),'last_seen_at'=>now(),'metadata'=>[]]);
        $unrelated = DeviceObservation::create(['tenant_id'=>$tenant->id,'router_id'=>$router->id,'source'=>'arp','network_identity'=>'10.0.0.21','ip_address'=>'10.0.0.21','mac_address'=>'AA:AA:AA:AA:AA:02','first_seen_at'=>now(),'last_seen_at'=>now(),'metadata'=>[]]);
        app(DiscoveryCustomerImportService::class)->import($user, [[...$candidate, 'names'=>[$candidate['key']=>'Static']]]);
        $connectionId = CustomerConnection::latest('id')->value('id');
        $this->assertSame($connectionId, $matching->fresh()->customer_connection_id);
        $this->assertNull($unrelated->fresh()->customer_connection_id);
    }

    private int $lastResourceId;

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();

        return [$tenant, $user, $router];
    }

    private function snapshot(Tenant $tenant, Router $router): NetworkDiscoverySnapshot
    {
        return NetworkDiscoverySnapshot::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'provider' => 'fake',
            'status' => 'success',
            'discovered_at' => now(),
            'summary' => [],
            'snapshot' => [],
        ]);
    }

    private function resource(Tenant $tenant, Router $router, NetworkDiscoverySnapshot $snapshot, string $name, string $target, string $state = 'DISCOVERED', ?int $connectionId = null): void
    {
        $resource = DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'discovery_snapshot_id' => $snapshot->id,
            'customer_connection_id' => $connectionId,
            'resource_type' => 'queue',
            'external_ref' => $name,
            'name' => $name,
            'management_state' => $state,
            'fingerprint' => hash('sha256', $name.$target),
            'normalized_data' => ['name' => $name, 'target' => $target],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $this->lastResourceId = $resource->id;
    }

    private function resourceWithData(Tenant $tenant, Router $router, NetworkDiscoverySnapshot $snapshot, string $name, string $target, array $extra): void
    {
        $resource = DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'discovery_snapshot_id' => $snapshot->id,
            'resource_type' => 'queue',
            'external_ref' => $name,
            'name' => $name,
            'management_state' => 'DISCOVERED',
            'fingerprint' => hash('sha256', $name.$target),
            'normalized_data' => ['name' => $name, 'target' => $target] + $extra,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $this->lastResourceId = $resource->id;
    }

    private function resourceId(string $name): int
    {
        return DiscoveredNetworkResource::where('name', $name)->value('id');
    }
}