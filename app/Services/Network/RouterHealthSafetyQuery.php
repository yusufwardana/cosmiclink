<?php

namespace App\Services\Network;

use App\Models\HealthObservation;
use App\Models\Router;

final class RouterHealthSafetyQuery
{
    public function allows(Router $router): bool
    {
        $freshnessSeconds = config('monitoring.freshness_seconds');
        $checkpointSeconds = config('monitoring.checkpoint_seconds');

        if (! is_int($freshnessSeconds) || $freshnessSeconds < 1
            || ! is_int($checkpointSeconds) || $checkpointSeconds < 1
            || $checkpointSeconds > $freshnessSeconds) {
            return false;
        }

        $observation = HealthObservation::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('subject_type', 'router')
            ->where('subject_id', $router->id)
            ->latest('observed_at')
            ->first();

        if ($observation === null
            || $observation->health_state !== 'online'
            || $observation->reachable !== true
            || $observation->observed_at === null) {
            return false;
        }

        $ageSeconds = $observation->observed_at->diffInSeconds(now(), false);

        return $ageSeconds >= 0 && $ageSeconds <= $freshnessSeconds;
    }
}
