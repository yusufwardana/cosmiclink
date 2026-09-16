<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;

final readonly class HealthObservationResult
{
    public function __construct(
        public HealthState $state,
        public ?bool $reachable,
        public ?bool $online,
        public ?int $latencyMs,
        public ?int $packetLossPercent,
        public Carbon $observedAt,
        public array $metadata = [],
    ) {}
}
