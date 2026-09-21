<?php

namespace Tests\Feature;

use App\Models\NetworkAgent;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\AcceptanceRecoveryService;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class Phase6IAcceptanceRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private function manifest(): array
    {
        return [
            'tenant_ref' => '900000182', 'router_ref' => '900000122',
            'agent_ref' => (string) Str::uuid(), 'installation_id' => (string) Str::uuid(),
            'credential_ref' => (string) Str::uuid(), 'version' => 1,
            'token' => str_repeat('a', 32).'.'.Str::random(64),
        ];
    }

    public function test_recovery_preserves_identity_and_token_without_authorizing_writes(): void
    {
        $m = $this->manifest();
        $result = app(AcceptanceRecoveryService::class)->recover($m);
        $agent = app(NetworkAgentService::class)->authenticate($m['token']);
        $this->assertSame($m['agent_ref'], $agent->identifier);
        $this->assertSame((int) $m['tenant_ref'], $agent->tenant_id);
        $router = Router::findOrFail($result['router_id']);
        $this->assertSame('10.10.12.1', $router->host);
        $this->assertNull($router->observer_credential_ref);
        $this->assertNotSame('LOCAL_OBSERVER_ACTIVE', $router->observer_migration_state);
        $this->assertStringNotContainsString($m['token'], json_encode($result));
    }

    public function test_occupied_scope_is_refused_without_partial_creation(): void
    {
        $m = $this->manifest();
        $tenant = new Tenant(['name' => 'Existing', 'slug' => 'occupied-recovery']);
        $tenant->id = (int) $m['tenant_ref'];
        $tenant->save();
        try {
            app(AcceptanceRecoveryService::class)->recover($m);
            $this->fail('Occupied scope accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Recovery scope is not vacant.', $e->getMessage());
        }
        $this->assertFalse(NetworkAgent::where('identifier', $m['agent_ref'])->exists());
        $this->assertFalse(Router::whereKey($m['router_ref'])->exists());
    }

    public function test_command_refuses_the_test_database_before_reading_input(): void
    {
        $this->artisan('network-agents:recover-acceptance', ['--confirm' => 'RECOVER_PHASE6I_ACCEPTANCE'])
            ->assertExitCode(1);
    }

    public function test_recovery_refuses_enabled_mutations(): void
    {
        config(['network.mutations_enabled' => true]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Safe defaults required.');
        app(AcceptanceRecoveryService::class)->recover($this->manifest());
    }

    public function test_malformed_token_is_refused_without_echoing_it(): void
    {
        $m = $this->manifest();
        $m['token'] = 'do-not-expose-this';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid recovery manifest.');
        app(AcceptanceRecoveryService::class)->recover($m);
    }
}
