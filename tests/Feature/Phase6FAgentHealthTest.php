<?php

namespace Tests\Feature;

use App\Models\NetworkAgent;
use App\Services\Network\NetworkAgentHealthService;
use Tests\TestCase;

class Phase6FAgentHealthTest extends TestCase
{
    public function test_health_boundaries_are_derived_from_server_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = app(NetworkAgentHealthService::class);
        foreach ([0 => 'ONLINE', 89 => 'ONLINE', 90 => 'STALE', 299 => 'STALE', 300 => 'OFFLINE'] as $age => $expected) {
            $agent = new NetworkAgent(['last_seen_at' => now()->subSeconds($age)]);
            $this->assertSame($expected, $policy->health($agent));
            $this->assertSame($expected === 'ONLINE', $agent->isOnline());
        }
        $this->assertSame('OFFLINE', $policy->health(new NetworkAgent));
    }

    public function test_invalid_threshold_order_is_rejected(): void
    {
        config(['network_agents.stale_seconds' => 30]);
        $this->expectException(\InvalidArgumentException::class);
        app(NetworkAgentHealthService::class)->health(new NetworkAgent);
    }

    public function test_invalid_lease_renewal_is_rejected(): void
    {
        config(['network_agents.renewal_seconds' => 120]);
        $this->expectException(\InvalidArgumentException::class);
        app(NetworkAgentHealthService::class)->health(new NetworkAgent);
    }
}
