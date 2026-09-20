<?php

namespace Tests\Feature;

use App\Models\NetworkAccount;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\ControlledNetworkOperationDecision;
use App\Services\Network\ControlledNetworkOperationGate;
use App\Services\Network\GoNetworkDriver;
use App\Services\Network\ManagedAccountLifecycleService;
use App\Services\Network\NetworkDriver;
use App\Services\Network\NetworkOperationResult;
use App\Services\Network\NetworkOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class Phase6ITask41ControlledOperationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_controlled_operation_is_authorized_by_the_gate_before_driver_dispatch(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'MANAGED');
        config(['network.mutations_enabled' => true]);

        $gate = Mockery::mock(ControlledNetworkOperationGate::class);
        $gate->shouldReceive('check')->once()->with($operator, Mockery::on(fn (NetworkAccount $checkedAccount): bool => $checkedAccount->is($account)), 'ENABLE_PPPOE')->andReturn(ControlledNetworkOperationDecision::allow());
        $gate->shouldReceive('check')->once()->with($operator, Mockery::on(fn (NetworkAccount $checkedAccount): bool => $checkedAccount->is($account)), 'DISABLE_PPPOE')->andReturn(ControlledNetworkOperationDecision::allow());
        $gate->shouldReceive('check')->once()->with($operator, Mockery::on(fn (NetworkAccount $checkedAccount): bool => $checkedAccount->is($account)), 'DISCONNECT_SESSION')->andReturn(ControlledNetworkOperationDecision::allow());
        $this->app->instance(ControlledNetworkOperationGate::class, $gate);

        $driver = new Task41RecordingNetworkDriver;
        $service = new NetworkOperationService($driver, $gate);

        $service->changeStatus($account, 'active', $operator);
        $service->changeStatus($account, 'disabled', $operator);
        $service->disconnect($account, $operator);

        $this->assertSame(['ENABLE_PPPOE', 'DISABLE_PPPOE', 'DISCONNECT_SESSION'], $driver->operations);
    }

    public function test_denied_controlled_operation_does_not_send_go_http(): void
    {
        Http::fake();
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'ADOPTED');
        config(['network.mutations_enabled' => true]);

        $result = (new NetworkOperationService(app(GoNetworkDriver::class), app(ControlledNetworkOperationGate::class)))
            ->changeStatus($account, 'active', $operator);

        $this->assertFalse($result->successful);
        Http::assertNothingSent();
    }

    public function test_current_reloaded_account_is_used_for_dispatch_context(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'MANAGED');
        $browserAccount = $account->fresh();
        $account->update(['username' => 'current-username']);
        config(['network.mutations_enabled' => true]);

        $gate = Mockery::mock(ControlledNetworkOperationGate::class);
        $gate->shouldReceive('check')->once()->with($operator, Mockery::on(fn (NetworkAccount $checked): bool => $checked->username === 'current-username'), 'ENABLE_PPPOE')->andReturn(ControlledNetworkOperationDecision::allow());
        $driver = new Task41RecordingNetworkDriver;

        $result = (new NetworkOperationService($driver, $gate))->changeStatus($browserAccount, 'active', $operator);

        $this->assertTrue($result->successful);
        $this->assertSame('current-username', $driver->usernames[0]);
        $this->assertDatabaseHas('network_operation_logs', ['target' => 'current-username', 'network_account_id' => $account->id]);
    }

    public function test_non_controlled_operations_remain_outside_the_controlled_gate(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'ADOPTED');
        $gate = Mockery::mock(ControlledNetworkOperationGate::class);
        $gate->shouldNotReceive('check');
        $driver = new Task41RecordingNetworkDriver;

        $service = new NetworkOperationService($driver, $gate);
        $this->assertTrue($service->testConnection($router, $operator)->successful);
        $this->assertTrue($service->createAccount($router, ['username' => 'new-user', 'profile' => 'HOME-10M'], $operator)->successful);
        $this->assertTrue($service->changeProfile($account, 'HOME-20M', $operator)->successful);
    }

    public function test_denied_controlled_operation_never_reaches_driver_or_creates_success_evidence(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'MANAGED');
        config(['network.mutations_enabled' => false]);
        $driver = new Task41RecordingNetworkDriver;

        $result = (new NetworkOperationService($driver, app(ControlledNetworkOperationGate::class)))->changeStatus($account, 'active', $operator);

        $this->assertFalse($result->successful);
        $this->assertSame('MUTATIONS_DISABLED', $result->errorCode);
        $this->assertSame([], $driver->operations);
        $this->assertDatabaseMissing('network_operation_logs', [
            'network_account_id' => $account->id,
            'operation' => 'ENABLE_PPPOE',
        ]);
    }

    public function test_real_gate_denies_non_operator_cross_tenant_and_non_managed_accounts_before_driver(): void
    {
        config(['network.mutations_enabled' => true]);
        [$tenant, $operator] = $this->tenantWithUser('admin');
        [, $otherOperator] = $this->tenantWithUser('admin', 'Other Tenant');
        $customer = User::factory()->for($tenant)->create(['role' => 'customer']);
        $router = Router::factory()->for($tenant)->create();
        $driver = new Task41RecordingNetworkDriver;

        foreach ([
            [$customer, $this->account($tenant, $router, 'MANAGED')],
            [$otherOperator, $this->account($tenant, $router, 'MANAGED')],
            [$operator, $this->account($tenant, $router, 'DISCOVERED')],
            [$operator, $this->account($tenant, $router, 'ADOPTED')],
        ] as [$actor, $account]) {
            $result = (new NetworkOperationService($driver, app(ControlledNetworkOperationGate::class)))->changeStatus($account, 'active', $actor);

            $this->assertFalse($result->successful);
            $this->assertContains($result->errorCode, ['OPERATION_NOT_ALLOWED', 'TENANT_MISMATCH', 'NOT_MANAGED']);
        }

        $this->assertSame([], $driver->operations);
    }

    public function test_managed_account_requires_the_canonical_task_three_scope(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'MANAGED');
        config(['network.mutations_enabled' => true]);

        foreach (['ENABLE_PPPOE', 'DISABLE_PPPOE', 'DISCONNECT_SESSION'] as $operation) {
            $account->forceFill(['management_scope' => [
                'version' => 1,
                'operations' => [$operation],
            ]])->save();

            $decision = app(ControlledNetworkOperationGate::class)->check($operator, $account->fresh(), $operation);

            $this->assertFalse($decision->allowed);
            $this->assertSame('SCOPE_NOT_CONFIRMED', $decision->errorCode);
        }
    }

    public function test_stale_managed_request_is_denied_after_current_account_is_revoked(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'MANAGED');
        $browserAccount = $account->fresh();
        config(['network.mutations_enabled' => true]);
        $driver = new Task41RecordingNetworkDriver;

        $account->forceFill([
            'management_state' => 'ADOPTED',
            'management_scope' => null,
            'revoked_at' => now(),
            'revoked_by_user_id' => $operator->id,
        ])->save();

        $result = (new NetworkOperationService($driver, app(ControlledNetworkOperationGate::class)))->changeStatus($browserAccount, 'active', $operator);

        $this->assertFalse($result->successful);
        $this->assertSame('NOT_MANAGED', $result->errorCode);
        $this->assertSame([], $driver->operations);
    }

    public function test_client_metadata_cannot_bypass_the_lifecycle_state(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'ADOPTED');
        $account->forceFill(['metadata' => ['adopted_from_discovery' => false]])->save();
        config(['network.mutations_enabled' => true]);

        $decision = app(ControlledNetworkOperationGate::class)->check($operator, $account->fresh(), 'ENABLE_PPPOE');

        $this->assertFalse($decision->allowed);
        $this->assertSame('NOT_MANAGED', $decision->errorCode);
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithUser(string $role, string $name = 'Task 4.1 Tenant'): array
    {
        $tenant = Tenant::factory()->create(['name' => $name]);
        $user = User::factory()->for($tenant)->create(['role' => $role]);

        return [$tenant, $user];
    }

    private function account(Tenant $tenant, Router $router, string $state): NetworkAccount
    {
        return NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => fake()->unique()->userName(),
            'profile' => 'HOME-10M',
            'status' => 'active',
            'management_state' => $state,
            'management_scope' => $state === 'MANAGED' ? ManagedAccountLifecycleService::MANAGEMENT_SCOPE : null,
        ]);
    }
}

final class Task41RecordingNetworkDriver implements NetworkDriver
{
    /** @var list<string> */
    public array $operations = [];

    /** @var list<string> */
    public array $usernames = [];

    public function testConnection(Router $router): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }

    public function createPppoeAccount(Router $router, array $account): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }

    public function enablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        $this->operations[] = 'ENABLE_PPPOE';
        $this->usernames[] = $username;

        return new NetworkOperationResult(true, 'ok');
    }

    public function disablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        $this->operations[] = 'DISABLE_PPPOE';
        $this->usernames[] = $username;

        return new NetworkOperationResult(true, 'ok');
    }

    public function changePppoeProfile(Router $router, string $username, string $profile): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }

    public function disconnectPppoeSession(Router $router, string $username): NetworkOperationResult
    {
        $this->operations[] = 'DISCONNECT_SESSION';
        $this->usernames[] = $username;

        return new NetworkOperationResult(true, 'ok');
    }
}
