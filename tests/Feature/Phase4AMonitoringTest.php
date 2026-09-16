<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\HealthObservation;
use App\Models\InternetPackage;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\NetworkAccount;
use App\Models\OutageIncident;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Monitoring\Contracts\MonitoringDriver;
use App\Services\Monitoring\FakeMonitoringDriver;
use App\Services\Monitoring\HealthState;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase4AMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_driver_observes_all_router_states_deterministically(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $driver = app(FakeMonitoringDriver::class);

        foreach ([HealthState::ONLINE, HealthState::DEGRADED, HealthState::OFFLINE] as $state) {
            $router->update(['monitoring_state' => $state->value]);
            $observation = $driver->observeRouter($router->fresh());
            $this->assertSame($state, $observation->state);
        }

        $first = $driver->observeRouter($router->fresh());
        $second = $driver->observeRouter($router->fresh());
        $this->assertSame($first->state, $second->state);
        $this->assertSame($first->latencyMs, $second->latencyMs);
        $this->assertSame($first->packetLossPercent, $second->packetLossPercent);
    }

    public function test_fake_driver_observes_connection_states_and_unknown(): void
    {
        [$tenant] = $this->tenantWithUser();
        $connection = $this->connection($tenant);
        $driver = app(MonitoringDriver::class);

        foreach ([HealthState::ONLINE, HealthState::OFFLINE, HealthState::UNKNOWN] as $state) {
            $connection->update(['monitoring_state' => $state->value]);
            $observation = $driver->observeConnection($connection->fresh());
            $this->assertSame($state, $observation->state);
        }
    }

    public function test_monitoring_persists_history_and_latest_state_without_mutating_lifecycle(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant);
        $invoice = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'unpaid']);
        $account = $connection->networkAccount;
        $connection->update(['status' => 'active', 'monitoring_state' => HealthState::OFFLINE->value]);

        $first = app(MonitoringService::class)->observeConnection($connection, $user);
        $connection->update(['monitoring_state' => HealthState::ONLINE->value]);
        $second = app(MonitoringService::class)->observeConnection($connection, $user);

        $this->assertSame(HealthState::ONLINE->value, $second->health_state);
        $this->assertSame(2, HealthObservation::where('subject_id', $connection->id)->count());
        $this->assertSame($first->id, HealthObservation::where('subject_id', $connection->id)->oldest('id')->value('id'));
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_tenant_scoped_monitoring_and_dashboard_do_not_leak(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('Tenant A');
        [$tenantB] = $this->tenantWithUser('Tenant B');
        $routerB = Router::factory()->for($tenantB)->create(['name' => 'Tenant B Router']);
        HealthObservation::factory()->for($tenantB)->create(['subject_type' => 'router', 'subject_id' => $routerB->id]);

        $this->actingAs($userA)->get(route('monitoring.index'))->assertOk()->assertDontSee('Tenant B Router');
        $this->actingAs($userA)->post(route('monitoring.routers.observe', $routerB))->assertForbidden();
        $this->actingAs($userA)->post(route('monitoring.routers.simulation', $routerB), ['state' => 'offline'])->assertForbidden();
    }

    public function test_bulk_monitoring_is_tenant_scoped_and_simulation_controls_are_safe(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $connection = $this->connection($tenant, $router);
        config(['monitoring.simulation' => true]);

        $this->actingAs($user)->post(route('monitoring.check'))->assertRedirect();
        $this->assertDatabaseHas('health_observations', ['tenant_id' => $tenant->id, 'subject_type' => 'router', 'subject_id' => $router->id]);
        $this->assertDatabaseHas('health_observations', ['tenant_id' => $tenant->id, 'subject_type' => 'connection', 'subject_id' => $connection->id]);

        $this->actingAs($user)->post(route('monitoring.routers.simulation', $router), ['state' => 'offline'])->assertRedirect();
        $this->assertSame('offline', $router->fresh()->monitoring_state);
    }

    public function test_monitoring_simulation_controls_are_unavailable_when_fake_driver_is_disabled(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        config(['monitoring.driver' => 'unsupported', 'monitoring.simulation' => false]);

        $this->actingAs($user)->post(route('monitoring.routers.simulation', $router), ['state' => 'offline'])->assertForbidden();
    }

    public function test_observations_never_store_router_secrets(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $router->setPassword('router-secret');
        $router->save();

        app(MonitoringService::class)->observeRouter($router, $user);

        $this->assertStringNotContainsString('router-secret', json_encode(HealthObservation::latest()->first()->toArray()));
    }

    public function test_shared_offline_connections_create_one_incident_and_recover(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $connections = collect(range(1, 3))->map(fn () => $this->connection($tenant, $router));
        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::OFFLINE->value]));

        app(MonitoringService::class)->observeTenant($tenant->id, $user);
        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $this->assertSame(1, OutageIncident::where('router_id', $router->id)->count());
        $incident = OutageIncident::where('router_id', $router->id)->firstOrFail();
        $this->assertSame(3, $incident->affectedConnections()->count());
        $this->assertSame('active', $connections->first()->fresh()->status);
        $this->assertSame('active', $connections->first()->networkAccount->fresh()->status);

        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::ONLINE->value]));
        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);
    }

    public function test_below_threshold_does_not_create_an_incident(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        collect(range(1, 2))->each(fn () => $this->connection($tenant, $router)->update(['monitoring_state' => HealthState::OFFLINE->value]));

        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $this->assertDatabaseCount('outage_incidents', 0);
    }

    public function test_unrelated_routers_have_separate_incidents_and_acknowledgement_is_tenant_safe(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $routerA = Router::factory()->for($tenant)->create(['name' => 'Router A']);
        $routerB = Router::factory()->for($tenant)->create(['name' => 'Router B']);
        foreach ([$routerA, $routerB] as $router) {
            collect(range(1, 3))->each(fn () => $this->connection($tenant, $router)->update(['monitoring_state' => HealthState::OFFLINE->value]));
        }
        app(MonitoringService::class)->observeTenant($tenant->id, $user);
        $this->assertSame(2, OutageIncident::where('tenant_id', $tenant->id)->count());

        $incident = OutageIncident::where('router_id', $routerA->id)->firstOrFail();
        $this->actingAs($user)->post(route('monitoring.incidents.acknowledge', $incident))->assertRedirect();
        $this->assertSame('acknowledged', $incident->fresh()->status);
    }

    public function test_tenant_cannot_view_another_tenant_incident(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('Tenant A');
        [$tenantB] = $this->tenantWithUser('Tenant B');
        $incident = OutageIncident::factory()->for($tenantB)->create();

        $this->actingAs($userA)->get(route('monitoring.incidents.show', $incident))->assertForbidden();
        $this->actingAs($userA)->post(route('monitoring.incidents.acknowledge', $incident))->assertForbidden();
    }

    public function test_incident_notifications_target_each_affected_customer_once_and_resolution_notifies_once(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $connections = collect(range(1, 3))->map(fn () => $this->connection($tenant, $router));
        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::OFFLINE->value]));

        app(MonitoringService::class)->observeTenant($tenant->id, $user);
        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $incident = OutageIncident::where('router_id', $router->id)->firstOrFail();
        $this->assertSame(3, MessageLog::where('outage_incident_id', $incident->id)->where('template', 'outage_detected')->count());
        $this->assertSame(3, MessageLog::where('outage_incident_id', $incident->id)->where('template', 'outage_detected')->where('status', 'sent')->count());

        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::ONLINE->value]));
        app(MonitoringService::class)->observeTenant($tenant->id, $user);
        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $this->assertSame(3, MessageLog::where('outage_incident_id', $incident->id)->where('template', 'outage_resolved')->count());
    }

    public function test_invalid_phone_and_provider_failure_are_audited_without_changing_incident_truth(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $connections = collect(range(1, 3))->map(fn ($index) => $this->connection($tenant, $router, ['phone' => $index === 1 ? null : ($index === 2 ? '081299999999' : '081234567890')]));
        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::OFFLINE->value]));

        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $incident = OutageIncident::where('router_id', $router->id)->firstOrFail();
        $this->assertSame('detected', $incident->status);
        $this->assertDatabaseHas('message_logs', ['outage_incident_id' => $incident->id, 'template' => 'outage_detected', 'status' => 'skipped']);
        $this->assertDatabaseHas('message_logs', ['outage_incident_id' => $incident->id, 'template' => 'outage_detected', 'status' => 'failed']);
        $this->assertSame('active', $connections->first()->fresh()->status);
        $this->assertSame('active', $connections->first()->networkAccount->fresh()->status);
    }

    public function test_resolved_incident_allows_a_later_independent_outage(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $connections = collect(range(1, 3))->map(fn () => $this->connection($tenant, $router));
        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::OFFLINE->value]));
        app(MonitoringService::class)->observeTenant($tenant->id, $user);
        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::ONLINE->value]));
        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $connections->each(fn (CustomerConnection $connection) => $connection->update(['monitoring_state' => HealthState::OFFLINE->value]));
        app(MonitoringService::class)->observeTenant($tenant->id, $user);

        $this->assertSame(2, OutageIncident::where('router_id', $router->id)->count());
        $this->assertSame(6, MessageLog::where('template', 'outage_detected')->count());
    }

    private function connection(Tenant $tenant, ?Router $router = null, array $customerAttributes = []): CustomerConnection
    {
        $customer = Customer::factory()->for($tenant)->create($customerAttributes + ['phone' => '081234567890']);
        $package = InternetPackage::factory()->for($tenant)->create();
        $router ??= Router::factory()->for($tenant)->create();
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => $customer->customer_code, 'profile' => $package->network_profile, 'status' => 'active']);

        return CustomerConnection::factory()->for($customer)->for($package)->for($router)->create(['tenant_id' => $tenant->id, 'network_account_id' => $account->id, 'status' => 'active', 'provisioned_at' => now()]);
    }

    private function tenantWithUser(string $name = 'DemoNet ISP'): array
    {
        $tenant = Tenant::factory()->create(['name' => $name]);

        return [$tenant, User::factory()->for($tenant)->create()];
    }
}
