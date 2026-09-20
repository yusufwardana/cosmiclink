<?php

namespace Tests\Feature;

use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\ControlledNetworkOperationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase6ITask2ControlledGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_2_additive_schema_contains_management_and_audit_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('network_accounts', [
            'management_state',
            'managed_at',
            'managed_by_user_id',
            'revoked_at',
            'revoked_by_user_id',
            'management_scope',
        ]));

        $this->assertTrue(Schema::hasColumns('network_operation_logs', [
            'network_account_id',
            'idempotency_key',
            'request_digest',
            'provider',
            'execution_mode',
            'preflight_evidence',
            'postflight_evidence',
            'failure_code',
            'resolved_by_user_id',
            'resolved_at',
            'resolution_note',
        ]));
    }

    public function test_legacy_accounts_are_not_backfilled_as_managed(): void
    {
        [$tenant, $user] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();

        NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'legacy-001',
            'profile' => 'HOME-10M',
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('network_accounts', ['management_state' => 'MANAGED']);
    }

    public function test_gate_denies_by_default_when_mutations_switch_is_unset(): void
    {
        [$tenant, $user] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'ADOPTED');
        config(['network.mutations_enabled' => false]);

        $decision = app(ControlledNetworkOperationGate::class)->check($user, $account, 'ENABLE_PPPOE');

        $this->assertFalse($decision->allowed);
        $this->assertSame('MUTATIONS_DISABLED', $decision->errorCode);
    }

    public function test_gate_requires_tenant_and_operator_role_before_management_checks(): void
    {
        [$tenant, $customer] = $this->tenantWithUser('customer');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'MANAGED');
        config(['network.mutations_enabled' => true]);

        $decision = app(ControlledNetworkOperationGate::class)->check($customer, $account, 'ENABLE_PPPOE');

        $this->assertFalse($decision->allowed);
        $this->assertSame('OPERATION_NOT_ALLOWED', $decision->errorCode);
    }

    public function test_gate_denies_cross_tenant_operator(): void
    {
        [$tenantA] = $this->tenantWithUser('admin', 'Tenant A');
        [, $operatorB] = $this->tenantWithUser('admin', 'Tenant B');
        $router = Router::factory()->for($tenantA)->create();
        $account = $this->account($tenantA, $router, 'MANAGED');
        config(['network.mutations_enabled' => true]);

        $decision = app(ControlledNetworkOperationGate::class)->check($operatorB, $account, 'ENABLE_PPPOE');

        $this->assertFalse($decision->allowed);
        $this->assertSame('TENANT_MISMATCH', $decision->errorCode);
    }

    public function test_gate_allows_only_the_three_initial_operations_to_reach_later_gates(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'MANAGED');
        config(['network.mutations_enabled' => true]);

        foreach (['CREATE_PPPOE', 'CHANGE_PROFILE', 'RAW_COMMAND', ''] as $operation) {
            $decision = app(ControlledNetworkOperationGate::class)->check($operator, $account, $operation);

            $this->assertFalse($decision->allowed);
            $this->assertSame('OPERATION_NOT_ALLOWED', $decision->errorCode);
        }

        foreach (['ENABLE_PPPOE', 'DISABLE_PPPOE', 'DISCONNECT_SESSION'] as $operation) {
            $decision = app(ControlledNetworkOperationGate::class)->check($operator, $account, $operation);

            $this->assertFalse($decision->allowed);
            $this->assertSame('SCOPE_NOT_CONFIRMED', $decision->errorCode);
        }
    }

    public function test_policies_require_tenant_and_operator_role(): void
    {
        [$tenant, $operator] = $this->tenantWithUser('admin');
        $router = Router::factory()->for($tenant)->create();
        $account = $this->account($tenant, $router, 'ADOPTED');
        $log = NetworkOperationLog::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'operation' => 'ENABLE_PPPOE',
            'status' => 'denied',
            'started_at' => now(),
        ]);
        $customer = User::factory()->for($tenant)->create(['role' => 'customer']);

        $this->assertTrue($operator->can('manageNetworkAccount', $account));
        $this->assertTrue($operator->can('executeNetworkOperation', $account));
        $this->assertTrue($operator->can('viewNetworkOperationLog', $log));
        $this->assertFalse($customer->can('manageNetworkAccount', $account));
        $this->assertFalse($customer->can('executeNetworkOperation', $account));
        $this->assertFalse($customer->can('viewNetworkOperationLog', $log));
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithUser(string $role, string $name = 'Task 2 Tenant'): array
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
        ]);
    }
}
