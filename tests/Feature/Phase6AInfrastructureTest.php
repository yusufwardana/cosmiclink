<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class Phase6AInfrastructureTest extends TestCase
{
    public function test_postgresql_postgis_and_redis_foundation_are_available(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('cosmiclink_test', config('database.connections.pgsql.database'));
        $this->assertSame('cosmiclink_test', DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertStringContainsString('POSTGIS=', DB::selectOne('SELECT PostGIS_Full_Version() AS version')->version);

        $key = 'phase6a:'.uniqid();
        Redis::set($key, 'ready');
        $this->assertSame('ready', Redis::get($key));
        Redis::del($key);

        Cache::store('redis')->put($key, 'cached', 30);
        $this->assertSame('cached', Cache::store('redis')->get($key));
        Cache::store('redis')->forget($key);

        $lock = Cache::store('redis')->lock($key, 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
