<?php

namespace Tests\Unit;

use App\Services\Monitoring\TrafficAnalyticsPeriod;
use App\Services\Monitoring\TrafficConnectionMode;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrafficAnalyticsPeriodTest extends TestCase
{
    public function test_today_is_the_exact_half_open_utc_day(): void
    {
        $period = TrafficAnalyticsPeriod::from('today', Carbon::parse('2026-09-22 15:34:12', 'America/New_York'));

        $this->assertSame('today', $period->key);
        $this->assertSame('2026-09-22T00:00:00+00:00', $period->start->toIso8601String());
        $this->assertSame('2026-09-23T00:00:00+00:00', $period->end->toIso8601String());
        $this->assertSame(86400, $period->seconds);
    }

    public function test_seven_and_thirty_day_periods_are_rolling_utc_windows(): void
    {
        $now = Carbon::parse('2026-09-22 20:00:00', 'UTC');
        $seven = TrafficAnalyticsPeriod::from('7d', $now);
        $thirty = TrafficAnalyticsPeriod::from('30d', $now);

        $this->assertSame('2026-09-15T20:00:00+00:00', $seven->start->toIso8601String());
        $this->assertSame('2026-09-22T20:00:00+00:00', $seven->end->toIso8601String());
        $this->assertSame(7 * 86400, $seven->seconds);
        $this->assertSame('2026-08-23T20:00:00+00:00', $thirty->start->toIso8601String());
        $this->assertSame('2026-09-22T20:00:00+00:00', $thirty->end->toIso8601String());
        $this->assertSame(30 * 86400, $thirty->seconds);
    }

    public function test_invalid_period_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TrafficAnalyticsPeriod::from('90d', Carbon::now('UTC'));
    }

    public function test_connection_modes_map_only_authoritative_available_sources(): void
    {
        $this->assertSame('simple_queue', TrafficConnectionMode::bucketSource(TrafficConnectionMode::STATIC_SIMPLE_QUEUE));
        $this->assertSame('hotspot_username', TrafficConnectionMode::bucketSource(TrafficConnectionMode::HOTSPOT));
        $this->assertNull(TrafficConnectionMode::bucketSource(TrafficConnectionMode::PPPOE));
        $this->assertFalse(TrafficConnectionMode::supported(TrafficConnectionMode::PPPOE));
    }
}
