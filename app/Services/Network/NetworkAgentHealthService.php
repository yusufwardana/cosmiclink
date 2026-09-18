<?php

namespace App\Services\Network;

use App\Models\NetworkAgent;
use InvalidArgumentException;

class NetworkAgentHealthService
{
    public function __construct()
    {
        foreach (['heartbeat_seconds', 'stale_seconds', 'offline_seconds', 'lease_seconds', 'renewal_seconds', 'max_attempts'] as $key) {
            $value = config("network_agents.$key");
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Network Agent policy requires positive integers.');
            }
        }
        if (config('network_agents.heartbeat_seconds') >= config('network_agents.stale_seconds')
            || config('network_agents.stale_seconds') >= config('network_agents.offline_seconds')
            || config('network_agents.renewal_seconds') >= config('network_agents.lease_seconds')) {
            throw new InvalidArgumentException('Network Agent policy intervals are incorrectly ordered.');
        }
    }

    public function health(NetworkAgent $agent): string
    {
        if ($agent->last_seen_at === null) {
            return 'OFFLINE';
        }
        $age = $agent->last_seen_at->diffInSeconds(now(), false);
        if ($age < config('network_agents.stale_seconds')) {
            return 'ONLINE';
        }

        return $age < config('network_agents.offline_seconds') ? 'STALE' : 'OFFLINE';
    }
}
