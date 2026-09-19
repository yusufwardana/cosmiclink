<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\HealthObservation;
use App\Models\ManagedTargetAction;
use App\Models\NetworkAccount;
use App\Models\NetworkAccountTransition;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\NetworkOperationLog;
use App\Models\ReconciliationEvidence;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\ManagedAccountLifecycleService;
use App\Support\Network\RouterResourceIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase6ITask3ManagedAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['monitoring.freshness_seconds' => 180, 'monitoring.checkpoint_seconds' => 120]);
    }

    public function test_adopted_account_can_become_managed_with_canonical_scope_and_audit_evidence(): void
    {
        $fixture = $this->managedFixture();
        $confirmation = ManagedAccountLifecycleService::CONFIRMATION;
        config(['monitoring.freshness_seconds' => 180, 'monitoring.checkpoint_seconds' => 120]);

        $decision = app(ManagedAccountLifecycleService::class)->manage(
            $fixture['account'],
            $fixture['user'],
            $confirmation,
        );

        $this->assertTrue($decision->allowed, json_encode([$decision->errorCode, $decision->failedPrecondition]));
        $this->assertSame('MANAGED', $fixture['account']->fresh()->management_state);
        $this->assertSame(ManagedAccountLifecycleService::MANAGEMENT_SCOPE, $fixture['account']->fresh()->management_scope);
        $this->assertSame($fixture['user']->id, $fixture['account']->fresh()->managed_by_user_id);
        $this->assertDatabaseHas('network_account_transitions', [
            'network_account_id' => $fixture['account']->id,
            'from_state' => 'ADOPTED',
            'to_state' => 'MANAGED',
            'policy_allowed' => true,
            'performed_by' => $fixture['user']->id,
            'scope_digest' => NetworkAccountTransition::scopeDigest(ManagedAccountLifecycleService::MANAGEMENT_SCOPE),
        ]);
        $this->assertDatabaseHas('managed_target_actions', [
            'network_account_id' => $fixture['account']->id,
            'operation' => ManagedAccountLifecycleService::LIFECYCLE_OPERATION,
            'status' => ManagedTargetAction::STATUS_APPROVED,
            'confirmation_digest' => hash('sha256', $confirmation),
        ]);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_managed_account_can_be_revoked_locally_without_resolving_operation_history(): void
    {
        $fixture = $this->managedFixture();
        $this->assertTrue(app(ManagedAccountLifecycleService::class)->manage(
            $fixture['account'],
            $fixture['user'],
            ManagedAccountLifecycleService::CONFIRMATION,
        )->allowed);

        $account = $fixture['account']->fresh();
        $account->update(['management_state' => 'MANAGED']);

        $decision = app(ManagedAccountLifecycleService::class)->revoke($account, $fixture['user']);

        $this->assertTrue($decision->allowed);
        $revoked = $account->fresh();
        $this->assertSame('ADOPTED', $revoked->management_state);
        $this->assertNull($revoked->management_scope);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame($fixture['resource']->id, $revoked->router_identity_resource_id);
        $this->assertDatabaseHas('network_account_transitions', [
            'network_account_id' => $account->id,
            'from_state' => 'MANAGED',
            'to_state' => 'ADOPTED',
            'policy_allowed' => true,
        ]);
        $this->assertSame(2, NetworkAccountTransition::where('network_account_id', $account->id)->count());
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_management_requires_explicit_confirmation_and_never_persists_plaintext(): void
    {
        $fixture = $this->managedFixture();

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], 'wrong-confirmation');

        $this->assertFalse($decision->allowed);
        $this->assertSame('PRE12', $decision->failedPrecondition);
        $this->assertSame('ADOPTED', $fixture['account']->fresh()->management_state);
        $this->assertDatabaseHas('managed_target_actions', [
            'network_account_id' => $fixture['account']->id,
            'failed_precondition' => 'PRE12',
            'confirmation_digest' => hash('sha256', 'wrong-confirmation'),
        ]);
        $this->assertStringNotContainsString('wrong-confirmation', json_encode(ManagedTargetAction::latest('id')->first()->toArray()));
    }

    public function test_first_failure_wins_and_reconciliation_is_checked_before_identity(): void
    {
        $fixture = $this->managedFixture();
        $fixture['resource']->update(['management_state' => 'DISCOVERED']);
        $fixture['account']->update(['router_identity_ref' => null, 'router_identity_type' => null, 'router_identity_fingerprint' => null]);

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);

        $this->assertFalse($decision->allowed);
        $this->assertSame('PRE5', $decision->failedPrecondition);
        $this->assertSame('ADOPTED', $fixture['account']->fresh()->management_state);
    }

    public function test_cross_tenant_actor_and_broken_relationship_are_denied_without_state_change(): void
    {
        $fixture = $this->managedFixture();
        $otherUser = User::factory()->create(['role' => 'admin']);

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $otherUser, ManagedAccountLifecycleService::CONFIRMATION);

        $this->assertFalse($decision->allowed);
        $this->assertSame('PRE1', $decision->failedPrecondition);
        $fixture['connection']->update(['network_account_id' => null]);
        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);
        $this->assertFalse($decision->allowed);
        $this->assertSame('PRE3', $decision->failedPrecondition);
        $this->assertSame('ADOPTED', $fixture['account']->fresh()->management_state);
    }

    public function test_stale_discovery_and_non_matched_reconciliation_are_denied(): void
    {
        $fixture = $this->managedFixture();
        $fixture['resource']->update(['last_seen_at' => now()->subSeconds((int) config('network.discovery_freshness_seconds') + 1)]);

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);
        $this->assertSame('PRE6', $decision->failedPrecondition);

        $fixture = $this->managedFixture();
        ReconciliationEvidence::query()->where('adopted_resource_id', $fixture['resource']->id)->update(['outcome' => 'CHANGED']);
        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);
        $this->assertSame('PRE7', $decision->failedPrecondition);
    }

    public function test_unresolved_operation_blocks_management_but_revocation_remains_fail_safe(): void
    {
        $fixture = $this->managedFixture();
        NetworkOperationLog::create([
            'tenant_id' => $fixture['tenant']->id,
            'router_id' => $fixture['router']->id,
            'network_account_id' => $fixture['account']->id,
            'operation' => 'ENABLE_PPPOE',
            'status' => 'unknown',
            'outcome' => 'UNKNOWN_OUTCOME',
            'safety_scope' => 'account',
            'target' => 'customer-01',
            'request_payload' => [],
            'result_payload' => [],
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);
        $this->assertFalse($decision->allowed);
        $this->assertSame('PRE11', $decision->failedPrecondition);

        $fixture['account']->update(['management_state' => 'MANAGED', 'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE]);
        $revocation = app(ManagedAccountLifecycleService::class)->revoke($fixture['account'], $fixture['user']);
        $this->assertTrue($revocation->allowed);
        $this->assertSame('ADOPTED', $fixture['account']->fresh()->management_state);
        $this->assertDatabaseHas('network_operation_logs', ['outcome' => 'UNKNOWN_OUTCOME']);
    }

    public function test_local_management_does_not_require_mutation_switch_and_creates_no_operation_log(): void
    {
        config(['network.mutations_enabled' => false, 'network.mutation_provider' => 'fake']);
        $fixture = $this->managedFixture();

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);

        $this->assertTrue($decision->allowed);
        $this->assertDatabaseCount('network_operation_logs', 0);
    }

    public function test_missing_identity_is_denied_at_pre8_without_requiring_an_active_session(): void
    {
        $fixture = $this->managedFixture();
        $fixture['account']->update([
            'router_identity_ref' => null,
            'router_identity_type' => null,
            'router_identity_fingerprint' => null,
        ]);

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);

        $this->assertFalse($decision->allowed);
        $this->assertSame('PRE8', $decision->failedPrecondition);
        $this->assertSame('IDENTITY_MISSING', $decision->errorCode);
    }

    public function test_compatibility_and_health_failures_are_ordered_after_identity(): void
    {
        $fixture = $this->managedFixture();
        config(['network.mutation_provider' => 'routeros']);

        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);
        $this->assertSame('PRE9', $decision->failedPrecondition);

        config(['network.mutation_provider' => 'fake']);
        HealthObservation::query()->where('subject_type', 'router')->where('subject_id', $fixture['router']->id)->update(['health_state' => 'offline', 'reachable' => false]);
        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);
        $this->assertSame('PRE10', $decision->failedPrecondition);
    }

    public function test_scope_is_server_defined_and_invalid_lifecycle_state_does_not_mutate(): void
    {
        $fixture = $this->managedFixture();
        $this->assertSame(['ENABLE_PPPOE', 'DISABLE_PPPOE', 'DISCONNECT_SESSION'], ManagedAccountLifecycleService::MANAGEMENT_SCOPE['operations']);

        $fixture['account']->update(['management_state' => 'MANAGED']);
        $decision = app(ManagedAccountLifecycleService::class)->manage($fixture['account'], $fixture['user'], ManagedAccountLifecycleService::CONFIRMATION);

        $this->assertFalse($decision->allowed);
        $this->assertSame('PRE5', $decision->failedPrecondition);
        $this->assertSame('MANAGED', $fixture['account']->fresh()->management_state);
        $this->assertDatabaseCount('network_account_transitions', 0);
    }

    /**
     * @return array{tenant: Tenant, user: User, router: Router, account: NetworkAccount, connection: CustomerConnection, resource: DiscoveredNetworkResource}
     */
    private function managedFixture(): array
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 19, 12, 0, 0));
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => 'admin']);
        $router = Router::factory()->for($tenant)->create(['name' => 'CORE-01']);
        $customer = Customer::factory()->for($tenant)->create();
        $connection = CustomerConnection::factory()->for($tenant)->for($customer)->for($router)->create();
        $identity = RouterResourceIdentity::parse('*7');
        $account = NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'customer-01',
            'profile' => 'HOME-10M',
            'status' => 'active',
            'management_state' => 'ADOPTED',
            'router_identity_ref' => '*7',
            'router_identity_type' => $identity->type,
            'router_identity_fingerprint' => $identity->fingerprint(),
            'routeros_version' => '6.49.13',
            'router_identity_evidence_at' => now(),
        ]);
        $connection->update(['network_account_id' => $account->id]);
        $snapshot = NetworkDiscoverySnapshot::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'provider' => 'fake',
            'status' => 'success',
            'discovered_at' => now(),
            'summary' => [],
            'snapshot' => ['device' => ['name' => 'CORE-01', 'routeros_version' => '6.49.13']],
        ]);
        $resource = DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'discovery_snapshot_id' => $snapshot->id,
            'customer_connection_id' => $connection->id,
            'network_account_id' => $account->id,
            'resource_type' => 'pppoe_account',
            'external_ref' => '*7',
            'name' => 'customer-01',
            'management_state' => 'ADOPTED',
            'fingerprint' => hash('sha256', 'resource-1'),
            'normalized_data' => ['username' => 'customer-01', 'profile' => 'HOME-10M'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $account->update(['router_identity_snapshot_id' => $snapshot->id, 'router_identity_resource_id' => $resource->id]);
        $compared = $resource->replicate();
        $compared->discovery_snapshot_id = $snapshot->id;
        $compared->management_state = 'DISCOVERED';
        $compared->fingerprint = hash('sha256', 'resource-1-compared');
        $compared->save();
        ReconciliationEvidence::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'adopted_resource_id' => $resource->id,
            'compared_resource_id' => $compared->id,
            'discovery_snapshot_id' => $snapshot->id,
            'network_account_id' => $account->id,
            'customer_connection_id' => $connection->id,
            'outcome' => 'MATCHED',
            'discovered_at' => now(),
            'reconciled_at' => now(),
            'adopted_fingerprint' => $resource->fingerprint,
            'compared_fingerprint' => $compared->fingerprint,
            'relationship_fingerprint' => ReconciliationEvidence::relationshipFingerprint($resource, $compared, $account->id, $connection->id),
        ]);
        HealthObservation::create([
            'tenant_id' => $tenant->id,
            'subject_type' => 'router',
            'subject_id' => $router->id,
            'health_state' => 'online',
            'reachable' => true,
            'observed_at' => now(),
            'provider' => 'fake',
            'metadata' => ['simulation' => true],
        ]);

        return compact('tenant', 'user', 'router', 'account', 'connection', 'resource');
    }
}
