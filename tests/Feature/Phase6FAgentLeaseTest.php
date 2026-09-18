<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase6FAgentLeaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_renewal_recovery_fencing_and_duplicate_safety(): void
    {
        $this->travelTo(now()->startOfSecond());
        $service = app(NetworkAgentService::class);
        $tenant = Tenant::factory()->create();
        [$agent, $token] = $service->enroll($tenant, 'Agent');
        $agent = $service->heartbeat($agent, ['version' => '0.6.0', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
        $job = $service->createDiscoveryJob(Router::factory()->for($tenant)->create(), $agent, null);
        $claim = $this->withToken($token)->postJson('/api/v1/agent/jobs/claim')->assertOk()->json('job');
        $fence = ['attempt' => $claim['attempt'], 'fence' => $claim['fence']];
        $this->assertTrue($job->fresh()->lease_expires_at->equalTo(now()->addSeconds(120)));
        $this->travel(30)->seconds();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/renew', $fence)->assertOk();
        $this->assertTrue($job->fresh()->lease_expires_at->equalTo(now()->addSeconds(120)));
        [$other, $otherToken] = $service->enroll($tenant, 'Other');
        $this->withToken($otherToken)->postJson('/api/v1/agent/jobs/'.$job->id.'/renew', $fence)->assertForbidden();
        $this->travel(120)->seconds();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/renew', $fence)->assertConflict();
        $this->artisan('network-agents:recover-jobs')->assertSuccessful();
        $this->assertSame('PENDING', $job->fresh()->status);
        $events = DB::table('network_agent_events')->count();
        $this->artisan('network-agents:recover-jobs')->assertSuccessful();
        $this->assertSame($events, DB::table('network_agent_events')->count());
        $service->heartbeat($agent, []);
        $next = $this->withToken($token)->postJson('/api/v1/agent/jobs/claim')->assertOk()->json('job');
        $this->assertSame(2, $next['attempt']);
        $this->assertNotSame($claim['fence'], $next['fence']);
        $result = ['success' => true, 'provider' => 'fake', 'snapshot' => ['device' => ['name' => 'CORE'], 'profiles' => [], 'accounts' => [], 'address_pools' => [], 'queues' => []]];
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $fence + $result)->assertConflict();
        $this->assertDatabaseCount('network_discovery_snapshots', 0);
        $current = ['attempt' => $next['attempt'], 'fence' => $next['fence']];
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $current + $result)->assertOk();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $current + $result)->assertOk();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $fence + $result)->assertConflict();
        $this->withToken($token)->postJson('/api/v1/agent/jobs/'.$job->id.'/renew', $current)->assertConflict();
        $this->assertDatabaseCount('network_discovery_snapshots', 1);
        $this->assertDatabaseCount('network_discovery_audits', 1);
        $this->assertDatabaseCount('network_agent_job_attempts', 2);
    }

    public function test_legacy_retry_is_failed_and_maximum_attempts_is_enforced(): void
    {
        $service = app(NetworkAgentService::class);
        $tenant = Tenant::factory()->create();
        foreach (['phase-6e', '0.6.0'] as $version) {
            [$agent] = $service->enroll($tenant, $version);
            $agent = $service->heartbeat($agent, ['version' => $version, 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
            $job = $service->createDiscoveryJob(Router::factory()->for($tenant)->create(), $agent, null);
            $limit = $version === 'phase-6e' ? 1 : 3;
            for ($attempt = 1; $attempt <= $limit; $attempt++) {
                $agent = $service->heartbeat($agent, []);
                $this->assertNotNull($service->claim($agent));
                $this->travel(121)->seconds();
                $this->artisan('network-agents:recover-jobs')->assertSuccessful();
            }
            $this->assertSame('FAILED', $job->fresh()->status);
            $this->assertSame($limit === 1 ? 'INCOMPATIBLE_AGENT_RETRY' : 'MAX_ATTEMPTS_EXCEEDED', $job->fresh()->error_code);
        }
    }
}
