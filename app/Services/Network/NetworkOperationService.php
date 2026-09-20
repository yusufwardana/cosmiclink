<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NetworkOperationService
{
    private const CONTROLLED_OPERATIONS = ['ENABLE_PPPOE', 'DISABLE_PPPOE', 'DISCONNECT_SESSION'];

    private const AMBIGUOUS_FAILURE_CODES = [
        'NETWORK_ENGINE_TIMEOUT',
        'NETWORK_ENGINE_UNAVAILABLE',
        'NETWORK_ENGINE_INVALID_RESPONSE',
    ];

    public function __construct(
        private readonly NetworkDriver $driver,
        private readonly ControlledNetworkOperationGate $controlledGate,
    ) {}

    public function testConnection(Router $router, ?User $user = null): NetworkOperationResult
    {
        return $this->execute('TEST_CONNECTION', $router, null, [], fn () => $this->driver->testConnection($router), $user);
    }

    public function createAccount(Router $router, array $account, ?User $user = null, ?CustomerConnection $connection = null): NetworkOperationResult
    {
        return $this->execute('CREATE_PPPOE', $router, $account['username'], $account, fn () => $this->driver->createPppoeAccount($router, $account), $user, $connection);
    }

    public function changeStatus(NetworkAccount $account, string $status, ?User $user = null, ?CustomerConnection $connection = null, ?string $idempotencyKey = null): NetworkOperationResult
    {
        $operation = $status === 'active' ? 'ENABLE_PPPOE' : 'DISABLE_PPPOE';
        $result = $this->execute($operation, $account->router, $account->username, [], fn (NetworkAccount $currentAccount) => $status === 'active'
            ? $this->driver->enablePppoeAccount($currentAccount->router, $currentAccount->username)
            : $this->driver->disablePppoeAccount($currentAccount->router, $currentAccount->username), $user, $connection, $account, $idempotencyKey);
        if ($result->successful) {
            NetworkAccount::query()->whereKey($account->id)->update(['status' => $status]);
        }

        return $result;
    }

    public function changeProfile(NetworkAccount $account, string $profile, ?User $user = null): NetworkOperationResult
    {
        $result = $this->execute('CHANGE_PROFILE', $account->router, $account->username, ['profile' => $profile], fn () => $this->driver->changePppoeProfile($account->router, $account->username, $profile), $user, account: $account);
        if ($result->successful) {
            $account->update(['profile' => $profile]);
        }

        return $result;
    }

    public function disconnect(NetworkAccount $account, ?User $user = null): NetworkOperationResult
    {
        return $this->execute('DISCONNECT_SESSION', $account->router, $account->username, [], fn (NetworkAccount $currentAccount) => $this->driver->disconnectPppoeSession($currentAccount->router, $currentAccount->username), $user, account: $account);
    }

    public function billingMayExecute(): bool
    {
        return $this->driver instanceof FakeNetworkDriver && config('network.mutations_enabled', false) === false;
    }

    public function changeStatusForBilling(NetworkAccount $account, string $status, ?User $user = null, ?CustomerConnection $connection = null): NetworkOperationResult
    {
        if (! $this->billingMayExecute()) {
            return new NetworkOperationResult(false, 'Billing network operations are simulation-only.', 'BILLING_NETWORK_DISPATCH_BLOCKED');
        }

        if ($user === null || $user->tenant_id !== $account->tenant_id || data_get($account->metadata, 'adopted_from_discovery', false)) {
            return new NetworkOperationResult(false, 'Billing network simulation is not authorized for this account.', ControlledOperationReason::OPERATION_NOT_ALLOWED);
        }

        $operation = $status === 'active' ? 'ENABLE_PPPOE' : 'DISABLE_PPPOE';
        $result = $this->execute($operation, $account->router, $account->username, [], fn () => $status === 'active'
            ? $this->driver->enablePppoeAccount($account->router, $account->username)
            : $this->driver->disablePppoeAccount($account->router, $account->username), $user, $connection, $account, null, false);

        if ($result->successful) {
            $account->update(['status' => $status]);
        }

        return $result;
    }

    private function execute(string $operation, Router $router, ?string $target, array $payload, callable $callback, ?User $user, ?CustomerConnection $connection = null, ?NetworkAccount $account = null, ?string $idempotencyKey = null, bool $controlled = true): NetworkOperationResult
    {
        $started = Carbon::now();
        $safePayload = $this->sanitize($payload);
        $defaultFakeSimulation = $this->driver instanceof FakeNetworkDriver && config('network.mutations_enabled', false) === false;
        if (! $controlled || ! in_array($operation, self::CONTROLLED_OPERATIONS, true) || $defaultFakeSimulation) {
            $result = $defaultFakeSimulation && $account ? $callback($account) : $callback();
            NetworkOperationLog::create([
                'tenant_id' => $router->tenant_id,
                'router_id' => $router->id,
                'customer_connection_id' => $connection?->id,
                'network_account_id' => $account?->id,
                'initiated_by_user_id' => $user?->id,
                'operation' => $operation,
                'target' => $target,
                'request_payload' => $safePayload,
                'result_payload' => $this->sanitize(['successful' => $result->successful, 'message' => $result->message, 'data' => $result->data]),
                'status' => $result->successful ? 'success' : 'failed',
                'outcome' => $result->successful ? 'SUCCEEDED' : 'FAILED',
                'error_message' => $result->successful ? null : $result->message,
                'failure_code' => $result->successful ? null : $result->errorCode,
                'started_at' => $started,
                'completed_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);

            return $result;
        }
        if ($account === null || $user === null) {
            return new NetworkOperationResult(false, 'Controlled network operation authorization is required.', ControlledOperationReason::OPERATION_NOT_ALLOWED);
        }

        // Re-read the account at the dispatch boundary so stale browser/request
        // state cannot authorize an operation after management was revoked.
        $currentAccount = NetworkAccount::query()->find($account->id);
        if ($currentAccount === null) {
            return new NetworkOperationResult(false, 'Controlled network operation authorization is required.', ControlledOperationReason::TARGET_NOT_FOUND);
        }

        $currentRouter = $currentAccount->router;
        $currentTarget = $currentAccount->username;
        $currentPayload = $safePayload;
        $decision = $this->controlledGate->check($user, $currentAccount, $operation);
        if (! $decision->allowed) {
            return new NetworkOperationResult(false, 'Controlled network operation denied.', $decision->errorCode);
        }
        $idempotencyKey ??= $this->defaultIdempotencyKey($operation, $currentRouter, $currentTarget, $currentPayload, $currentAccount);
        $requestDigest = hash('sha256', json_encode([$operation, $currentRouter->tenant_id, $currentRouter->id, $currentAccount->id, $currentTarget, $currentPayload], JSON_THROW_ON_ERROR));
        $executionId = 'network-operation-'.bin2hex(random_bytes(16));
        $scope = 'account:'.$currentAccount->id;
        $reservation = $this->reserve($currentRouter, $currentAccount, $connection, $operation, $currentTarget, $currentPayload, $user, $idempotencyKey, $requestDigest, $executionId, $scope, $started);

        if ($reservation instanceof NetworkOperationResult) {
            return $reservation;
        }

        try {
            $job = app(NetworkAgentService::class)->createMutationJob($reservation, $currentAccount, $user);
        } catch (\Throwable) {
            $reservation->update([
                'status' => 'failed',
                'outcome' => 'FAILED',
                'result_payload' => ['successful' => false, 'message' => 'Controlled mutation job could not be created.', 'data' => []],
                'error_message' => 'Controlled mutation job could not be created.',
                'failure_code' => 'MUTATION_JOB_CREATION_FAILED',
                'completed_at' => Carbon::now(),
            ]);

            return new NetworkOperationResult(false, 'Controlled mutation job could not be created.', 'MUTATION_JOB_CREATION_FAILED');
        }

        return new NetworkOperationResult(false, 'Controlled network operation accepted for Agent execution.', 'NETWORK_OPERATION_PENDING', [
            'network_operation_log_id' => $reservation->id,
            'network_agent_job_id' => $job->id,
            'execution_id' => $reservation->execution_id,
        ]);
    }

    private function reserve(Router $router, ?NetworkAccount $account, ?CustomerConnection $connection, string $operation, ?string $target, array $payload, ?User $user, string $idempotencyKey, string $requestDigest, string $executionId, string $scope, Carbon $started): NetworkOperationLog|NetworkOperationResult
    {
        return DB::transaction(function () use ($router, $account, $connection, $operation, $target, $payload, $user, $idempotencyKey, $requestDigest, $executionId, $scope, $started): NetworkOperationLog|NetworkOperationResult {
            if ($account) {
                NetworkAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            } else {
                Router::query()->whereKey($router->id)->lockForUpdate()->firstOrFail();
            }

            $existing = NetworkOperationLog::query()->where('tenant_id', $router->tenant_id)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_digest !== $requestDigest) {
                    return new NetworkOperationResult(false, 'Idempotency key conflicts with a different request.', 'IDEMPOTENCY_CONFLICT');
                }
                if (in_array($existing->outcome, ['SUCCEEDED', 'FAILED'], true)) {
                    return new NetworkOperationResult((bool) data_get($existing->result_payload, 'successful'), (string) data_get($existing->result_payload, 'message', ''), $existing->failure_code, (array) data_get($existing->result_payload, 'data', []));
                }
                if ($existing->status === 'reserved' && $existing->outcome === null) {
                    $job = $existing->agentJob()->first();

                    return new NetworkOperationResult(false, 'Controlled network operation is pending Agent execution.', 'NETWORK_OPERATION_PENDING', [
                        'network_operation_log_id' => $existing->id,
                        'network_agent_job_id' => $job?->id,
                        'execution_id' => $existing->execution_id,
                    ]);
                }

                return new NetworkOperationResult(false, 'A conflicting network operation is unresolved.', 'CONCURRENT_OPERATION');
            }

            $conflict = NetworkOperationLog::query()
                ->where('tenant_id', $router->tenant_id)
                ->where(function ($query) use ($scope, $account, $router): void {
                    $query->where('safety_scope', $scope);
                    if ($account) {
                        $query->orWhere(function ($query) use ($account): void {
                            $query->where('network_account_id', $account->id)->where('router_id', $account->router_id);
                        });
                    } else {
                        $query->orWhere('router_id', $router->id);
                    }
                })
                ->whereIn('status', ['reserved', 'preflighting', 'executing', 'verifying', 'unknown', 'postflight_mismatch'])
                ->whereNull('resolved_at')
                ->exists();
            if ($conflict) {
                return new NetworkOperationResult(false, 'A conflicting network operation is unresolved.', 'CONCURRENT_OPERATION');
            }

            return NetworkOperationLog::create([
                'tenant_id' => $router->tenant_id,
                'router_id' => $router->id,
                'customer_connection_id' => $connection?->id,
                'network_account_id' => $account?->id,
                'initiated_by_user_id' => $user?->id,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_digest' => $requestDigest,
                'execution_id' => $executionId,
                'provider' => config('network.mutation_provider', 'fake'),
                'execution_mode' => config('network.mutation_provider', 'fake') === 'fake' ? 'simulation' : 'real',
                'target' => $target,
                'request_payload' => $payload,
                'status' => 'reserved',
                'safety_scope' => $scope,
                'started_at' => $started,
                'reserved_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        });
    }

    private function defaultIdempotencyKey(string $operation, Router $router, ?string $target, array $payload, ?NetworkAccount $account): string
    {
        return hash('sha256', implode('|', [$operation, $router->tenant_id, $router->id, $account?->id ?? '', $target ?? '', json_encode($payload) ?: '']));
    }

    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'secret', 'token', 'credentials'], true)) {
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }
}
