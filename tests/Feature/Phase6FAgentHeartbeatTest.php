<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase6FAgentHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_is_bounded_server_timed_and_transition_only(): void
    {
        $this->travelTo(now()->startOfSecond());
        $service = app(NetworkAgentService::class);
        [$agent, $token] = $service->enroll(Tenant::factory()->create(), 'Agent');
        $payload = ['version' => '0.6.0', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1', 'shell'], 'go_runtime' => 'go1.27.0', 'os' => 'windows', 'architecture' => 'amd64', 'uptime_seconds' => 20, 'last_seen_at' => '2099-01-01', 'environment' => ['TOKEN' => 'secret-literal']];
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', $payload)->assertOk();
        $agent->refresh();
        $this->assertTrue($agent->last_seen_at->equalTo(now()));
        $this->assertSame(['discovery.routeros.readonly', 'jobs.lease.v1'], $agent->capabilities);
        $this->assertSame(['go_runtime' => 'go1.27.0', 'os' => 'windows', 'architecture' => 'amd64', 'uptime_seconds' => 20], $agent->metadata);
        $this->assertStringNotContainsString('secret-literal', $agent->toJson());
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', $payload)->assertOk();
        $this->assertSame(2, DB::table('network_agent_events')->count());
        $this->travel(300)->seconds();
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', $payload)->assertOk();
        $this->assertSame(['AGENT_ENROLLED', 'AGENT_ONLINE', 'AGENT_OFFLINE', 'AGENT_RECOVERED'], DB::table('network_agent_events')->orderBy('id')->pluck('type')->all());
        $this->withToken($token)->postJson('/api/v1/agent/heartbeat', ['capabilities' => array_fill(0, 17, 'shell')])->assertUnprocessable();
    }
}
