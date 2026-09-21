<?php

namespace Tests\Feature;

use App\Models\NetworkAgent;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class Phase6IObserverReferenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_requires_confirmation_and_uses_existing_service_lifecycle(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $agent = NetworkAgent::create([
            'tenant_id' => $tenant->id,
            'identifier' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Acceptance Agent',
            'token_id' => 'agent-token-id',
            'token_hash' => 'not-used-in-command-test',
        ]);
        $reference = [
            'tenant_ref' => (string) $tenant->id,
            'router_ref' => (string) $router->id,
            'agent_ref' => $agent->identifier,
            'installation_id' => '550e8400-e29b-41d4-a716-446655440001',
            'credential_ref' => '2308170a-0dfc-452f-9088-ded13340fc10',
            'purpose' => 'OBSERVER',
            'version' => 1,
            'status' => 'ACTIVE',
        ];
        $router->forceFill([
            'observer_synced_agent_ref' => $reference['agent_ref'],
            'observer_synced_installation_id' => $reference['installation_id'],
            'observer_synced_credential_ref' => $reference['credential_ref'],
            'observer_synced_credential_purpose' => $reference['purpose'],
            'observer_synced_credential_version' => $reference['version'],
            'observer_synced_credential_status' => $reference['status'],
            'observer_reference_synced_at' => now(),
            'observer_migration_state' => 'REFERENCE_SYNCED',
        ])->save();

        $args = [
            'tenant' => (string) $tenant->id,
            'router' => (string) $router->id,
            'agent' => $agent->identifier,
            'credential' => $reference['credential_ref'],
            'installation' => $reference['installation_id'],
            'version' => '1',
        ];
        $this->assertSame(1, Artisan::call('network-agents:bind-observer', $args));
        $this->assertSame('REFERENCE_SYNCED', $router->fresh()->observer_migration_state);

        $confirmation = implode(' ', ['BIND_OBSERVER', ...array_values($args)]);
        $this->assertSame(0, Artisan::call('network-agents:bind-observer', $args + ['--confirm' => $confirmation]));
        $this->assertSame('LOCAL_OBSERVER_ACTIVE', $router->fresh()->observer_migration_state);
    }
}