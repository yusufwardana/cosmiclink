<?php

namespace Tests\Feature;

use App\Models\NetworkAgent;
use App\Models\Tenant;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6IAgentTokenRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotation_preserves_existing_agent_identity_and_invalidates_old_token(): void
    {
        $tenant = Tenant::factory()->create();
        [$agent, $oldToken] = app(NetworkAgentService::class)->enroll($tenant, 'Phase 6I Agent');

        [$rotated, $newToken] = app(NetworkAgentService::class)->rotate($agent);

        $this->assertSame($agent->id, $rotated->id);
        $this->assertSame($agent->identifier, $rotated->identifier);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertNull(app(NetworkAgentService::class)->authenticate($oldToken));
        $this->assertSame($agent->id, app(NetworkAgentService::class)->authenticate($newToken)?->id);
        $this->assertSame(1, NetworkAgent::where('identifier', $agent->identifier)->count());
    }
}