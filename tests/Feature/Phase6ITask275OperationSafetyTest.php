<?php

namespace Tests\Feature;

use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\NetworkDriver;
use App\Services\Network\NetworkOperationResult;
use App\Services\Network\NetworkOperationSafetyQuery;
use App\Services\Network\NetworkOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6ITask275OperationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_is_durable_before_driver_invocation_and_success_resolves(): void
    {
        $account = $this->account();
        $invoked = false;
        $assertReservation = function (Router $router, string $username): void {
            TestCase::assertDatabaseHas('network_operation_logs', [
                'router_id' => $router->id,
                'target' => $username,
                'status' => 'reserved',
                'outcome' => null,
            ]);
        };

        $result = (new NetworkOperationService(new Task275ReservationNetworkDriver($invoked, $assertReservation)))
            ->changeStatus($account, 'active');

        $this->assertTrue($invoked);
        $this->assertTrue($result->successful);
        $this->assertDatabaseHas('network_operation_logs', [
            'network_account_id' => $account->id,
            'status' => 'success',
            'outcome' => 'SUCCEEDED',
        ]);
    }

    public function test_deterministic_failure_resolves_as_failed(): void
    {
        $router = Router::factory()->create(['status' => 'unavailable']);

        $result = app(NetworkOperationService::class)->testConnection($router);

        $this->assertFalse($result->successful);
        $this->assertSame('ROUTER_UNAVAILABLE', $result->errorCode);
        $this->assertDatabaseHas('network_operation_logs', [
            'router_id' => $router->id,
            'status' => 'failed',
            'outcome' => 'FAILED',
            'failure_code' => 'ROUTER_UNAVAILABLE',
        ]);
    }

    public function test_ambiguous_transport_failure_becomes_unknown_outcome(): void
    {
        $account = $this->account();

        $result = (new NetworkOperationService($this->driverReturning(
            new NetworkOperationResult(false, 'timeout', 'NETWORK_ENGINE_TIMEOUT')
        )))->changeStatus($account, 'active');

        $this->assertFalse($result->successful);
        $this->assertSame('NETWORK_ENGINE_TIMEOUT', $result->errorCode);
        $this->assertDatabaseHas('network_operation_logs', [
            'network_account_id' => $account->id,
            'status' => 'unknown',
            'outcome' => 'UNKNOWN_OUTCOME',
        ]);
    }

    public function test_unknown_and_postflight_mismatch_block_safety_query_but_resolved_history_does_not(): void
    {
        $account = $this->account();
        $query = app(NetworkOperationSafetyQuery::class);

        $this->assertFalse($query->hasUnresolvedState($account));

        NetworkOperationLog::factory()->create([
            'tenant_id' => $account->tenant_id,
            'router_id' => $account->router_id,
            'network_account_id' => $account->id,
            'status' => 'unknown',
            'outcome' => 'UNKNOWN_OUTCOME',
            'completed_at' => null,
        ]);
        $this->assertTrue($query->hasUnresolvedState($account));

        NetworkOperationLog::query()->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->assertFalse($query->hasUnresolvedState($account));

        NetworkOperationLog::factory()->create([
            'tenant_id' => $account->tenant_id,
            'router_id' => $account->router_id,
            'network_account_id' => $account->id,
            'status' => 'postflight_mismatch',
            'outcome' => 'POSTFLIGHT_MISMATCH',
            'completed_at' => null,
        ]);
        $this->assertTrue($query->hasUnresolvedState($account));
    }

    public function test_active_reservation_and_conflicting_scope_are_blocked(): void
    {
        $account = $this->account();
        NetworkOperationLog::factory()->create([
            'tenant_id' => $account->tenant_id,
            'router_id' => $account->router_id,
            'network_account_id' => $account->id,
            'status' => 'reserved',
            'outcome' => null,
            'completed_at' => null,
        ]);

        $result = (new NetworkOperationService($this->driverReturning(
            new NetworkOperationResult(true, 'should not run')
        )))->changeStatus($account, 'active');

        $this->assertFalse($result->successful);
        $this->assertSame('CONCURRENT_OPERATION', $result->errorCode);
    }

    public function test_tenant_scope_and_idempotency_are_isolated(): void
    {
        $account = $this->account();
        $other = $this->account();
        $service = new NetworkOperationService($this->driverReturning(new NetworkOperationResult(true, 'enabled')));

        $first = $service->changeStatus($account, 'active', idempotencyKey: 'same-key');
        $replay = $service->changeStatus($account, 'active', idempotencyKey: 'same-key');
        $conflict = $service->changeStatus($account, 'disabled', idempotencyKey: 'same-key');
        $otherTenant = $service->changeStatus($other, 'active', idempotencyKey: 'same-key');

        $this->assertTrue($first->successful);
        $this->assertTrue($replay->successful);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict->errorCode);
        $this->assertTrue($otherTenant->successful);
    }

    public function test_same_tenant_different_router_scopes_do_not_conflict(): void
    {
        $tenant = Tenant::factory()->create();
        $routerA = Router::factory()->for($tenant)->create();
        $routerB = Router::factory()->for($tenant)->create();
        $accountA = $this->accountFor($tenant, $routerA, 'router-a-user');
        $accountB = $this->accountFor($tenant, $routerB, 'router-b-user');
        $service = new NetworkOperationService($this->driverReturning(new NetworkOperationResult(true, 'enabled')));

        $this->assertTrue($service->changeStatus($accountA, 'active')->successful);
        $this->assertTrue($service->changeStatus($accountB, 'active')->successful);
    }

    public function test_billing_boundary_blocks_non_fake_driver_before_dispatch(): void
    {
        $account = $this->account();
        $invoked = false;

        config(['network.mutations_enabled' => true]);
        $result = (new NetworkOperationService(new Task275BlockedNetworkDriver($invoked)))
            ->changeStatusForBilling($account, 'active');

        $this->assertFalse($result->successful);
        $this->assertSame('BILLING_NETWORK_DISPATCH_BLOCKED', $result->errorCode);
        $this->assertFalse($invoked);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    private function driverReturning(NetworkOperationResult $result): NetworkDriver
    {
        return new Task275TestNetworkDriver($result);
    }

    private function account(): NetworkAccount
    {
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();

        return $this->accountFor($tenant, $router, fake()->unique()->userName());
    }

    private function accountFor(Tenant $tenant, Router $router, string $username): NetworkAccount
    {
        return NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => $username,
            'profile' => 'HOME-10M',
            'status' => 'active',
            'management_state' => 'ADOPTED',
        ]);
    }
}

