<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\TrafficBucket;
use App\Models\TrafficCollection;
use App\Models\TrafficSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase6KTrafficSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_traffic_tables_models_constraints_and_cascades(): void
    {
        foreach (['traffic_collections', 'traffic_samples', 'traffic_buckets'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create();
        $observedAt = now()->startOfMinute();
        $collection = TrafficCollection::create([
            'tenant_id' => $tenant->id, 'router_id' => $router->id,
            'collected_at' => $observedAt, 'provider' => 'routeros',
            'datasets' => ['interfaces'], 'enrichment_collected' => false,
            'metadata' => ['version' => '6.49.13'],
        ]);
        $sample = TrafficSample::create([
            'traffic_collection_id' => $collection->id, 'tenant_id' => $tenant->id,
            'router_id' => $router->id, 'source_type' => 'interface', 'source_key' => '*1',
            'upload_bytes' => 10, 'download_bytes' => 20, 'delta_status' => 'first_observation',
            'observed_at' => $observedAt, 'metadata' => ['name' => 'ether1'],
        ]);
        $bucket = TrafficBucket::create([
            'tenant_id' => $tenant->id, 'router_id' => $router->id,
            'source_type' => 'interface', 'subject_key' => '*1',
            'bucket_started_at' => $observedAt->copy()->startOfMinute(),
            'upload_bytes' => 0, 'download_bytes' => 0, 'sample_count' => 0,
        ]);

        $this->assertTrue($collection->datasets === ['interfaces']);
        $this->assertFalse($collection->enrichment_collected);
        $this->assertSame($router->id, $collection->router->id);
        $this->assertSame($collection->id, $sample->collection->id);
        $this->assertSame($router->id, $bucket->router->id);
        $this->assertCount(1, $router->trafficCollections);
        $this->assertCount(1, $router->trafficSamples);
        $this->assertCount(1, $router->trafficBuckets);

        $collectionIndexes = collect(Schema::getIndexes('traffic_collections'));
        $sampleIndexes = collect(Schema::getIndexes('traffic_samples'));
        $bucketIndexes = collect(Schema::getIndexes('traffic_buckets'));
        $this->assertTrue($collectionIndexes->contains(fn (array $index) => ($index['name'] ?? null) === 'traffic_collections_router_time_unique' && ($index['unique'] ?? false)));
        $this->assertTrue($sampleIndexes->contains(fn (array $index) => ($index['name'] ?? null) === 'traffic_samples_collection_source_unique' && ($index['unique'] ?? false)));
        $this->assertTrue($bucketIndexes->contains(fn (array $index) => ($index['name'] ?? null) === 'traffic_buckets_subject_time_unique' && ($index['unique'] ?? false)));

        $router->delete();
        $this->assertDatabaseCount('traffic_collections', 0);
        $this->assertDatabaseCount('traffic_samples', 0);
        $this->assertDatabaseCount('traffic_buckets', 0);
    }
}
