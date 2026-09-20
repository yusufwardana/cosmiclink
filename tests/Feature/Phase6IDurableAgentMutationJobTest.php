<?php

namespace Tests\Feature;

use App\Models\NetworkAccount;
use App\Models\NetworkAgentJob;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\ControlledNetworkOperationDecision;
use App\Services\Network\ControlledNetworkOperationGate;
use App\Services\Network\ManagedAccountLifecycleService;
use App\Services\Network\NetworkAgentService;
use App\Services\Network\NetworkOperationSafetyQuery;
use App\Services\Network\NetworkOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class Phase6IDurableAgentMutationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['network.mutations_enabled' => true, 'network.mutation_provider' => 'fake']);
        $gate = Mockery::mock(ControlledNetworkOperationGate::class);
        $gate->shouldReceive('check')->andReturn(ControlledNetworkOperationDecision::allow());
        $this->app->instance(ControlledNetworkOperationGate::class, $gate);
    }

    public function test_enable_disable_and_disconnect_are_reserved_as_durable_jobs_and_claim_the_strict_contract_without_http_mutation(): void
    {
        foreach ([
            ['status', 'active', NetworkAgentJob::MUTATE_ENABLE_PPPOE],
            ['status', 'disabled', NetworkAgentJob::MUTATE_DISABLE_PPPOE],
            ['disconnect', null, NetworkAgentJob::MUTATE_DISCONNECT_SESSION],
        ] as [$method, $value, $jobType]) {
            $fixture = $this->fixture('agent-'.$jobType);
            Http::fake();

            $result = $method === 'status'
                ? app(NetworkOperationService::class)->changeStatus($fixture['account'], $value, $fixture['user'], idempotencyKey: 'key-'.$jobType)
                : app(NetworkOperationService::class)->disconnect($fixture['account'], $fixture['user']);

            $this->assertFalse($result->successful);
            $this->assertSame('NETWORK_OPERATION_PENDING', $result->errorCode);
            Http::assertNothingSent();
            $job = NetworkAgentJob::where('network_account_id', $fixture['account']->id)->sole();
            $log = NetworkOperationLog::findOrFail($job->network_operation_log_id);
            $this->assertSame($jobType, $job->job_type);
            $this->assertSame('reserved', $log->status);
            $this->assertNull($log->outcome);

            $claim = $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/claim')->assertOk()->json('job');
            $this->assertSame($jobType, $claim['type']);
            $this->assertSame('routeros-mutation.v1', $claim['protocol_version']);
            $this->assertSame($log->execution_id, $claim['execution_id']);
            $this->assertSame($log->idempotency_key, $claim['idempotency_key']);
            $this->assertSame($log->request_digest, $claim['request_digest']);
            $this->assertSame((string) $log->id, $claim['fencing_ref']);
            $this->assertSame('operator-ref', $claim['credential_ref']);
            $this->assertSame('OPERATOR', $claim['credential_purpose']);
            $this->assertSame('observer-ref', $claim['observer_credential_ref']);
            $this->assertSame('OBSERVER', $claim['observer_credential_purpose']);
            $this->assertSame('*7', $claim['target_identity_ref']);
            $this->assertSame('customer-01', $claim['account_ref']);
            $encoded = json_encode($claim, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('password', strtolower($encoded));
            $this->assertStringNotContainsString('secret', strtolower($encoded));
        }
    }

    public function test_mutation_results_are_correlated_mapped_and_idempotent_while_conflicts_are_rejected(): void
    {
        foreach ([
            ['ACCOUNT_ENABLED', true, 'success', 'SUCCEEDED'],
            ['MUTATION_FAILED', false, 'failed', 'FAILED'],
            ['UNKNOWN_OUTCOME', false, 'unknown', 'UNKNOWN_OUTCOME'],
            ['POSTFLIGHT_MISMATCH', false, 'postflight_mismatch', 'POSTFLIGHT_MISMATCH'],
        ] as [$code, $success, $status, $outcome]) {
            $fixture = $this->fixture('result-'.$code);
            app(NetworkOperationService::class)->changeStatus($fixture['account'], 'active', $fixture['user'], idempotencyKey: 'result-'.$code);
            $job = NetworkAgentJob::where('network_account_id', $fixture['account']->id)->sole();
            $claim = $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/claim')->assertOk()->json('job');
            $payload = $this->resultPayload($job, $claim, $success, $code);

            $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $payload)->assertOk();
            $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $payload)->assertOk();
            $this->assertDatabaseHas('network_operation_logs', ['id' => $job->network_operation_log_id, 'status' => $status, 'outcome' => $outcome]);

            $conflict = array_replace($payload, ['code' => 'CONFLICTING_RESULT', 'message' => 'different']);
            $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $conflict)->assertConflict();
            $this->assertDatabaseHas('network_operation_logs', ['id' => $job->network_operation_log_id, 'status' => $status, 'outcome' => $outcome]);
        }
    }

    public function test_stale_or_mismatched_mutation_result_correlation_is_rejected(): void
    {
        $fixture = $this->fixture('correlation-agent');
        app(NetworkOperationService::class)->changeStatus($fixture['account'], 'active', $fixture['user'], idempotencyKey: 'correlation-key');
        $job = NetworkAgentJob::where('network_account_id', $fixture['account']->id)->sole();
        $claim = $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/claim')->assertOk()->json('job');
        $base = $this->resultPayload($job, $claim, true, 'ACCOUNT_ENABLED');

        foreach ([
            ['execution_id' => 'stale-execution'],
            ['request_digest' => str_repeat('f', 64)],
            ['idempotency_key' => 'wrong-key'],
            ['tenant_ref' => '999999'],
            ['router_ref' => '999999'],
            ['agent_ref' => 'wrong-agent'],
            ['fencing_ref' => 'wrong-reservation'],
            ['job_type' => NetworkAgentJob::MUTATE_DISABLE_PPPOE],
        ] as $override) {
            $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/'.$job->id.'/result', array_replace($base, $override))->assertConflict();
        }

        $other = $this->fixture('other-agent');
        $this->withToken($other['token'])->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $base)->assertForbidden();
        $this->assertDatabaseHas('network_operation_logs', ['id' => $job->network_operation_log_id, 'status' => 'reserved', 'outcome' => null]);
    }

    public function test_adopted_or_revoked_accounts_and_billing_cannot_create_mutation_jobs(): void
    {
        $gate = Mockery::mock(ControlledNetworkOperationGate::class);
        $gate->shouldReceive('check')->andReturn(ControlledNetworkOperationDecision::deny('MUTATION_JOB_NOT_AUTHORIZED'));
        $this->app->instance(ControlledNetworkOperationGate::class, $gate);
        $fixture = $this->fixture('state-agent');
        $fixture['account']->update(['management_state' => 'ADOPTED']);
        $result = app(NetworkOperationService::class)->changeStatus($fixture['account']->fresh(), 'active', $fixture['user']);
        $this->assertSame('MUTATION_JOB_NOT_AUTHORIZED', $result->errorCode);
        $this->assertDatabaseCount('network_agent_jobs', 0);

        $fixture['account']->update(['management_state' => 'REVOKED']);
        $result = app(NetworkOperationService::class)->changeStatus($fixture['account']->fresh(), 'active', $fixture['user']);
        $this->assertSame('MUTATION_JOB_NOT_AUTHORIZED', $result->errorCode);
        $this->assertDatabaseCount('network_agent_jobs', 0);

        config(['network.mutations_enabled' => false]);
        app(NetworkOperationService::class)->changeStatusForBilling($fixture['account']->fresh(), 'active');
        $this->assertDatabaseCount('network_agent_jobs', 0);
    }

    public function test_pending_dispatch_replay_returns_the_same_job_without_creating_another_mutation(): void
    {
        $fixture = $this->fixture('pending-replay-agent');
        $service = app(NetworkOperationService::class);

        $first = $service->changeStatus($fixture['account'], 'active', $fixture['user'], idempotencyKey: 'pending-replay-key');
        $replay = $service->changeStatus($fixture['account'], 'active', $fixture['user'], idempotencyKey: 'pending-replay-key');

        $this->assertSame('NETWORK_OPERATION_PENDING', $first->errorCode);
        $this->assertSame('NETWORK_OPERATION_PENDING', $replay->errorCode);
        $this->assertSame($first->data['network_operation_log_id'], $replay->data['network_operation_log_id']);
        $this->assertSame($first->data['network_agent_job_id'], $replay->data['network_agent_job_id']);
        $this->assertDatabaseCount('network_operation_logs', 1);
        $this->assertDatabaseCount('network_agent_jobs', 1);
    }

    public function test_claimed_mutation_lease_expiry_becomes_unknown_and_blocks_future_operations(): void
    {
        $fixture = $this->fixture('expired-agent');
        app(NetworkOperationService::class)->changeStatus($fixture['account'], 'active', $fixture['user'], idempotencyKey: 'expired-key');
        $job = NetworkAgentJob::where('network_account_id', $fixture['account']->id)->sole();
        $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/claim')->assertOk();
        $job->update(['lease_expires_at' => now()->subSecond()]);

        $this->assertSame(1, app(NetworkAgentService::class)->recover());
        $this->assertSame(NetworkAgentJob::UNKNOWN_OUTCOME, $job->fresh()->status);
        $this->assertDatabaseHas('network_operation_logs', [
            'id' => $job->network_operation_log_id,
            'status' => 'unknown',
            'outcome' => 'UNKNOWN_OUTCOME',
        ]);
        $this->assertTrue(app(NetworkOperationSafetyQuery::class)->hasUnresolvedState($fixture['account']));
    }

    public function test_mutation_result_secret_fields_are_rejected_or_sanitized_from_all_durable_evidence(): void
    {
        $fixture = $this->fixture('secret-agent');
        app(NetworkOperationService::class)->changeStatus($fixture['account'], 'active', $fixture['user'], idempotencyKey: 'secret-key');
        $job = NetworkAgentJob::where('network_account_id', $fixture['account']->id)->sole();
        $claim = $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/claim')->assertOk()->json('job');
        $payload = $this->resultPayload($job, $claim, true, 'ACCOUNT_ENABLED');
        $payload['data'] = ['written' => true, 'password' => 'never-store', 'nested' => ['secret' => 'never-store']];

        $this->withToken($fixture['token'])->postJson('/api/v1/agent/jobs/'.$job->id.'/result', $payload)->assertOk();

        foreach ([$job->fresh()->toArray(), $job->operationLog->fresh()->toArray()] as $record) {
            $encoded = strtolower(json_encode($record, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('never-store', $encoded);
            $this->assertStringNotContainsString('password', $encoded);
            $this->assertStringNotContainsString('"secret"', $encoded);
        }
    }

    public function test_stale_managed_model_cannot_create_a_job_after_management_is_revoked_in_storage(): void
    {
        $fixture = $this->fixture('stale-create-agent');
        $reservation = NetworkOperationLog::create([
            'tenant_id' => $fixture['tenant']->id,
            'router_id' => $fixture['router']->id,
            'network_account_id' => $fixture['account']->id,
            'initiated_by_user_id' => $fixture['user']->id,
            'operation' => 'ENABLE_PPPOE',
            'idempotency_key' => 'stale-create-key',
            'request_digest' => str_repeat('a', 64),
            'execution_id' => 'network-operation-stale-create',
            'provider' => 'fake',
            'execution_mode' => 'simulation',
            'target' => $fixture['account']->username,
            'request_payload' => [],
            'status' => 'reserved',
            'safety_scope' => 'account:'.$fixture['account']->id,
            'started_at' => now(),
            'reserved_at' => now(),
            'created_at' => now(),
        ]);
        NetworkAccount::query()->whereKey($fixture['account']->id)->update([
            'management_state' => 'ADOPTED',
            'management_scope' => null,
            'revoked_at' => now(),
        ]);

        try {
            app(NetworkAgentService::class)->createMutationJob($reservation, $fixture['account'], $fixture['user']);
            $this->fail('A stale MANAGED model created a mutation job after revocation.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('network_agent_jobs', 0);
        }
    }

    private function fixture(string $agentName): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => 'admin']);
        [$agent, $token] = app(NetworkAgentService::class)->enroll($tenant, $agentName);
        $agent = app(NetworkAgentService::class)->heartbeat($agent, ['version' => '0.6.0', 'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1']]);
        $router = Router::factory()->for($tenant)->create();
        $router->forceFill([
            'observer_agent_ref' => $agent->identifier,
            'observer_installation_id' => 'installation-1',
            'observer_credential_ref' => 'observer-ref',
            'observer_credential_purpose' => 'OBSERVER',
            'observer_credential_version' => 2,
            'observer_credential_status' => 'ACTIVE',
            'observer_migration_state' => 'LOCAL_OBSERVER_ACTIVE',
        ])->save();
        $account = NetworkAccount::create([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'username' => 'customer-01',
            'profile' => 'HOME-10M',
            'status' => 'disabled',
            'management_state' => 'MANAGED',
            'management_scope' => ManagedAccountLifecycleService::MANAGEMENT_SCOPE,
            'router_identity_ref' => '*7',
            'metadata' => ['operator_credential' => [
                'agent_ref' => $agent->identifier,
                'installation_id' => 'installation-1',
                'credential_ref' => 'operator-ref',
                'purpose' => 'OPERATOR',
                'version' => 3,
            ]],
        ]);

        return compact('tenant', 'user', 'agent', 'token', 'router', 'account');
    }

    private function resultPayload(NetworkAgentJob $job, array $claim, bool $success, string $code): array
    {
        return [
            'attempt' => $claim['attempt'],
            'fence' => $claim['fence'],
            'success' => $success,
            'provider' => 'fake',
            'code' => $code,
            'message' => $success ? 'completed' : 'failed',
            'data' => ['written' => $success],
            'job_type' => $job->job_type,
            'tenant_ref' => (string) $job->tenant_id,
            'router_ref' => (string) $job->router_id,
            'agent_ref' => (string) $job->agent->identifier,
            'execution_id' => $job->execution_id,
            'idempotency_key' => $job->idempotency_key,
            'request_digest' => $job->request_digest,
            'fencing_ref' => $job->reservation_ref,
        ];
    }
}
