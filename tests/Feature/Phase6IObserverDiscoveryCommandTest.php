<?php

namespace Tests\Feature;

use App\Models\NetworkAgent;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class Phase6IObserverDiscoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_requires_active_observer_and_confirmation_before_queueing_read_only_discovery(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $agent = NetworkAgent::create([
            'tenant_id' => $tenant->id,
            'identifier' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Acceptance Agent',
            'version' => '0.6.0',
            'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1'],
            'token_id' => 'agent-token-id',
            'token_hash' => 'not-used-in-command-test',
        ]);
        $router->forceFill([
            'observer_agent_ref' => $agent->identifier,
            'observer_credential_ref' => 'observer-ref',
            'observer_credential_purpose' => 'OBSERVER',
            'observer_credential_version' => 1,
            'observer_credential_status' => 'ACTIVE',
            'observer_migration_state' => 'LOCAL_OBSERVER_ACTIVE',
        ])->save();

        $args = ['tenant' => (string) $tenant->id, 'router' => (string) $router->id, 'agent' => $agent->identifier];
        $this->assertSame(1, Artisan::call('network-agents:discover-observer', $args));
        $this->assertDatabaseCount('network_agent_jobs', 0);

        $args['--confirm'] = implode(' ', ['DISCOVER_OBSERVER', ...array_values($args)]);
        $this->assertSame(0, Artisan::call('network-agents:discover-observer', $args));
        $this->assertDatabaseHas('network_agent_jobs', ['tenant_id' => $tenant->id, 'router_id' => $router->id, 'network_agent_id' => $agent->id, 'job_type' => 'DISCOVER_ROUTER', 'status' => 'PENDING']);
    }
}