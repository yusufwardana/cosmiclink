<?php

namespace App\Services\Monitoring;

enum LiveConnectionStatus: string
{
    case ONLINE = 'online';
    case SUSPECTED_OFFLINE = 'suspected_offline';
    case OFFLINE = 'offline';
    case UNKNOWN = 'unknown';
    case STALE = 'stale';
}
