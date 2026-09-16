<?php

namespace App\Services\Network;

use App\Models\Router;

interface NetworkDriver
{
    public function testConnection(Router $router): NetworkOperationResult;

    public function createPppoeAccount(Router $router, array $account): NetworkOperationResult;

    public function enablePppoeAccount(Router $router, string $username): NetworkOperationResult;

    public function disablePppoeAccount(Router $router, string $username): NetworkOperationResult;

    public function changePppoeProfile(Router $router, string $username, string $profile): NetworkOperationResult;

    public function disconnectPppoeSession(Router $router, string $username): NetworkOperationResult;
}