final class Task275TestNetworkDriver implements NetworkDriver
{
    public function __construct(private NetworkOperationResult $result) {}

    public function testConnection(Router $router): NetworkOperationResult
    {
        return $this->result;
    }

    public function createPppoeAccount(Router $router, array $account): NetworkOperationResult
    {
        return $this->result;
    }

    public function enablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return $this->result;
    }

    public function disablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return $this->result;
    }

    public function changePppoeProfile(Router $router, string $username, string $profile): NetworkOperationResult
    {
        return $this->result;
    }

    public function disconnectPppoeSession(Router $router, string $username): NetworkOperationResult
    {
        return $this->result;
    }
}

final class Task275BlockedNetworkDriver implements NetworkDriver
{
    public function __construct(private bool &$invoked) {}

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
        $this->invoked = true;

        return new NetworkOperationResult(true, 'must not run');
    }

    public function disablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }

    public function changePppoeProfile(Router $router, string $username, string $profile): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }

    public function disconnectPppoeSession(Router $router, string $username): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }
}

final class Task275ReservationNetworkDriver implements NetworkDriver
{
    public function __construct(private bool &$invoked, private \Closure $assertReservation) {}

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
        $this->invoked = true;
        ($this->assertReservation)($router, $username);

        return new NetworkOperationResult(true, 'enabled');
    }

    public function disablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }

    public function changePppoeProfile(Router $router, string $username, string $profile): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }

    public function disconnectPppoeSession(Router $router, string $username): NetworkOperationResult
    {
        return new NetworkOperationResult(true, 'ok');
    }
}
