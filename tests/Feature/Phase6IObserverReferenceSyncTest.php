<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\NetworkAgentService;
use App\Services\Network\ObserverReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase6IObserverReferenceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_agent_can_sync_typed_observer_metadata_without_secret_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        [$agent, $token] = app(NetworkAgentService::class)->enroll($tenant, 'Sync Agent');

        $response = $this->withToken($token)->postJson('/api/v1/agent/observer-references/sync', [
            'tenant_ref' => (string) $tenant->id, 'router_ref' => (string) $router->id, 'agent_ref' => $agent->identifier,
            'installation_id' => 'installation-1', 'credential_ref' => 'cred-observer-1', 'purpose' => 'OBSERVER', 'version' => 1, 'status' => 'ACTIVE',
        ])->assertOk();

        $response->assertJsonPath('migration_state', 'REFERENCE_SYNCED');
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
        $this->assertStringNotContainsString('secret', strtolower($response->getContent()));
        $this->assertSame('REFERENCE_SYNCED', $router->fresh()->observer_migration_state);
    }

    public function test_wrong_agent_tenant_operator_and_secret_bearing_payloads_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        [$agent, $token] = app(NetworkAgentService::class)->enroll($otherTenant, 'Wrong Agent');

        $base = ['tenant_ref' => (string) $tenant->id, 'router_ref' => (string) $router->id, 'agent_ref' => $agent->identifier, 'installation_id' => 'installation-1', 'credential_ref' => 'cred-1', 'purpose' => 'OBSERVER', 'version' => 1, 'status' => 'ACTIVE'];
        $this->withToken($token)->postJson('/api/v1/agent/observer-references/sync', $base)->assertForbidden();
        [$validAgent, $validToken] = app(NetworkAgentService::class)->enroll($tenant, 'Valid Agent');
        $validBase = array_replace($base, ['agent_ref' => $validAgent->identifier]);
        $this->withToken($validToken)->postJson('/api/v1/agent/observer-references/sync', array_replace($validBase, ['purpose' => 'OPERATOR']))->assertStatus(422);
        $this->withToken($validToken)->postJson('/api/v1/agent/observer-references/sync', array_replace($validBase, ['password' => 'never']))->assertStatus(422);
    }

    public function test_sync_does_not_switch_the_selected_reference_and_switch_is_explicitly_audited(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        [$agent] = app(NetworkAgentService::class)->enroll($tenant, 'Lifecycle Agent');
        $service = app(ObserverReferenceService::class);

        $v1 = ['tenant_ref' => (string) $tenant->id, 'router_ref' => (string) $router->id, 'agent_ref' => $agent->identifier, 'installation_id' => 'installation-1', 'credential_ref' => 'cred-observer', 'purpose' => 'OBSERVER', 'version' => 1, 'status' => 'ACTIVE'];
        $service->sync($agent, $v1);
        $service->bind($router->fresh(), $agent, $v1);
        $service->activate($router->fresh(), $agent);

        $v2 = array_replace($v1, ['version' => 2]);
        $service->sync($agent, $v2);
        $this->assertSame(1, $router->fresh()->observer_credential_version);
        $this->assertSame(2, $router->fresh()->observer_synced_credential_version);
        $this->assertSame(ObserverReferenceService::LOCAL_OBSERVER_ACTIVE, $router->fresh()->observer_migration_state);

        $service->switchReference($router->fresh(), $agent, $v2);
        $this->assertSame(2, $router->fresh()->observer_credential_version);
        $this->assertSame('OBSERVER_REFERENCE_SWITCHED', DB::table('network_agent_events')->latest('id')->value('type'));
    }
}
