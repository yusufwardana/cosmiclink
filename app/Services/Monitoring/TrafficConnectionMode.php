<?php

namespace App\Services\Monitoring;

final class TrafficConnectionMode
{
    public const STATIC_SIMPLE_QUEUE = 'STATIC_SIMPLE_QUEUE';

    public const HOTSPOT = 'HOTSPOT';

    public const PPPOE = 'PPPOE';

    public static function bucketSource(string $mode): ?string
    {
        return match ($mode) {
            self::STATIC_SIMPLE_QUEUE => 'simple_queue',
            self::HOTSPOT => 'hotspot_username',
            self::PPPOE => null,
            default => null,
        };
    }

    public static function supported(string $mode): bool
    {
        return self::bucketSource($mode) !== null;
    }
}
