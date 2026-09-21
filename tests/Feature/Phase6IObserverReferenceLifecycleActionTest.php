<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\NetworkAgent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6IObserverReferenceLifecycleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_operator_can_bind_then_activate_a_synchronized_observer_reference(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => 'admin']);
        $router = Router::factory()->for($tenant)->create();

        NetworkAgent::create([
            'tenant_id' => $tenant->id,
            'identifier' => '550e8400-e29b-41d4-a716-446655440000',
            'name' => 'Acceptance Agent',
            'token_id' => 'agent-token-id',
            'token_hash' => 'not-used-in-this-action-test',
        ]);

        $reference = [
            'agent_ref' => '550e8400-e29b-41d4-a716-446655440000',
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
            'observer_migration_state' => 'REFERENCE_SYNCED',
            'observer_reference_synced_at' => now(),
        ])->save();

        $this->actingAs($user)->postJson('/routers/'.$router->id.'/observer-reference/bind', $reference)
            ->assertOk()
            ->assertJsonPath('migration_state', 'LOCAL_OBSERVER_READY');

        $this->assertSame('LOCAL_OBSERVER_READY', $router->fresh()->observer_migration_state);

        $this->actingAs($user)->postJson('/routers/'.$router->id.'/observer-reference/activate', [
            'agent_ref' => $reference['agent_ref'],
        ])->assertOk()->assertJsonPath('migration_state', 'LOCAL_OBSERVER_ACTIVE');

        $this->assertSame('LOCAL_OBSERVER_ACTIVE', $router->fresh()->observer_migration_state);
    }
}