<?php

namespace App\Services\Network;

use App\Models\Router;

class FakeNetworkDriver implements NetworkDriver
{
    public function testConnection(Router $router): NetworkOperationResult
    {
        return $this->guard($router, 'Connection test successful.');
    }

    public function createPppoeAccount(Router $router, array $account): NetworkOperationResult
    {
        return $this->guard($router, 'PPPoE account created.', ['username' => $account['username'], 'profile' => $account['profile']]);
    }

    public function enablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return $this->guard($router, 'PPPoE account enabled.', ['username' => $username]);
    }

    public function disablePppoeAccount(Router $router, string $username): NetworkOperationResult
    {
        return $this->guard($router, 'PPPoE account disabled.', ['username' => $username]);
    }

    public function changePppoeProfile(Router $router, string $username, string $profile): NetworkOperationResult
    {
        return $this->guard($router, 'PPPoE profile changed.', ['username' => $username, 'profile' => $profile]);
    }

    public function disconnectPppoeSession(Router $router, string $username): NetworkOperationResult
    {
        return $this->guard($router, 'PPPoE session disconnected.', ['username' => $username]);
    }

    private function guard(Router $router, string $message, array $data = []): NetworkOperationResult
    {
        if ($router->status === 'unavailable') {
            return new NetworkOperationResult(false, 'Simulated router unavailable.', 'ROUTER_UNAVAILABLE');
        }

        return new NetworkOperationResult(true, $message, data: $data);
    }
}
