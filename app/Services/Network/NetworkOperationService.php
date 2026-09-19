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

    public function __construct(private readonly NetworkDriver $driver) {}

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
        $result = $this->execute($operation, $account->router, $account->username, [], fn () => $status === 'active'
            ? $this->driver->enablePppoeAccount($account->router, $account->username)
            : $this->driver->disablePppoeAccount($account->router, $account->username), $user, $connection, $account, $idempotencyKey);
        if ($result->successful) {
            $account->update(['status' => $status]);
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
        return $this->execute('DISCONNECT_SESSION', $account->router, $account->username, [], fn () => $this->driver->disconnectPppoeSession($account->router, $account->username), $user, account: $account);
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

        return $this->changeStatus($account, $status, $user, $connection);
    }

    private function execute(string $operation, Router $router, ?string $target, array $payload, callable $callback, ?User $user, ?CustomerConnection $connection = null, ?NetworkAccount $account = null, ?string $idempotencyKey = null): NetworkOperationResult
    {
        $started = Carbon::now();
        $safePayload = $this->sanitize($payload);
        if (! in_array($operation, self::CONTROLLED_OPERATIONS, true)) {
            $result = $callback();
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
        $idempotencyKey ??= $this->defaultIdempotencyKey($operation, $router, $target, $safePayload, $account);
        $requestDigest = hash('sha256', json_encode([$operation, $router->tenant_id, $router->id, $account?->id, $target, $safePayload], JSON_THROW_ON_ERROR));
        $scope = $account ? 'account:'.$account->id : 'router:'.$router->id;
        $reservation = $this->reserve($router, $account, $connection, $operation, $target, $safePayload, $user, $idempotencyKey, $requestDigest, $scope, $started);

        if ($reservation instanceof NetworkOperationResult) {
            return $reservation;
        }

        try {
            $result = $callback();
        } catch (\Throwable) {
            $result = new NetworkOperationResult(false, 'Network operation failed.', 'NETWORK_ENGINE_UNAVAILABLE');
        }

        $outcome = $this->outcomeFor($result);
        $status = match ($outcome) {
            'SUCCEEDED' => 'success',
            'UNKNOWN_OUTCOME' => 'unknown',
            'POSTFLIGHT_MISMATCH' => 'postflight_mismatch',
            default => 'failed',
        };

        $reservation->update([
            'status' => $status,
            'outcome' => $outcome,
            'result_payload' => $this->sanitize(['successful' => $result->successful, 'message' => $result->message, 'data' => $result->data]),
            'error_message' => $result->successful ? null : $result->message,
            'failure_code' => $result->successful ? null : $result->errorCode,
            'completed_at' => Carbon::now(),
        ]);

        return $result;
    }

    private function reserve(Router $router, ?NetworkAccount $account, ?CustomerConnection $connection, string $operation, ?string $target, array $payload, ?User $user, string $idempotencyKey, string $requestDigest, string $scope, Carbon $started): NetworkOperationLog|NetworkOperationResult
    {
        return DB::transaction(function () use ($router, $account, $connection, $operation, $target, $payload, $user, $idempotencyKey, $requestDigest, $scope, $started): NetworkOperationLog|NetworkOperationResult {
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

    private function outcomeFor(NetworkOperationResult $result): string
    {
        if ($result->successful) {
            return 'SUCCEEDED';
        }
        if ($result->errorCode === 'POSTFLIGHT_MISMATCH') {
            return 'POSTFLIGHT_MISMATCH';
        }
        if (in_array($result->errorCode, self::AMBIGUOUS_FAILURE_CODES, true)) {
            return 'UNKNOWN_OUTCOME';
        }

        return 'FAILED';
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
