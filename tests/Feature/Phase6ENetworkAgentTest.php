<?php

namespace Tests\Feature;

use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAgentJob;
use App\Models\NetworkDiscoveryAudit;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase6ENetworkAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_heartbeat_claim_a_tenant_job_and_idempotently_persist_a_sanitized_discovery_result(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();
        $router->setPassword('router-secret');
        $router->save();
        [$agent, $token] = app(NetworkAgentService::class)->enroll($tenant, 'LAN Agent');
        $agent = app(NetworkAgentService::class)->heartbeat($agent, ['version' => '6e-test', 'capabilities' => ['discovery.routeros.readonly']]);
        $job = app(NetworkAgentService::class)->createDiscoveryJob($router, $agent, $user);

        $this->assertNotSame($token, $agent->token_hash);
        $this->assertStringNotContainsString('router-secret', json_encode($job->fresh()->toArray()));
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', ['version' => '6e-test', 'capabilities' => ['discovery.routeros.readonly']])->assertOk();
        $this->assertNotNull($agent->fresh()->last_seen_at);
        $claim = $this->withToken($token)->postJson('/api/v1/agent/jobs/claim')->assertOk()->json();
        $this->assertSame((string) $job->id, (string) $claim['job']['id']);
        $this->assertSame('router-secret', $claim['job']['connection']['password']);
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $this->successPayload('agent-secret'))->assertOk();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $this->successPayload('agent-secret'))->assertOk();

        $this->assertSame(NetworkAgentJob::SUCCEEDED, $job->fresh()->status);
        $this->assertDatabaseCount('network_discovery_snapshots', 1);
        $this->assertDatabaseCount('discovered_network_resources', 1);
        $this->assertDatabaseCount('network_discovery_audits', 1);
        $resource = DiscoveredNetworkResource::firstOrFail();
        $this->assertSame('DISCOVERED', $resource->management_state);
        $this->assertSame('DISCOVERY', NetworkDiscoveryAudit::firstOrFail()->action);
        $this->assertStringNotContainsString('agent-secret', json_encode($resource->toArray()));
        $this->assertStringNotContainsString('router-secret', json_encode($job->fresh()->toArray()));
    }

    public function test_migrated_discovery_job_claim_contains_no_raw_router_credentials(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $router = Router::factory()->for($tenant)->create();
        $router->setPassword('legacy-router-secret');
        $router->save();
        [$agent, $token] = app(NetworkAgentService::class)->enroll($tenant, 'Migrated Agent');
        $agent = app(NetworkAgentService::class)->heartbeat($agent, ['version' => '0.6.0', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
        $router->forceFill([
            'observer_agent_ref' => $agent->identifier,
            'observer_installation_id' => 'installation-1',
            'observer_credential_ref' => 'cred-1',
            'observer_credential_purpose' => 'OBSERVER',
            'observer_credential_version' => 1,
            'observer_credential_status' => 'ACTIVE',
            'observer_migration_state' => 'LOCAL_OBSERVER_ACTIVE',
        ])->save();
        $job = app(NetworkAgentService::class)->createDiscoveryJob($router, $agent, $user);

        $claim = $this->withToken($token)->postJson('/api/v1/agent/jobs/claim')->assertOk()->json();

        $encoded = json_encode($claim);
        $this->assertStringNotContainsString('legacy-router-secret', $encoded);
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertSame((string) $agent->identifier, $claim['job']['agent_ref']);
        $this->assertSame((string) $router->tenant_id, $claim['job']['tenant_ref']);
        $this->assertSame((string) $router->id, $claim['job']['router_ref']);
        $this->assertSame($job->id, $claim['job']['id']);
    }

    public function test_invalid_or_cross_tenant_agent_cannot_claim_job_and_a_job_is_claimed_once(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $router = Router::factory()->for($tenantA)->create();
        $user = User::factory()->for($tenantA)->create();
        [$agentA, $tokenA] = app(NetworkAgentService::class)->enroll($tenantA, 'A');
        [$agentB, $tokenB] = app(NetworkAgentService::class)->enroll($tenantB, 'B');
        $agentA = app(NetworkAgentService::class)->heartbeat($agentA, ['version' => '6e-test', 'capabilities' => ['discovery.routeros.readonly']]);
        $job = app(NetworkAgentService::class)->createDiscoveryJob($router, $agentA, $user);

        $this->withToken('bad-token')->postJson('/api/v1/agent/jobs/claim')->assertUnauthorized();
        $this->withToken($tokenB)->postJson('/api/v1/agent/jobs/claim')->assertNoContent();
        $this->withToken($tokenA)->postJson('/api/v1/agent/jobs/claim')->assertOk();
        $this->withToken($tokenA)->postJson('/api/v1/agent/jobs/claim')->assertNoContent();
        $this->assertSame(NetworkAgentJob::RUNNING, $job->fresh()->status);
    }

    public function test_only_discover_router_jobs_are_supported_and_failure_is_completed_without_secrets(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $router->setPassword('router-secret');
        $router->save();
        $user = User::factory()->for($tenant)->create();
        [$agent, $token] = app(NetworkAgentService::class)->enroll($tenant, 'LAN');
        $agent = app(NetworkAgentService::class)->heartbeat($agent, ['version' => '6e-test', 'capabilities' => ['discovery.routeros.readonly']]);
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(NetworkAgentService::class)->createJob($router, $agent, $user, 'EXECUTE');
        } finally {
            $job = app(NetworkAgentService::class)->createDiscoveryJob($router, $agent, $user);
            $this->withToken($token)->postJson('/api/v1/agent/jobs/claim')->assertOk();
            $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', ['success' => false, 'code' => 'ROUTER_UNAVAILABLE', 'message' => 'Router unavailable', 'credential' => ['password' => 'agent-secret']])->assertOk();

            $job->refresh();
            $this->assertSame(NetworkAgentJob::FAILED, $job->status);
            $this->assertSame('ROUTER_UNAVAILABLE', $job->error_code);
            $this->assertStringNotContainsString('agent-secret', json_encode($job->toArray()));
            $this->assertStringNotContainsString('router-secret', json_encode($job->toArray()));
        }
    }

    public function test_agent_job_schema_has_no_payload_or_credential_columns_and_discovery_cannot_manage_resources(): void
    {
        $columns = Schema::getColumnListing('network_agent_jobs');

        $this->assertNotContains('payload', $columns);
        $this->assertNotContains('credential', $columns);
        $this->assertNotContains('credentials', $columns);
        $this->assertNotContains('password', $columns);
        $this->assertSame(0, DiscoveredNetworkResource::where('management_state', 'MANAGED')->count());
    }

    private function successPayload(string $secret): array
    {
        return ['success' => true, 'provider' => 'fake', 'discovered_at' => '2026-09-18T00:00:00Z', 'code' => 'DISCOVERY_COMPLETE', 'message' => 'Read-only discovery complete', 'snapshot' => ['device' => ['name' => 'CORE-01'], 'profiles' => [['external_ref' => '10m', 'name' => '10M', 'token' => $secret]], 'accounts' => [], 'address_pools' => [], 'queues' => []]];
    }
}
