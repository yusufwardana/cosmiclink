<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class LiveMonitoringCycle
{
    private function __construct(
        public CarbonImmutable $observedAt,
        public string $source,
        public ?string $failureCode,
    ) {}

    public static function successful(CarbonInterface $observedAt, string $source): self
    {
        return new self(CarbonImmutable::instance($observedAt), $source, null);
    }

    public static function failed(CarbonInterface $observedAt, string $failureCode, string $source): self
    {
        return new self(CarbonImmutable::instance($observedAt), $source, $failureCode);
    }

    public function isSuccessful(): bool
    {
        return $this->failureCode === null;
    }
}
