<?php

namespace Tests\Feature;

use App\Models\NetworkAgent;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\NetworkAgentCompatibilityService;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6FAgentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_versions_and_explicit_legacy_boundary(): void
    {
        $policy = app(NetworkAgentCompatibilityService::class);
        foreach (['0.6.0' => 'CURRENT', '0.5.0' => 'UPDATE_AVAILABLE', '0.4.9' => 'UNSUPPORTED', '0.6.0-rc.1' => 'UPDATE_AVAILABLE', 'garbage' => 'UNSUPPORTED', 'phase-6e' => 'UPDATE_AVAILABLE'] as $version => $expected) {
            $agent = new NetworkAgent(['version' => $version, 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
            $this->assertSame($expected, $policy->compatibility($agent));
        }
        $legacy = new NetworkAgent(['version' => 'phase-6e', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
        $this->assertTrue($policy->canDiscover($legacy));
        $this->assertFalse($policy->canDiscover($legacy, true));
        $this->assertFalse($policy->canDiscover(new NetworkAgent(['version' => '0.6.0'])));
    }

    public function test_ineligible_agents_cannot_claim_or_receive_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $service = app(NetworkAgentService::class);
        [$agent] = $service->enroll($tenant, 'Agent');
        $agent->update(['version' => '0.6.0', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1'], 'last_seen_at' => now()]);
        $job = $service->createDiscoveryJob($router, $agent, null);
        foreach ([['last_seen_at' => now()->subSeconds(90)], ['last_seen_at' => now()->subSeconds(300)], ['last_seen_at' => now(), 'version' => '0.1.0'], ['version' => '0.6.0', 'capabilities' => []]] as $change) {
            $agent->update($change);
            $this->assertNull($service->claim($agent));
            $this->assertSame('PENDING', $job->fresh()->status);
        }
        $this->expectException(\InvalidArgumentException::class);
        $service->createDiscoveryJob($router, $agent, null);
    }
}
