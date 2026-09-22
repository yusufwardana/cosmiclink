<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\TrafficBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase6KTrafficAnalyticsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bucket_analytics_context_is_typed_and_historical_rows_fail_closed(): void
    {
        $router = Router::factory()->for(Tenant::factory())->create();

        $this->assertTrue(Schema::hasColumns('traffic_buckets', ['metadata', 'subscriber_authoritative']));

        $bucket = TrafficBucket::create([
            'tenant_id' => $router->tenant_id,
            'router_id' => $router->id,
            'source_type' => 'simple_queue',
            'subject_key' => '10.0.0.2/32',
            'bucket_started_at' => now()->startOfMinute(),
        ]);

        $this->assertFalse($bucket->fresh()->subscriber_authoritative);
        $this->assertNull($bucket->fresh()->metadata);
    }

    public function test_bucket_analytics_indexes_exist(): void
    {
        $indexes = collect(DB::select("select indexname from pg_indexes where schemaname = current_schema() and tablename = 'traffic_buckets'"))
            ->pluck('indexname');

        $this->assertContains('traffic_buckets_analytics_scope_index', $indexes);
        $this->assertContains('traffic_buckets_subject_history_index', $indexes);
    }
}
