<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HealthObservation;
use App\Models\OutageIncident;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5BApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_api_dashboard_and_customers_are_tenant_scoped_and_paginated(): void
    {
        [$tenant, $user] = $this->tenantWithUser('Tenant A');
        [$foreign] = $this->tenantWithUser('Tenant B');
        Customer::factory()->for($tenant)->create(['name' => 'Visible Customer']);
        Customer::factory()->for($foreign)->create(['name' => 'Hidden Customer']);

        $this->actingAs($user)->getJson('/api/v1/dashboard')->assertOk()->assertJsonStructure(['data' => ['customers', 'connections', 'billing', 'network', 'outages']]);
        $this->actingAs($user)->getJson('/api/v1/customers?per_page=1')->assertOk()->assertJsonStructure(['data', 'meta', 'links'])->assertJsonPath('meta.per_page', 1)->assertJsonFragment(['name' => 'Visible Customer'])->assertJsonMissing(['name' => 'Hidden Customer']);
    }

    public function test_customer_360_api_returns_tenant_safe_detail(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        [$foreign] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create(['name' => 'Customer 360']);
        $other = Customer::factory()->for($foreign)->create(['name' => 'Foreign Customer']);

        $this->actingAs($user)->getJson(route('api.v1.customers.show', $customer))->assertOk()->assertJsonPath('data.name', 'Customer 360')->assertJsonStructure(['data' => ['connections', 'invoices', 'payments', 'messages', 'outage_incidents']]);
        $this->actingAs($user)->getJson(route('api.v1.customers.show', $other))->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_monitoring_api_reads_summary_and_runs_existing_pipeline(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        HealthObservation::factory()->for($tenant)->create(['subject_type' => 'router', 'subject_id' => $router->id]);

        $this->actingAs($user)->getJson('/api/v1/monitoring')->assertOk()->assertJsonStructure(['data' => ['summary', 'routers', 'connections', 'incidents']]);
        $this->actingAs($user)->postJson('/api/v1/monitoring/check')->assertOk()->assertJsonPath('message', 'Monitoring check completed.');
    }

    public function test_monitoring_and_incident_api_reject_foreign_subjects_and_validate_simulation(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser();
        [$tenantB] = $this->tenantWithUser();
        $router = Router::factory()->for($tenantB)->create();
        $incident = OutageIncident::factory()->for($tenantB)->create();

        $this->actingAs($userA)->postJson(route('api.v1.monitoring.routers.simulation', $router), ['state' => 'not-a-state'])->assertForbidden();
        $this->actingAs($userA)->getJson(route('api.v1.outages.show', $incident))->assertForbidden();
        $this->actingAs($userA)->postJson(route('api.v1.outages.acknowledge', $incident))->assertForbidden();
    }

    public function test_monitoring_simulation_validation_returns_predictable_json_errors(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $router = Router::factory()->for($tenant)->create();
        config(['monitoring.simulation' => true]);

        $this->actingAs($user)->postJson(route('api.v1.monitoring.routers.simulation', $router), ['state' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['state']]);
    }

    private function tenantWithUser(string $name = 'DemoNet ISP'): array
    {
        $tenant = Tenant::factory()->create(['name' => $name]);

        return [$tenant, User::factory()->for($tenant)->create()];
    }
}
