<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class TrafficAnalyticsPeriod
{
    private function __construct(
        public string $key,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $seconds,
    ) {}

    public static function from(string $period, CarbonInterface $now): self
    {
        $end = CarbonImmutable::instance($now)->utc();

        return match ($period) {
            'today' => new self('today', $end->startOfDay(), $end->startOfDay()->addDay(), 86400),
            '7d' => new self('7d', $end->subDays(7), $end, 7 * 86400),
            '30d' => new self('30d', $end->subDays(30), $end, 30 * 86400),
            default => throw new InvalidArgumentException("Unsupported traffic period [{$period}]."),
        };
    }
}
