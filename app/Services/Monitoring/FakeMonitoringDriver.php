<?php

namespace App\Services\Monitoring;

use App\Models\CustomerConnection;
use App\Models\Router;
use App\Services\Monitoring\Contracts\MonitoringDriver;

class FakeMonitoringDriver implements MonitoringDriver
{
    public function observeRouter(Router $router): HealthObservationResult
    {
        $state = HealthState::tryFrom($router->monitoring_state ?? HealthState::ONLINE->value) ?? HealthState::UNKNOWN;

        return new HealthObservationResult($state, $state !== HealthState::OFFLINE && $state !== HealthState::UNKNOWN, null, $state === HealthState::OFFLINE ? null : ($state === HealthState::DEGRADED ? 180 : 18), $state === HealthState::DEGRADED ? 8 : 0, now(), ['simulation' => true]);
    }

    public function observeConnection(CustomerConnection $connection): HealthObservationResult
    {
        $state = HealthState::tryFrom($connection->monitoring_state ?? HealthState::ONLINE->value) ?? HealthState::UNKNOWN;

        return new HealthObservationResult($state, null, $state === HealthState::ONLINE, null, null, now(), ['simulation' => true]);
    }
}
