<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\NetworkAgentFleetService;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6FAgentFleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_and_detail_are_tenant_scoped_without_secrets(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        [$agent, $token] = app(NetworkAgentService::class)->enroll($tenant, 'Local Agent');
        [$foreign] = app(NetworkAgentService::class)->enroll(Tenant::factory()->create(), 'Foreign Agent');
        $summary = app(NetworkAgentFleetService::class)->summary($tenant->id);
        $this->assertSame(['TOTAL' => 1, 'ONLINE' => 0, 'STALE' => 0, 'OFFLINE' => 1, 'UNSUPPORTED' => 1, 'PENDING' => 0, 'RUNNING' => 0, 'FAILED' => 0], $summary);
        $this->actingAs($user)->get('/network/agents')->assertOk()->assertSee('Local Agent')->assertDontSee('Foreign Agent')->assertDontSee($token)->assertDontSee($agent->token_hash);
        $this->get('/network/agents/'.$agent->id)->assertOk()->assertSee('AGENT_ENROLLED')->assertDontSee($token)->assertDontSee($agent->token_hash);
        $this->get('/network/agents/'.$foreign->id)->assertNotFound();
    }
}
