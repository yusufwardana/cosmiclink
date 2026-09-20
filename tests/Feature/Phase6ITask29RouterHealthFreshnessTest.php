<?php

namespace Tests\Feature;

use App\Models\HealthObservation;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Monitoring\HealthState;
use App\Services\Monitoring\MonitoringService;
use App\Services\Network\RouterHealthSafetyQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase6ITask29RouterHealthFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_monitoring_configuration_keeps_checkpoint_inside_freshness_window(): void
    {
        $this->assertLessThanOrEqual(
            config('monitoring.freshness_seconds'),
            config('monitoring.checkpoint_seconds')
        );
    }

    public function test_unchanged_online_router_is_refreshed_before_persisted_evidence_can_stale(): void
    {
        config(['monitoring.freshness_seconds' => 180, 'monitoring.checkpoint_seconds' => 120]);
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create(['monitoring_state' => HealthState::ONLINE->value]);

        Carbon::setTestNow(Carbon::create(2026, 9, 19, 12, 0, 0));
        app(MonitoringService::class)->observeRouter($router, $user);
        Carbon::setTestNow(now()->addSeconds(119));
        app(MonitoringService::class)->observeRouter($router->fresh(), $user);
        Carbon::setTestNow(now()->addSeconds(2));
        app(MonitoringService::class)->observeRouter($router->fresh(), $user);

        $this->assertSame(2, HealthObservation::where('subject_type', 'router')->where('subject_id', $router->id)->count());
        $this->assertTrue(app(RouterHealthSafetyQuery::class)->allows($router->fresh()));
        $this->assertSame(HealthState::ONLINE->value, HealthObservation::where('subject_type', 'router')->where('subject_id', $router->id)->latest('observed_at')->value('health_state'));
    }

    public function test_state_changes_are_persisted_immediately_even_before_checkpoint(): void
    {
        config(['monitoring.checkpoint_seconds' => 120]);
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create(['monitoring_state' => HealthState::ONLINE->value]);

        Carbon::setTestNow(Carbon::create(2026, 9, 19, 12, 0, 0));
        app(MonitoringService::class)->observeRouter($router, $user);
        $router->update(['monitoring_state' => HealthState::OFFLINE->value]);
        Carbon::setTestNow(now()->addSecond());
        app(MonitoringService::class)->observeRouter($router->fresh(), $user);

        $this->assertSame(2, HealthObservation::where('subject_type', 'router')->where('subject_id', $router->id)->count());
        $this->assertSame(HealthState::OFFLINE->value, HealthObservation::where('subject_type', 'router')->where('subject_id', $router->id)->latest('observed_at')->value('health_state'));
    }

    public function test_router_health_query_fails_closed_for_missing_stale_offline_and_unreachable_evidence(): void
    {
        config(['monitoring.freshness_seconds' => 180, 'monitoring.checkpoint_seconds' => 120]);
        [$tenant] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $query = app(RouterHealthSafetyQuery::class);

        $this->assertFalse($query->allows($router));

        Carbon::setTestNow(Carbon::create(2026, 9, 19, 12, 0, 0));
        HealthObservation::factory()->for($tenant)->create([
            'subject_id' => $router->id,
            'health_state' => HealthState::ONLINE->value,
            'reachable' => true,
            'observed_at' => now()->subSeconds((int) config('monitoring.freshness_seconds') - 1),
        ]);
        $this->assertTrue($query->allows($router->fresh()));

        HealthObservation::query()->update(['observed_at' => now()->subSeconds(config('monitoring.freshness_seconds') + 1)]);
        $this->assertFalse($query->allows($router->fresh()));

        HealthObservation::query()->update([
            'health_state' => HealthState::OFFLINE->value,
            'reachable' => false,
            'observed_at' => now(),
        ]);
        $this->assertFalse($query->allows($router->fresh()));
    }

    public function test_unsafe_monitoring_configuration_fails_closed(): void
    {
        [$tenant] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        HealthObservation::factory()->for($tenant)->create([
            'subject_id' => $router->id,
            'health_state' => HealthState::ONLINE->value,
            'reachable' => true,
        ]);
        config(['monitoring.freshness_seconds' => 180, 'monitoring.checkpoint_seconds' => 181]);

        $this->assertFalse(app(RouterHealthSafetyQuery::class)->allows($router->fresh()));
    }

    public function test_router_health_query_does_not_transition_managed_state(): void
    {
        [$tenant] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        $account = $router->networkAccounts()->create([
            'tenant_id' => $tenant->id,
            'username' => 'health-check',
            'profile' => 'HOME-10M',
            'status' => 'active',
            'management_state' => 'ADOPTED',
        ]);

        app(RouterHealthSafetyQuery::class)->allows($router->fresh());

        $this->assertSame('ADOPTED', $account->fresh()->management_state);
        $this->assertDatabaseCount('network_account_transitions', 0);
    }

    private function tenantWithUser(): array
    {
        $tenant = Tenant::factory()->create();

        return [$tenant, User::factory()->for($tenant)->create()];
    }
}
