<?php

namespace App\Services\Monitoring;

enum HealthState: string
{
    case ONLINE = 'online';
    case OFFLINE = 'offline';
    case DEGRADED = 'degraded';
    case UNKNOWN = 'unknown';
}
