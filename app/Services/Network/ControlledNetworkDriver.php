<?php

namespace App\Services\Network;

use App\Models\NetworkAccount;
use App\Models\Router;

interface ControlledNetworkDriver
{
    public function executeControlled(Router $router, NetworkAccount $account, ControlledNetworkExecution $execution): NetworkOperationResult;
}
