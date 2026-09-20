<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\ManagedTargetAction;
use App\Models\ManagedTargetPreflightEvidence;
use App\Models\ManagedTargetSession;
use App\Models\NetworkAccount;
use App\Models\NetworkAccountTransition;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\AdoptDiscoveredNetworkResource;
use App\Services\Network\ControlledNetworkOperationGate;
use App\Services\Network\ControlledOperationReason;
use App\Services\Network\DiscoveryResult;
use App\Services\Network\ManagedAccountLifecycleService;
use App\Services\Network\ManagedTargetIdentityService;
use App\Services\Network\NetworkDiscoveryClient;
use App\Services\Network\NetworkDiscoveryService;
use App\Support\Network\RouterResourceIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class Phase6ITask28TargetIdentityContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_target_identity_context_schema_exists(): void
    {
        foreach ([
            'router_identity_ref',
            'router_identity_type',
            'router_identity_fingerprint',
            'routeros_version',
            'router_identity_snapshot_id',
            'router_identity_resource_id',
            'router_identity_evidence_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('network_accounts', $column), "missing network_accounts.{$column}");
        }

        $this->assertTrue(Schema::hasTable('managed_target_sessions'));
        $this->assertTrue(Schema::hasTable('managed_target_preflight_evidence'));
        $this->assertTrue(Schema::hasTable('managed_target_actions'));
        $this->assertTrue(Schema::hasTable('network_account_transitions'));
        $this->assertTrue(Schema::hasColumn('network_account_transitions', 'scope_digest'));
        $this->assertTrue(Schema::hasColumn('network_account_transitions', 'policy_allowed'));

        // Task 2.8 stores safe projections only: no structure introduced here may
        // grow a column that could hold credential material.
        foreach (['managed_target_sessions', 'managed_target_preflight_evidence', 'managed_target_actions', 'network_account_transitions'] as $contextTable) {
            foreach (Schema::getColumnListing($contextTable) as $column) {
                $this->assertDoesNotMatchRegularExpression(
                    '/pass(word)?|secret|token|credential|challenge/i',
                    $column,
                    "{$contextTable}.{$column} looks like credential storage"
                );
            }
        }

        $this->assertTrue(Schema::hasColumn('managed_target_sessions', 'superseded_at'));
        $this->assertTrue(Schema::hasColumn('managed_target_actions', 'confirmation_digest'));
        $this->assertTrue(Schema::hasColumn('managed_target_preflight_evidence', 'target_identity_fingerprint'));
    }

    public function test_adoption_persists_safe_target_identity_projections(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $account = $fixture['account']->fresh();

        $this->assertSame(RouterResourceIdentity::TYPE_ROUTEROS_ID, $account->router_identity_type);
        $this->assertSame('*7', $account->router_identity_ref);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $account->router_identity_fingerprint);
        $this->assertSame('6.49.13', $account->routeros_version);
        $this->assertSame($fixture['resource']->discovery_snapshot_id, $account->router_identity_snapshot_id);
        $this->assertSame($fixture['resource']->id, $account->router_identity_resource_id);
        $this->assertEquals($fixture['snapshot']->discovered_at, $account->router_identity_evidence_at);

        $serialized = json_encode($account->only([
            'router_identity_ref', 'router_identity_type', 'router_identity_fingerprint', 'routeros_version',
        ]));
        $this->assertStringNotContainsString('never-return', (string) $serialized);
        $this->assertStringNotContainsString('password', strtolower((string) $serialized));
    }

    public function test_adoption_clears_identity_context_on_unadopt(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        app(AdoptDiscoveredNetworkResource::class)->unadopt($fixture['resource']->fresh(), $fixture['user']);

        $account = $fixture['account']->fresh();

        $this->assertNull($account->router_identity_ref);
        $this->assertNull($account->router_identity_type);
        $this->assertNull($account->router_identity_fingerprint);
        $this->assertNull($account->routeros_version);
        $this->assertNull($account->router_identity_snapshot_id);
        $this->assertNull($account->router_identity_resource_id);
        $this->assertNull($account->router_identity_evidence_at);
    }

    public function test_identity_resolution_reports_missing_adopted_evidence(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $fixture['resource']->fresh()->update(['management_state' => 'DISCOVERED', 'network_account_id' => null]);

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());

        $this->assertFalse($result->resolved);
        $this->assertSame(ControlledOperationReason::IDENTITY_MISSING, $result->reasonCode);
        $this->assertSame(ControlledOperationReason::PRE8_TARGET_IDENTITY, $result->failedPrecondition);
        $this->assertNull($result->identity);
    }

    public function test_identity_resolution_reports_ambiguous_and_conflicting_adopted_evidence(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $this->resource($fixture['tenant'], $fixture['router'], $fixture['snapshot'], [
            'external_ref' => '*8',
            'username' => $fixture['account']->username,
        ], 'second-fingerprint', $fixture['account']);

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());

        $this->assertFalse($result->resolved);
        $this->assertSame(ControlledOperationReason::AMBIGUOUS_ACCOUNT_IDENTITY, $result->reasonCode);
        $this->assertSame(ControlledOperationReason::PRE8_TARGET_IDENTITY, $result->failedPrecondition);
    }

    public function test_identity_resolution_reports_stale_evidence(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        Carbon::setTestNow(now()->addDays(2));

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());
        Carbon::setTestNow();

        $this->assertFalse($result->resolved);
        $this->assertTrue($result->stale);
        $this->assertSame(ControlledOperationReason::IDENTITY_STALE, $result->reasonCode);
        $this->assertSame(ControlledOperationReason::PRE8_TARGET_IDENTITY, $result->failedPrecondition);
    }

    public function test_identity_resolution_reports_superseded_evidence_when_the_recorded_target_is_no_longer_adopted(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $fixture['resource']->fresh()->update(['external_ref' => '*9']);

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());

        $this->assertFalse($result->resolved);
        $this->assertSame(ControlledOperationReason::IDENTITY_STALE, $result->reasonCode);
    }

    public function test_name_only_identity_requires_the_explicit_fallback_policy(): void
    {
        $fixture = $this->adoptedFixture(externalRef: 'customer-01');

        $this->assertSame(RouterResourceIdentity::TYPE_NAME, $fixture['account']->fresh()->router_identity_type);

        $denied = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());
        $this->assertFalse($denied->resolved);
        $this->assertSame(ControlledOperationReason::IDENTITY_TYPE_UNSUPPORTED, $denied->reasonCode);

        config()->set('network.controlled_operations.identity.allow_name_fallback', true);
        $allowed = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());
        $this->assertTrue($allowed->resolved);
        $this->assertSame(RouterResourceIdentity::TYPE_NAME, $allowed->identity?->type());
    }

    public function test_simulated_identity_never_claims_real_target_eligibility(): void
    {
        $fixture = $this->adoptedFixture(externalRef: 'sim:*12', provider: 'fake');

        $this->assertSame(RouterResourceIdentity::TYPE_SIMULATED, $fixture['account']->fresh()->router_identity_type);

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());

        $this->assertTrue($result->resolved);
        $this->assertFalse($result->realTargetEligible);
        $this->assertSame(RouterResourceIdentity::TYPE_SIMULATED, $result->identity?->type());
    }

    public function test_real_routeros_identity_is_audited_as_real_target_eligible(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7', provider: 'routeros');

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());

        $this->assertTrue($result->resolved);
        $this->assertTrue($result->realTargetEligible);
    }

    public function test_preflight_evidence_records_safe_projections_and_validates_against_account_identity(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $service = app(ManagedTargetIdentityService::class);
        $identity = $service->resolve($fixture['account']->fresh());

        $evidence = $service->recordPreflightEvidence(
            $fixture['account']->fresh(),
            'DISABLE_PPPOE',
            'READY',
            $identity->identity,
            now(),
        );

        $this->assertInstanceOf(ManagedTargetPreflightEvidence::class, $evidence);
        $this->assertSame('DISABLE_PPPOE', $evidence->operation);
        $this->assertSame('READY', $evidence->outcome);
        $this->assertSame($identity->identity->fingerprint(), $evidence->target_identity_fingerprint);
        $this->assertSame(RouterResourceIdentity::TYPE_ROUTEROS_ID, $evidence->target_identity_type);
        $this->assertSame($fixture['account']->fresh()->router_identity_fingerprint, $evidence->account_identity_fingerprint);
        $this->assertStringNotContainsString('never-return', json_encode($evidence->attributesToArray()));

        $this->assertTrue($service->preflightEvidenceIsCurrent($fixture['account']->fresh(), 'DISABLE_PPPOE')->resolved);
    }

    public function test_expired_or_mismatched_preflight_evidence_is_not_current(): void
    {
        config()->set('network.controlled_operations.preflight_ttl_seconds', 30);
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $service = app(ManagedTargetIdentityService::class);
        $identity = $service->resolve($fixture['account']->fresh());

        Carbon::setTestNow(now()->subMinute());
        $service->recordPreflightEvidence($fixture['account']->fresh(), 'DISABLE_PPPOE', 'READY', $identity->identity, now());
        Carbon::setTestNow();

        $expired = $service->preflightEvidenceIsCurrent($fixture['account']->fresh(), 'DISABLE_PPPOE');
        $this->assertFalse($expired->resolved);
        $this->assertSame(ControlledOperationReason::PREFLIGHT_STALE, $expired->reasonCode);

        Carbon::setTestNow(now()->addMinute());
        // Fresh evidence taken against the identity as adopted...
        $service->recordPreflightEvidence($fixture['account']->fresh(), 'DISABLE_PPPOE', 'READY', $identity->identity, now());
        // ...then the adopted target identity moves to a different reference.
        $fixture['account']->fresh()->update(['router_identity_fingerprint' => str_repeat('f', 64)]);
        $mismatched = $service->preflightEvidenceIsCurrent($fixture['account']->fresh(), 'DISABLE_PPPOE');
        Carbon::setTestNow();

        $this->assertFalse($mismatched->resolved);
        $this->assertSame(ControlledOperationReason::PRE_FLIGHT_IDENTITY_MISMATCH, $mismatched->reasonCode);
    }

    public function test_target_action_audit_persists_digests_and_never_plaintext_confirmation(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $service = app(ManagedTargetIdentityService::class);
        $confirmation = 'CONFIRM-DISABLE-PPPOE-CUSTOMER-01-8f2b';

        $action = ManagedTargetAction::recordAttempt(
            account: $fixture['account']->fresh(),
            user: $fixture['user'],
            operation: 'DISABLE_PPPOE',
            allowed: false,
            reasonCode: ControlledOperationReason::RECONCILIATION_NOT_MATCHED,
            failedPrecondition: ControlledOperationReason::PRE7_RECONCILIATION,
            identity: $service->resolve($fixture['account']->fresh())->identity,
            confirmation: $confirmation,
        );

        $this->assertSame(hash('sha256', $confirmation), $action->confirmation_digest);
        $this->assertSame(ManagedTargetAction::STATUS_DENIED, $action->status);
        $this->assertSame($fixture['tenant']->id, $action->tenant_id);
        $this->assertSame($fixture['account']->id, $action->network_account_id);
        $this->assertSame($fixture['account']->fresh()->router_identity_fingerprint, $action->target_identity_fingerprint);
        $this->assertStringNotContainsString($confirmation, json_encode($action->fresh()->toArray()));
        $this->assertStringNotContainsString('never-return', json_encode($action->fresh()->toArray()));
    }

    public function test_active_sessions_are_recorded_and_superseded_from_discovery_evidence(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $service = app(ManagedTargetIdentityService::class);

        $this->runDiscovery($fixture['router'], $fixture['user'], sessions: [[
            'external_ref' => '*3',
            'name' => $fixture['account']->username,
            'service' => 'pppoe',
            'address' => '10.10.0.25',
            'caller-id' => '',
            'uptime' => '1h2m3s',
            'password' => 'never-return',
        ]]);

        $session = ManagedTargetSession::query()->where('network_account_id', $fixture['account']->id)->firstOrFail();
        $this->assertSame('*3', $session->session_ref);
        $this->assertSame(RouterResourceIdentity::TYPE_ROUTEROS_ID, $session->session_ref_type);
        $this->assertSame('customer-01', $session->session_name);
        $this->assertSame('pppoe', $session->service);
        $this->assertSame('10.10.0.25', $session->address);
        $this->assertSame(3723, $session->uptime_seconds);
        $this->assertSame(ManagedTargetSession::STATE_ACTIVE, $session->status);
        $this->assertNull($session->superseded_at);
        $this->assertStringNotContainsString('never-return', json_encode($session->toArray()));

        $resolved = $service->resolve($fixture['account']->fresh());
        $this->assertSame(1, $resolved->activeSessionCount());
        $this->assertCount(1, $resolved->activeSessions);

        $this->runDiscovery($fixture['router'], $fixture['user'], sessions: []);

        $this->assertSame(ManagedTargetSession::STATE_SUPERSEDED, $session->fresh()->status);
        $this->assertNotNull($session->fresh()->superseded_at);
        $this->assertSame(0, $service->resolve($fixture['account']->fresh())->activeSessionCount());
    }

    public function test_controlled_gate_stays_default_deny_but_reports_per_account_identity_state(): void
    {
        config()->set('network.mutations_enabled', true);
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $fixture['account']->fresh()->update([
            'management_state' => 'MANAGED',
            'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE,
        ]);
        $gate = app(ControlledNetworkOperationGate::class);

        $healthy = $gate->check($fixture['user'], $fixture['account']->fresh(), 'DISABLE_PPPOE');

        // Identity context alone must not authorise anything: reconciliation
        // evidence (PRE7) is still the first unsatisfied precondition.
        $this->assertFalse($healthy->allowed);
        $this->assertSame(ControlledOperationReason::RECONCILIATION_NOT_MATCHED, $healthy->errorCode);
        $this->assertSame(ControlledOperationReason::PRE7_RECONCILIATION, $healthy->failedPrecondition);
        $this->assertTrue($healthy->evidence['identity']['resolved']);
        $this->assertSame(RouterResourceIdentity::TYPE_ROUTEROS_ID, $healthy->evidence['identity']['identity_type']);

        $fixture['account']->fresh()->update([
            'router_identity_ref' => null,
            'router_identity_type' => null,
            'router_identity_fingerprint' => null,
            'router_identity_snapshot_id' => null,
            'router_identity_resource_id' => null,
            'router_identity_evidence_at' => null,
        ]);

        $denied = $gate->check($fixture['user'], $fixture['account']->fresh(), 'DISABLE_PPPOE');

        $this->assertFalse($denied->allowed);
        $this->assertNotSame(ControlledOperationReason::APPROVED, $denied->errorCode);
        $this->assertFalse($denied->evidence['identity']['resolved']);
        $this->assertSame(ControlledOperationReason::IDENTITY_MISSING, $denied->evidence['identity']['reason_code']);
        $this->assertSame(ControlledOperationReason::PRE8_TARGET_IDENTITY, $denied->evidence['identity']['failed_precondition']);
        $this->assertStringNotContainsString('never-return', json_encode($denied->evidence));
    }

    public function test_revoked_accounts_never_resolve_a_usable_identity_context(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $fixture['account']->fresh()->update([
            'management_state' => 'MANAGED',
            'revoked_at' => now(),
            'revoked_by_user_id' => $fixture['user']->id,
        ]);

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());

        $this->assertFalse($result->resolved);
        $this->assertSame(ControlledOperationReason::MANAGEMENT_REVOKED, $result->reasonCode);
        $this->assertSame(ControlledOperationReason::PRE4_RESOURCE_ADOPTED, $result->failedPrecondition);
    }

    public function test_an_identity_claimed_by_another_account_is_ambiguous_for_both(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');

        // A second account that was adopted from the same RouterOS sentence: the
        // target no longer resolves to exactly one account, so neither may be
        // treated as safely addressable.
        $shadow = NetworkAccount::create([
            'tenant_id' => $fixture['tenant']->id,
            'router_id' => $fixture['router']->id,
            'username' => 'customer-01-shadow',
            'profile' => 'HOME-20M',
            'status' => 'active',
            'management_state' => 'MANAGED',
            'router_identity_ref' => $fixture['account']->router_identity_ref,
            'router_identity_type' => $fixture['account']->router_identity_type,
            'router_identity_fingerprint' => $fixture['account']->router_identity_fingerprint,
            'router_identity_resource_id' => $fixture['account']->router_identity_resource_id,
            'router_identity_snapshot_id' => $fixture['account']->router_identity_snapshot_id,
            'router_identity_evidence_at' => $fixture['account']->router_identity_evidence_at,
        ]);

        $result = app(ManagedTargetIdentityService::class)->resolve($fixture['account']->fresh());

        $this->assertFalse($result->resolved);
        $this->assertSame(ControlledOperationReason::AMBIGUOUS_ACCOUNT_IDENTITY, $result->reasonCode);
        $this->assertSame(ControlledOperationReason::PRE8_TARGET_IDENTITY, $result->failedPrecondition);
    }

    public function test_management_transition_audit_records_references_and_digests_only(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $account = $fixture['account']->fresh();
        $scope = ['operations' => ['ENABLE_PPPOE', 'DISABLE_PPPOE'], 'secret' => 'never-return'];

        $transition = NetworkAccountTransition::record(
            account: $account,
            fromState: NetworkAccountTransition::STATE_ADOPTED,
            toState: NetworkAccountTransition::STATE_MANAGED,
            user: $fixture['user'],
            reasonCode: ControlledOperationReason::APPROVED,
            scope: $scope,
        );

        $this->assertSame(NetworkAccountTransition::STATE_ADOPTED, $transition->from_state);
        $this->assertSame(NetworkAccountTransition::STATE_MANAGED, $transition->to_state);
        $this->assertSame($fixture['tenant']->id, $transition->tenant_id);
        $this->assertSame($fixture['router']->id, $transition->router_id);
        $this->assertSame($account->id, $transition->network_account_id);
        $this->assertSame($fixture['user']->id, $transition->performed_by);
        $this->assertSame($account->router_identity_fingerprint, $transition->target_identity_fingerprint);
        $this->assertSame(64, strlen((string) $transition->scope_digest));
        $this->assertSame(NetworkAccountTransition::scopeDigest($scope), $transition->scope_digest);
        // The digest is order-insensitive, so re-declaring the same scope cannot forge a different grant.
        $this->assertSame($transition->scope_digest, NetworkAccountTransition::scopeDigest([
            'secret' => 'never-return',
            'operations' => ['DISABLE_PPPOE', 'ENABLE_PPPOE'],
        ]));
        $this->assertStringNotContainsString('never-return', json_encode($transition->toArray()));
        $this->assertTrue($transition->policy_allowed);
        $this->assertSame(1, $transition->sequence);
        $this->assertSame(
            1,
            NetworkAccountTransition::query()->where('network_account_id', $account->id)->count()
        );
    }

    public function test_transition_audit_refuses_unknown_states(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');

        $this->expectException(InvalidArgumentException::class);
        NetworkAccountTransition::record(
            account: $fixture['account']->fresh(),
            fromState: 'ADOPTED',
            toState: 'NOT_A_STATE',
        );
    }

    public function test_transition_audit_keeps_history_and_flags_undocumented_state_pairs(): void
    {
        $fixture = $this->adoptedFixture(externalRef: '*7');
        $account = $fixture['account']->fresh();

        $first = NetworkAccountTransition::record(
            account: $account,
            fromState: NetworkAccountTransition::STATE_ADOPTED,
            toState: NetworkAccountTransition::STATE_OBSERVED,
            user: $fixture['user'],
            reasonCode: ControlledOperationReason::MANAGEMENT_REVOKED,
        );

        $this->assertFalse($first->policy_allowed);
        $this->assertSame(1, $first->sequence);

        $second = NetworkAccountTransition::record(
            account: $account,
            fromState: NetworkAccountTransition::STATE_ADOPTED,
            toState: NetworkAccountTransition::STATE_REVOKED,
        );

        $this->assertTrue($second->policy_allowed);
        $this->assertSame(2, $second->sequence);
        $this->assertSame(2, NetworkAccountTransition::query()->where('network_account_id', $account->id)->count());
    }

    /**
     * @return array{tenant: Tenant, user: User, router: Router, account: NetworkAccount, resource: DiscoveredNetworkResource, snapshot: NetworkDiscoverySnapshot, connection: CustomerConnection}
     */
    private function adoptedFixture(string $externalRef = '*7', ?Carbon $discoveredAt = null, string $provider = 'routeros'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => 'admin']);
        $router = Router::factory()->for($tenant)->create(['name' => 'CORE-01']);
        $connection = CustomerConnection::factory()
            ->for(Customer::factory()->for($tenant))
            ->for($router)
            ->create(['tenant_id' => $tenant->id]);

        $snapshot = $this->runDiscovery($router, $user, $discoveredAt, $provider, $externalRef);
        $resource = DiscoveredNetworkResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('resource_type', 'pppoe_account')
            ->where('external_ref', $externalRef)
            ->firstOrFail();

        app(AdoptDiscoveredNetworkResource::class)->handle($resource, $connection->fresh(), $user);

        return [
            'tenant' => $tenant,
            'user' => $user,
            'router' => $router,
            'account' => NetworkAccount::query()->where('tenant_id', $tenant->id)->firstOrFail(),
            'resource' => $resource->fresh(),
            'snapshot' => $snapshot,
            'connection' => $connection->fresh(),
        ];
    }

    private function runDiscovery(Router $router, User $user, ?Carbon $discoveredAt = null, string $provider = 'routeros', string $externalRef = '*7', array $sessions = []): NetworkDiscoverySnapshot
    {
        app()->bind(NetworkDiscoveryClient::class, fn () => new class($discoveredAt, $provider, $externalRef, $sessions) implements NetworkDiscoveryClient
        {
            public function __construct(
                private readonly ?Carbon $discoveredAt,
                private readonly string $provider,
                private readonly string $externalRef,
                private readonly array $sessions,
            ) {}

            public function discover(Router $router): DiscoveryResult
            {
                return new DiscoveryResult(true, 'Read-only discovery complete', null, [
                    'provider' => $this->provider,
                    'router_ref' => (string) $router->id,
                    'discovered_at' => ($this->discoveredAt ?? now())->toIso8601String(),
                    'snapshot' => [
                        'device' => ['name' => 'CORE-01', 'routeros_version' => '6.49.13'],
                        'profiles' => [],
                        'accounts' => [[
                            'external_ref' => $this->externalRef,
                            'username' => 'customer-01',
                            'profile' => 'HOME-20M',
                            'service' => 'pppoe',
                            'enabled' => true,
                            'password' => 'never-return',
                        ]],
                        'address_pools' => [],
                        'queues' => [],
                        'active_sessions' => $this->sessions,
                    ],
                ]);
            }
        });

        return app(NetworkDiscoveryService::class)->discover($router, $user);
    }

    private function resource(Tenant $tenant, Router $router, NetworkDiscoverySnapshot $snapshot, array $data, string $fingerprint, ?NetworkAccount $account = null): DiscoveredNetworkResource
    {
        return DiscoveredNetworkResource::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'discovery_snapshot_id' => $snapshot->id,
            'customer_connection_id' => $account?->connections()->first()?->id,
            'network_account_id' => $account?->id,
            'resource_type' => 'pppoe_account',
            'external_ref' => $data['external_ref'],
            'name' => $data['username'],
            'management_state' => 'ADOPTED',
            'fingerprint' => $fingerprint,
            'normalized_data' => $data,
            'first_seen_at' => $snapshot->discovered_at,
            'last_seen_at' => $snapshot->discovered_at,
        ]);
    }
}
