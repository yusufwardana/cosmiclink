<?php

namespace App\Services\Monitoring\Contracts;

use App\Models\CustomerConnection;
use App\Models\Router;
use App\Services\Monitoring\HealthObservationResult;

interface MonitoringDriver
{
    public function observeRouter(Router $router): HealthObservationResult;

    public function observeConnection(CustomerConnection $connection): HealthObservationResult;
}
