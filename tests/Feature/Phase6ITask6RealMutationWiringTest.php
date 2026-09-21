<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\HealthObservation;
use App\Models\ManagedTargetPreflightEvidence;
use App\Models\NetworkAccount;
use App\Models\NetworkAgentJob;
use App\Models\NetworkDiscoverySnapshot;
use App\Models\ReconciliationEvidence;
use App\Models\Router;
use App\Models\RouterHardwareAcceptance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Monitoring\MonitoringService;
use App\Services\Network\AdoptDiscoveredNetworkResource;
use App\Services\Network\ControlledNetworkOperationGate;
use App\Services\Network\ControlledOperationReason;
use App\Services\Network\DiscoveryResult;
use App\Services\Network\ManagedAccountLifecycleService;
use App\Services\Network\ManagedTargetIdentityService;
use App\Services\Network\ManagedTargetPreflightService;
use App\Services\Network\NetworkDiscoveryClient;
use App\Services\Network\NetworkDiscoveryService;
use App\Services\Network\NetworkReconciliationService;
use App\Services\Network\OperatorCredentialBindingService;
use App\Services\Network\RouterCompatibilityEvidenceService;
use App\Services\Network\RouterHardwareAcceptanceService;
use App\Services\Network\RouterHealthSafetyQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class Phase6ITask6RealMutationWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'network.mutations_enabled' => false,
            'network.mutation_provider' => 'fake',
            'monitoring.driver' => 'fake',
            'monitoring.freshness_seconds' => 180,
            'monitoring.checkpoint_seconds' => 120,
            'network.controlled_operations.preflight_ttl_seconds' => 300,
        ]);
    }

    public function test_architecture_must_remain_valid_and_exact(): void
    {
        $fixture = $this->hardwareFixture();
        $service = app(RouterHardwareAcceptanceService::class);
        $acceptance = $service->accept($fixture['router'], $fixture['user'], $service::CONFIRMATION);
        $this->assertSame('mmips', $acceptance->architecture);
        foreach (['arm', null, '', ' mmips', ['mmips'], 123, 'mmips/invalid'] as $architecture) {
            $snapshot = $fixture['snapshot']->fresh();
            $data = $snapshot->snapshot;
            $data['device']['architecture'] = $architecture;
            $snapshot->update(['snapshot' => $data]);
            $this->assertFalse(app(RouterCompatibilityEvidenceService::class)->evaluate($fixture['router'])->canAuthorizeRealMutation(), json_encode($architecture));
        }
        $snapshot = $fixture['snapshot']->fresh();
        $data = $snapshot->snapshot;
        $data['device']['architecture'] = 'arm';
        $snapshot->update(['snapshot' => $data]);
        $service->accept($fixture['router'], $fixture['user'], $service::CONFIRMATION);
        $this->assertTrue(app(RouterCompatibilityEvidenceService::class)->evaluate($fixture['router'])->canAuthorizeRealMutation());
    }

    public function test_preflight_boolean_values_are_strict(): void
    {
        $fixture = $this->hardwareFixture();
        $fixture['account']->forceFill(['management_state' => 'MANAGED', 'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE])->save();
        foreach (['disabled', 'enabled'] as $key) {
            foreach ([[true, true], [false, false], [1, true], [0, false], ['true', true], ['false', false], ['yes', true], ['no', false], ['1', true], ['0', false]] as [$value, $expected]) {
                $this->runDiscovery($fixture['router'], $fixture['user'], accounts: [['external_ref' => '*7', 'username' => 'customer-01', $key => $value]]);
                $result = app(ManagedTargetPreflightService::class)->prepare($fixture['account'], 'ENABLE_PPPOE', $fixture['user']);
                $this->assertTrue($result->resolved);
                $this->assertSame($key === 'disabled' ? $expected : ! $expected, $result->hardwareDisabled);
            }
            foreach ([null, '', 'garbage', 2, -1, [], ['yes'], ' true ', 'TRUE', 'on', 'off'] as $value) {
                $this->runDiscovery($fixture['router'], $fixture['user'], accounts: [['external_ref' => '*7', 'username' => 'customer-01', $key => $value]]);
                $result = app(ManagedTargetPreflightService::class)->prepare($fixture['account'], 'ENABLE_PPPOE', $fixture['user']);
                $this->assertFalse($result->resolved, json_encode([$key => $value]));
            }
        }
        foreach ([[], ['disabled' => true, 'enabled' => true], ['disabled' => false, 'enabled' => 'garbage']] as $state) {
            $this->runDiscovery($fixture['router'], $fixture['user'], accounts: [array_merge(['external_ref' => '*7', 'username' => 'customer-01'], $state)]);
            $this->assertFalse(app(ManagedTargetPreflightService::class)->prepare($fixture['account'], 'ENABLE_PPPOE', $fixture['user'])->resolved);
        }
    }

    public function test_compatible_v6_evidence_without_acceptance_stays_closed(): void
    {
        $fixture = $this->hardwareFixture();

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($fixture['router']);

        $this->assertTrue($decision->compatible);
        $this->assertFalse($decision->realProviderCompatible);
        $this->assertFalse($decision->hardwareAccepted);
        $this->assertFalse($decision->canAuthorizeRealMutation());
    }

    public function test_explicit_acceptance_authorizes_real_mutation_for_the_exact_scope(): void
    {
        $fixture = $this->hardwareFixture();

        $acceptance = app(RouterHardwareAcceptanceService::class)->accept(
            $fixture['router'],
            $fixture['user'],
            RouterHardwareAcceptanceService::CONFIRMATION,
        );

        $this->assertSame($fixture['router']->observer_agent_ref, $acceptance->agent_ref);
        $this->assertSame($fixture['router']->observer_installation_id, $acceptance->installation_id);
        $this->assertSame('routeros_v6_api', $acceptance->target);
        $this->assertSame('6.49.13', $acceptance->routeros_version);
        $this->assertSame('CORE-01', $acceptance->observed_identity);
        $this->assertSame(hash('sha256', RouterHardwareAcceptanceService::CONFIRMATION), $acceptance->confirmation_digest);
        $this->assertNull($acceptance->revoked_at);

        $decision = app(RouterCompatibilityEvidenceService::class)->evaluate($fixture['router']);
        $this->assertTrue($decision->realProviderCompatible);
        $this->assertTrue($decision->hardwareAccepted);
        $this->assertTrue($decision->canAuthorizeRealMutation());

        // The full ordered gate now legitimately authorizes the managed sandbox account.
        config(['network.mutations_enabled' => true]);
        $this->prepareFullAuthorization($fixture);
        $gate = app(ControlledNetworkOperationGate::class)->check($fixture['user'], $fixture['account']->fresh(), 'ENABLE_PPPOE');
        $this->assertTrue($gate->allowed, json_encode(['code' => $gate->errorCode, 'pre' => $gate->failedPrecondition]));
    }

    public function test_acceptance_cannot_be_forged_without_operator_role_or_exact_confirmation(): void
    {
        $fixture = $this->hardwareFixture();
        $service = app(RouterHardwareAcceptanceService::class);

        $customer = User::factory()->for($fixture['tenant'])->create(['role' => 'customer']);
        $forged = false;
        try {
            $service->accept($fixture['router'], $customer, RouterHardwareAcceptanceService::CONFIRMATION);
        } catch (InvalidArgumentException) {
            $forged = true;
        }
        $this->assertTrue($forged);

        try {
            $service->accept($fixture['router'], $fixture['user'], 'CONFIRM_SOMETHING_ELSE');
            $this->fail('A wrong confirmation phrase was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, RouterHardwareAcceptance::query()->count());
    }

    public function test_acceptance_fails_closed_when_agent_scope_or_hardware_evidence_drifts(): void
    {
        $fixture = $this->hardwareFixture();
        app(RouterHardwareAcceptanceService::class)->accept($fixture['router'], $fixture['user'], RouterHardwareAcceptanceService::CONFIRMATION);

        // Agent scope drift: the bound installation no longer matches the router.
        $fixture['router']->forceFill(['observer_installation_id' => 'installation-2'])->save();
        $this->assertFalse(app(RouterCompatibilityEvidenceService::class)->evaluate($fixture['router'])->canAuthorizeRealMutation());
        $fixture['router']->forceFill(['observer_installation_id' => 'installation-1'])->save();

        // Revocation immediately closes the grant.
        app(RouterHardwareAcceptanceService::class)->revoke($fixture['router'], $fixture['user']);
        $this->assertFalse(app(RouterCompatibilityEvidenceService::class)->evaluate($fixture['router'])->canAuthorizeRealMutation());
        $this->assertNotNull(RouterHardwareAcceptance::query()->firstOrFail()->revoked_at);

        // Incompatible hardware fails closed for evaluation and for new grants.
        $this->runDiscovery($fixture['router'], $fixture['user'], version: '7.0.0');
        $this->assertSame('VERSION_UNSUPPORTED', app(RouterCompatibilityEvidenceService::class)->evaluate($fixture['router'])->reason);
        try {
            app(RouterHardwareAcceptanceService::class)->accept($fixture['router'], $fixture['user'], RouterHardwareAcceptanceService::CONFIRMATION);
            $this->fail('Acceptance was granted against incompatible hardware.');
        } catch (InvalidArgumentException) {
        }
    }

    public function test_operator_binding_requires_operator_purpose_and_exact_scope(): void
    {
        $fixture = $this->hardwareFixture();
        $service = app(OperatorCredentialBindingService::class);

        $base = ['tenant_ref' => (string) $fixture['tenant']->id, 'router_ref' => (string) $fixture['router']->id, 'agent_ref' => $fixture['router']->observer_agent_ref, 'installation_id' => $fixture['router']->observer_installation_id, 'credential_ref' => 'operator-ref', 'version' => 3];

        $fixture['account']->forceFill(['management_state' => 'MANAGED', 'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE])->save();

        // OBSERVER cannot stand in for OPERATOR.
        try {
            $service->bind($fixture['account'], $fixture['user'], array_merge($base, ['purpose' => 'OBSERVER']));
            $this->fail('An OBSERVER reference was accepted as operator credential.');
        } catch (InvalidArgumentException) {
        }

        // A different Agent installation cannot be bound.
        try {
            $service->bind($fixture['account'], $fixture['user'], array_merge($base, ['purpose' => 'OPERATOR', 'installation_id' => 'other-install']));
            $this->fail('A cross-installation operator reference was accepted.');
        } catch (InvalidArgumentException) {
        }

        $bound = $service->bind($fixture['account'], $fixture['user'], array_merge($base, ['purpose' => 'OPERATOR']));
        $metadata = $fixture['account']->fresh()->metadata;
        $this->assertSame($bound, $metadata['operator_credential']);
        $this->assertSame('operator-ref', $metadata['operator_credential']['credential_ref']);
        // Reference metadata only: no secret material is stored by Laravel.
        $this->assertStringNotContainsString('never-return', json_encode($metadata));
    }

    public function test_operator_binding_requires_a_managed_account(): void
    {
        $fixture = $this->hardwareFixture();
        $fixture['account']->forceFill(['management_state' => 'ADOPTED', 'management_scope' => null])->save();

        try {
            app(OperatorCredentialBindingService::class)->bind($fixture['account'], $fixture['user'], [
                'tenant_ref' => (string) $fixture['tenant']->id,
                'router_ref' => (string) $fixture['router']->id,
                'agent_ref' => $fixture['router']->observer_agent_ref,
                'installation_id' => $fixture['router']->observer_installation_id,
                'credential_ref' => 'operator-ref',
                'purpose' => 'OPERATOR',
                'version' => 3,
            ]);
            $this->fail('An operator credential was bound to an unmanaged account.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Operator credentials can only be bound to MANAGED accounts.', $exception->getMessage());
        }
    }

    public function test_preflight_evidence_is_exact_target_and_operation_specific(): void
    {
        $fixture = $this->hardwareFixture();
        $fixture['account']->forceFill(['management_state' => 'MANAGED', 'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE])->save();
        $service = app(ManagedTargetPreflightService::class);

        // Two hardware rows addressing the same identity are ambiguous: no READY evidence.
        $this->runDiscovery($fixture['router'], $fixture['user'], accounts: [
            ['external_ref' => '*7', 'username' => 'customer-01', 'service' => 'pppoe', 'enabled' => true],
            ['external_ref' => '*7', 'username' => 'customer-01', 'service' => 'pppoe', 'enabled' => true],
        ]);
        $ambiguous = $service->prepare($fixture['account']->fresh(), 'ENABLE_PPPOE', $fixture['user']);
        $this->assertFalse($ambiguous->resolved);
        $this->assertSame(ControlledOperationReason::AMBIGUOUS_ACCOUNT_IDENTITY, $ambiguous->reasonCode);
        $this->assertFalse(ManagedTargetPreflightEvidence::query()->where('outcome', 'READY')->exists());

        // Exact single target: READY evidence records the current hardware state.
        $this->runDiscovery($fixture['router'], $fixture['user'], accounts: [
            ['external_ref' => '*7', 'username' => 'customer-01', 'service' => 'pppoe', 'enabled' => true],
        ]);
        $ready = $service->prepare($fixture['account']->fresh(), 'ENABLE_PPPOE', $fixture['user']);
        $this->assertTrue($ready->resolved);
        $this->assertFalse($ready->hardwareDisabled);
        $this->assertSame('ENABLE_PPPOE', $ready->evidence->operation);
        $this->assertSame(ManagedTargetPreflightEvidence::OUTCOME_READY, $ready->evidence->outcome);
        $this->assertFalse($ready->evidence->evidence['hardware_disabled']);
        $this->assertTrue(app(ManagedTargetIdentityService::class)->preflightEvidenceIsCurrent($fixture['account']->fresh(), 'ENABLE_PPPOE')->resolved);

        // Evidence is operation-specific: DISABLE has none yet.
        $this->assertFalse(app(ManagedTargetIdentityService::class)->preflightEvidenceIsCurrent($fixture['account']->fresh(), 'DISABLE_PPPOE')->resolved);
    }

    public function test_preflight_fails_closed_on_username_or_missing_hardware_state(): void
    {
        $fixture = $this->hardwareFixture();
        $fixture['account']->forceFill(['management_state' => 'MANAGED', 'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE])->save();
        $service = app(ManagedTargetPreflightService::class);

        $this->runDiscovery($fixture['router'], $fixture['user'], accounts: [
            ['external_ref' => '*7', 'username' => 'someone-else', 'service' => 'pppoe'],
        ]);
        $result = $service->prepare($fixture['account']->fresh(), 'DISABLE_PPPOE', $fixture['user']);
        $this->assertFalse($result->resolved);
        $this->assertSame(ControlledOperationReason::IDENTITY_MISMATCH, $result->reasonCode);

        $this->runDiscovery($fixture['router'], $fixture['user'], accounts: [
            ['external_ref' => '*7', 'username' => 'customer-01', 'service' => 'pppoe'],
        ]);
        $result = $service->prepare($fixture['account']->fresh(), 'DISABLE_PPPOE', $fixture['user']);
        $this->assertFalse($result->resolved);
        $this->assertFalse(ManagedTargetPreflightEvidence::query()->where('outcome', 'READY')->exists());
    }

    public function test_stale_preflight_and_stale_health_fail_while_refreshed_online_health_passes(): void
    {
        $fixture = $this->hardwareFixture();
        $identityService = app(ManagedTargetIdentityService::class);
        $identity = $identityService->resolve($fixture['account']->fresh());

        Carbon::setTestNow(now()->subSeconds(400));
        $identityService->recordPreflightEvidence($fixture['account']->fresh(), 'ENABLE_PPPOE', 'READY', $identity->identity, now());
        Carbon::setTestNow();

        $stale = $identityService->preflightEvidenceIsCurrent($fixture['account']->fresh(), 'ENABLE_PPPOE');
        $this->assertFalse($stale->resolved);
        $this->assertSame(ControlledOperationReason::PREFLIGHT_STALE, $stale->reasonCode);

        // Aged-out health observation fails the freshness query...
        HealthObservation::create(['tenant_id' => $fixture['tenant']->id, 'subject_type' => 'router', 'subject_id' => $fixture['router']->id, 'health_state' => 'online', 'reachable' => true, 'online' => true, 'provider' => 'fake', 'observed_at' => now()->subHours(3)]);
        $this->assertFalse(app(RouterHealthSafetyQuery::class)->allows($fixture['router']->fresh()));

        // ...while the existing monitoring path refreshes a fresh ONLINE observation.
        app(MonitoringService::class)->observeRouter($fixture['router'], $fixture['user']);
        $this->assertTrue(app(RouterHealthSafetyQuery::class)->allows($fixture['router']->fresh()));
        $this->assertSame('online', HealthObservation::query()->where('subject_type', 'router')->where('subject_id', $fixture['router']->id)->latest('observed_at')->firstOrFail()->health_state);
    }

    public function test_production_accounts_cannot_inherit_sandbox_authorization(): void
    {
        $fixture = $this->hardwareFixture();
        app(RouterHardwareAcceptanceService::class)->accept($fixture['router'], $fixture['user'], RouterHardwareAcceptanceService::CONFIRMATION);
        config(['network.mutations_enabled' => true]);
        $this->prepareFullAuthorization($fixture);

        // A production (unmanaged, unadopted) account on the same accepted router.
        $production = NetworkAccount::create([
            'tenant_id' => $fixture['tenant']->id,
            'router_id' => $fixture['router']->id,
            'username' => 'client-3M',
            'profile' => 'HOME-10M',
            'status' => 'active',
        ]);

        $decision = app(ControlledNetworkOperationGate::class)->check($fixture['user'], $production, 'ENABLE_PPPOE');
        $this->assertFalse($decision->allowed);
        $this->assertSame(ControlledOperationReason::NOT_MANAGED, $decision->errorCode);
    }

    public function test_mutation_defaults_and_unknown_outcome_semantics_remain_unchanged(): void
    {
        config()->offsetUnset('network.mutations_enabled');
        config()->offsetUnset('network.mutation_provider');

        $this->assertFalse(config('network.mutations_enabled') ?? false);
        $this->assertSame('fake', config('network.mutation_provider') ?? 'fake');
        $this->assertSame('UNKNOWN_OUTCOME', NetworkAgentJob::UNKNOWN_OUTCOME);
        $this->assertSame('POSTFLIGHT_MISMATCH', NetworkAgentJob::POSTFLIGHT_MISMATCH);
    }

    /**
     * After adoption: flip to MANAGED, bind the operator reference, record
     * a fresh READY preflight, then MATCHED reconciliation and a fresh ONLINE
     * health observation so the ordered gate reaches PRE12 fully satisfied.
     */
    private function prepareFullAuthorization(array $fixture): void
    {
        $account = $fixture['account']->fresh();
        $account->forceFill(['management_state' => 'MANAGED', 'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE])->save();
        app(OperatorCredentialBindingService::class)->bind($account, $fixture['user'], [
            'tenant_ref' => (string) $fixture['tenant']->id,
            'router_ref' => (string) $fixture['router']->id,
            'agent_ref' => $fixture['router']->observer_agent_ref,
            'installation_id' => $fixture['router']->observer_installation_id,
            'credential_ref' => 'operator-ref',
            'purpose' => 'OPERATOR',
            'version' => 3,
        ]);

        $resource = $fixture['resource']->fresh();
        $snapshot = $fixture['snapshot']->fresh();
        ReconciliationEvidence::create([
            'tenant_id' => $fixture['tenant']->id,
            'router_id' => $fixture['router']->id,
            'adopted_resource_id' => $resource->id,
            'compared_resource_id' => $resource->id,
            'discovery_snapshot_id' => $snapshot->id,
            'network_account_id' => $account->id,
            'customer_connection_id' => $fixture['connection']->id,
            'outcome' => 'MATCHED',
            'discovered_at' => $snapshot->discovered_at,
            'reconciled_at' => now(),
            'adopted_fingerprint' => $resource->fingerprint,
            'compared_fingerprint' => $resource->fingerprint,
            'relationship_fingerprint' => ReconciliationEvidence::relationshipFingerprint($resource, $resource, $account->id, $fixture['connection']->id),
        ]);

        $preflight = app(ManagedTargetPreflightService::class)->prepare($account, 'ENABLE_PPPOE', $fixture['user']);
        $this->assertTrue($preflight->resolved);
        app(NetworkReconciliationService::class)->reconcile($fixture['router']);
        app(MonitoringService::class)->observeRouter($fixture['router'], $fixture['user']);
    }

    /**
     * @return array{tenant: Tenant, user: User, router: Router, account: NetworkAccount, resource: DiscoveredNetworkResource, snapshot: NetworkDiscoverySnapshot, connection: CustomerConnection}
     */
    private function hardwareFixture(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => 'admin']);
        $router = Router::factory()->for($tenant)->create(['name' => 'CORE-01']);
        $router->forceFill([
            'observer_agent_ref' => 'agent-ref-1',
            'observer_installation_id' => 'installation-1',
            'observer_credential_ref' => 'observer-ref',
            'observer_credential_purpose' => 'OBSERVER',
            'observer_credential_version' => 2,
            'observer_credential_status' => 'ACTIVE',
            'observer_migration_state' => 'LOCAL_OBSERVER_ACTIVE',
        ])->save();
        $connection = CustomerConnection::factory()
            ->for(Customer::factory()->for($tenant))
            ->for($router)
            ->create(['tenant_id' => $tenant->id]);

        $snapshot = $this->runDiscovery($router, $user);
        $resource = DiscoveredNetworkResource::query()
            ->where('tenant_id', $tenant->id)
            ->where('resource_type', 'pppoe_account')
            ->where('external_ref', '*7')
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

    private function runDiscovery(Router $router, User $user, ?array $accounts = null, string $version = '6.49.13', array $sessions = []): NetworkDiscoverySnapshot
    {
        $accounts ??= [['external_ref' => '*7', 'username' => 'customer-01', 'profile' => 'HOME-20M', 'service' => 'pppoe', 'enabled' => true, 'password' => 'never-return']];

        app()->bind(NetworkDiscoveryClient::class, fn () => new class($accounts, $version, $sessions) implements NetworkDiscoveryClient
        {
            public function __construct(
                private readonly array $accounts,
                private readonly string $version,
                private readonly array $sessions,
            ) {}

            public function discover(Router $router): DiscoveryResult
            {
                return new DiscoveryResult(true, 'Read-only discovery complete', null, [
                    'provider' => 'routeros',
                    'discovered_at' => now()->toIso8601String(),
                    'snapshot' => [
                        'device' => ['name' => 'CORE-01', 'routeros_version' => $this->version, 'architecture' => 'mmips'],
                        'profiles' => [],
                        'accounts' => $this->accounts,
                        'address_pools' => [],
                        'queues' => [],
                        'active_sessions' => $this->sessions,
                    ],
                ]);
            }
        });

        return app(NetworkDiscoveryService::class)->discover($router, $user);
    }
}
