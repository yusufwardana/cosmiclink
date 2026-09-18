<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase6FAgentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_attempt_cannot_renew_or_write_any_business_state(): void
    {
        $service = app(NetworkAgentService::class);
        $tenant = Tenant::factory()->create();
        [$agent, $token] = $service->enroll($tenant, 'Safety');
        $agent = $service->heartbeat($agent, ['version' => '0.6.0', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
        $job = $service->createDiscoveryJob(Router::factory()->for($tenant)->create(), $agent, null);
        $claim = $service->claim($agent)['job'];
        $old = ['attempt' => $claim['attempt'], 'fence' => $claim['fence']];
        $this->travel(121)->seconds();
        $service->recover();
        $agent = $service->heartbeat($agent, []);
        $service->claim($agent);
        $before = $job->fresh()->getAttributes();
        $events = DB::table('network_agent_events')->count();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/renew', $old)->assertConflict();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $old + ['success' => false])->assertConflict();
        $this->assertSame($before, $job->fresh()->getAttributes());
        $this->assertSame($events, DB::table('network_agent_events')->count());
        foreach (['network_discovery_snapshots', 'discovered_network_resources', 'network_discovery_audits'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_failure_diagnostics_are_bounded_and_foreign_results_are_forbidden(): void
    {
        $service = app(NetworkAgentService::class);
        $tenant = Tenant::factory()->create();
        [$agent, $token] = $service->enroll($tenant, 'Safety');
        $agent = $service->heartbeat($agent, ['version' => '0.6.0', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
        $job = $service->createDiscoveryJob(Router::factory()->for($tenant)->create(), $agent, null);
        $claim = $service->claim($agent)['job'];
        $payload = ['attempt' => $claim['attempt'], 'fence' => $claim['fence'], 'success' => false, 'code' => 'secret-literal', 'message' => 'Bearer secret-literal', 'provider' => 'secret-literal'];
        [, $foreignToken] = $service->enroll(Tenant::factory()->create(), 'Foreign');
        $this->withToken($foreignToken)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $payload)->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $payload)->assertOk();
        $this->assertSame('DISCOVERY_FAILED', $job->fresh()->error_code);
        $this->assertStringNotContainsString('secret-literal', $job->fresh()->toJson());
        $this->assertStringNotContainsString('secret-literal', json_encode(DB::table('network_agent_events')->get()));
    }
}
