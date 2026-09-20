<?php

namespace Tests\Feature;

use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class Phase0FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_reach_authenticated_dashboard(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk()->assertSee('Dashboard');
    }

    public function test_guest_cannot_reach_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_tenant_a_cannot_view_update_or_delete_tenant_b_router(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('Tenant A');
        [$tenantB] = $this->tenantWithUser('Tenant B');
        $router = Router::factory()->for($tenantB)->create();

        $this->actingAs($userA)
            ->get(route('routers.show', $router))
            ->assertForbidden();

        $this->actingAs($userA)
            ->put(route('routers.update', $router), [
                'name' => 'Hijacked',
                'host' => 'router-b.local',
                'api_port' => 8728,
                'username' => 'admin',
            ])->assertForbidden();

        $this->actingAs($userA)
            ->delete(route('routers.destroy', $router))
            ->assertForbidden();

        $this->assertDatabaseHas('routers', ['id' => $router->id, 'tenant_id' => $tenantB->id]);
        $this->assertDatabaseMissing('routers', ['name' => 'Hijacked']);
    }

    public function test_router_credentials_are_encrypted_and_never_rendered(): void
    {
        [$tenant, $user] = $this->tenantWithUser();

        $this->actingAs($user)
            ->post(route('routers.store'), [
                'name' => 'Demo Router',
                'description' => 'Simulation router',
                'host' => 'placeholder.local',
                'api_port' => 8728,
                'username' => 'admin',
                'password' => 'router-secret',
            ])->assertRedirect(route('routers.index'));

        $router = Router::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertNotSame('router-secret', $router->getRawOriginal('encrypted_credentials'));
        $this->assertSame('router-secret', Crypt::decryptString($router->getRawOriginal('encrypted_credentials')));
        $this->actingAs($user)->get(route('routers.edit', $router))->assertDontSee('router-secret');
        $this->actingAs($user)->get(route('routers.show', $router))->assertDontSee('router-secret');
    }

    public function test_tenant_user_can_create_update_and_delete_own_router(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $this->actingAs($user)->post(route('routers.store'), [
            'name' => 'Router One', 'host' => 'router.local', 'api_port' => 8728, 'username' => 'admin',
        ])->assertRedirect(route('routers.index'));
        $router = Router::where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($user)->put(route('routers.update', $router), [
            'name' => 'Router Updated', 'host' => 'router.local', 'api_port' => 8729, 'username' => 'operator',
        ])->assertRedirect(route('routers.index'));
        $this->assertDatabaseHas('routers', ['id' => $router->id, 'name' => 'Router Updated', 'api_port' => 8729]);
        $this->actingAs($user)->delete(route('routers.destroy', $router))->assertRedirect(route('routers.index'));
        $this->assertDatabaseMissing('routers', ['id' => $router->id]);
    }

    public function test_user_can_test_fake_router_connection(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();

        $this->actingAs($user)
            ->post(route('routers.test', $router))
            ->assertRedirect()
            ->assertSessionHas('status', 'Connection test successful.');

        $this->assertNotNull($router->fresh()->last_seen_at);
        $this->assertDatabaseHas('network_operation_logs', [
            'router_id' => $router->id,
            'operation' => 'TEST_CONNECTION',
            'status' => 'success',
        ]);
    }

    public function test_tenant_a_cannot_execute_operation_on_tenant_b_router(): void
    {
        [, $userA] = $this->tenantWithUser('Tenant A');
        [$tenantB] = $this->tenantWithUser('Tenant B');
        $router = Router::factory()->for($tenantB)->create();

        $this->actingAs($userA)
            ->post(route('network.accounts.store'), [
                'router_id' => $router->id,
                'username' => 'cust001',
                'profile' => 'HOME-10M',
            ])->assertForbidden();

        $this->assertDatabaseCount('network_accounts', 0);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_fake_driver_can_create_disable_enable_change_profile_and_disconnect(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();

        $this->actingAs($user)->post(route('network.accounts.store'), [
            'router_id' => $router->id,
            'username' => 'cust001',
            'profile' => 'HOME-10M',
        ])->assertRedirect();

        $account = NetworkAccount::query()->where('username', 'cust001')->firstOrFail();
        $this->assertFalse(config('network.mutations_enabled'));
        $this->actingAs($user)->post(route('network.accounts.disable', $account))->assertRedirect();
        $this->actingAs($user)->post(route('network.accounts.enable', $account))->assertRedirect();
        $this->actingAs($user)->put(route('network.accounts.profile', $account), [
            'profile' => 'HOME-20M',
        ])->assertRedirect();
        $this->actingAs($user)->post(route('network.accounts.disconnect', $account))->assertRedirect();

        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame('HOME-20M', $account->fresh()->profile);
        $this->assertDatabaseCount('network_operation_logs', 5);
        $this->assertDatabaseHas('network_operation_logs', ['operation' => 'CREATE_PPPOE', 'status' => 'success']);
        $this->assertDatabaseHas('network_operation_logs', ['operation' => 'DISCONNECT_SESSION', 'status' => 'success']);
    }

    public function test_failed_fake_operation_is_logged_and_secret_is_redacted(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create(['status' => 'unavailable']);

        $this->actingAs($user)->post(route('network.accounts.store'), [
            'router_id' => $router->id,
            'username' => 'cust001',
            'profile' => 'HOME-10M',
            'password' => 'pppoe-secret',
        ])->assertSessionHasErrors('router_id');

        $log = NetworkOperationLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString('pppoe-secret', json_encode($log->request_payload));
        $this->assertStringNotContainsString('pppoe-secret', json_encode($log->result_payload));
        $this->assertStringNotContainsString('pppoe-secret', (string) $log->error_message);
    }

    public function test_tenant_a_cannot_view_tenant_b_operation_logs(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser('Tenant A');
        [$tenantB] = $this->tenantWithUser('Tenant B');
        NetworkOperationLog::factory()->for($tenantB)->create(['operation' => 'CREATE_PPPOE']);

        $this->actingAs($userA)
            ->get(route('network.logs.index'))
            ->assertOk()
            ->assertDontSee('CREATE_PPPOE');

        $this->assertSame($tenantA->id, $userA->tenant_id);
    }

    private function tenantWithUser(string $name = 'DemoNet ISP'): array
    {
        $tenant = Tenant::factory()->create(['name' => $name]);
        $user = User::factory()->for($tenant)->create();

        return [$tenant, $user];
    }
}
