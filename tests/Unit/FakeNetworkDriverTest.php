<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\Network\FakeNetworkDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeNetworkDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_router_returns_deterministic_failure(): void
    {
        $router = Router::factory()->create(['status' => 'unavailable']);
        $result = app(FakeNetworkDriver::class)->testConnection($router);

        $this->assertFalse($result->successful);
        $this->assertSame('ROUTER_UNAVAILABLE', $result->errorCode);
        $this->assertSame('Simulated router unavailable.', $result->message);
    }
}
