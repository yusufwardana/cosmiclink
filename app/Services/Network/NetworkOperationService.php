<?php

namespace App\Services\Network;

use App\Models\CustomerConnection;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\User;
use Illuminate\Support\Carbon;

class NetworkOperationService
{
    public function __construct(private readonly NetworkDriver $driver) {}

    public function testConnection(Router $router, ?User $user = null): NetworkOperationResult
    {
        return $this->execute('TEST_CONNECTION', $router, null, [], fn () => $this->driver->testConnection($router), $user);
    }

    public function createAccount(Router $router, array $account, ?User $user = null, ?CustomerConnection $connection = null): NetworkOperationResult
    {
        return $this->execute('CREATE_PPPOE', $router, $account['username'], $account, fn () => $this->driver->createPppoeAccount($router, $account), $user, $connection);
    }

    public function changeStatus(NetworkAccount $account, string $status, ?User $user = null, ?CustomerConnection $connection = null): NetworkOperationResult
    {
        $operation = $status === 'active' ? 'ENABLE_PPPOE' : 'DISABLE_PPPOE';
        $result = $this->execute($operation, $account->router, $account->username, [], fn () => $status === 'active'
            ? $this->driver->enablePppoeAccount($account->router, $account->username)
            : $this->driver->disablePppoeAccount($account->router, $account->username), $user, $connection);
        if ($result->successful) {
            $account->update(['status' => $status]);
        }

        return $result;
    }

    public function changeProfile(NetworkAccount $account, string $profile, ?User $user = null): NetworkOperationResult
    {
        $result = $this->execute('CHANGE_PROFILE', $account->router, $account->username, ['profile' => $profile], fn () => $this->driver->changePppoeProfile($account->router, $account->username, $profile), $user);
        if ($result->successful) {
            $account->update(['profile' => $profile]);
        }

        return $result;
    }

    public function disconnect(NetworkAccount $account, ?User $user = null): NetworkOperationResult
    {
        return $this->execute('DISCONNECT_SESSION', $account->router, $account->username, [], fn () => $this->driver->disconnectPppoeSession($account->router, $account->username), $user);
    }

    private function execute(string $operation, Router $router, ?string $target, array $payload, callable $callback, ?User $user, ?CustomerConnection $connection = null): NetworkOperationResult
    {
        $started = Carbon::now();
        $safePayload = $this->sanitize($payload);
        $result = $callback();
        NetworkOperationLog::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'customer_connection_id' => $connection?->id,
            'initiated_by_user_id' => $user?->id,
            'operation' => $operation,
            'target' => $target,
            'request_payload' => $safePayload,
            'result_payload' => $this->sanitize(['successful' => $result->successful, 'message' => $result->message, 'data' => $result->data]),
            'status' => $result->successful ? 'success' : 'failed',
            'error_message' => $result->successful ? null : $result->message,
            'started_at' => $started,
            'completed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        return $result;
    }

    private function sanitize(array $payload): array
    {
        foreach (['password', 'secret', 'token', 'credentials'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }
}
