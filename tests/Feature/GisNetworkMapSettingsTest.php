<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GisNetworkMapSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithUser(string $role = 'admin'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create(['role' => $role]);

        return [$tenant, $user];
    }

    public function test_gis_settings_are_readable_and_updateable_for_the_current_tenant(): void
    {
        [$tenant, $user] = $this->tenantWithUser();

        $this->actingAs($user)->get(route('settings.gis-network-map.index'))
            ->assertOk()
            ->assertSee('GIS Network Map');

        $this->actingAs($user)->put(route('settings.gis-network-map.update'), [
            'map_provider' => 'demo',
            'style_url' => '',
            'default_latitude' => '-7.7956',
            'default_longitude' => '110.3695',
            'default_zoom' => '12',
            'auto_fit' => '1',
            'navigation_controls' => '1',
            'show_attribution' => '1',
            'show_router_name' => '1',
            'show_status' => '1',
            'show_telemetry' => '1',
        ])->assertRedirect(route('settings.gis-network-map.index'));

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'key' => 'gis.default_latitude',
            'value' => '-7.7956',
        ]);

        $this->actingAs($user)->getJson('/api/v1/network-map')
            ->assertOk()
            ->assertJsonPath('data.settings.default_latitude', -7.7956)
            ->assertJsonPath('data.settings.default_zoom', 12);
    }

    public function test_gis_settings_validate_coordinates_zoom_and_custom_style_url(): void
    {
        [, $user] = $this->tenantWithUser();

        $this->actingAs($user)->from(route('settings.gis-network-map.index'))
            ->put(route('settings.gis-network-map.update'), [
                'map_provider' => 'custom',
                'style_url' => 'javascript:alert(1)',
                'default_latitude' => '91',
                'default_longitude' => '181',
                'default_zoom' => '99',
            ])->assertSessionHasErrors(['style_url', 'default_latitude', 'default_longitude', 'default_zoom']);
    }

    public function test_gis_settings_are_tenant_isolated(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser();
        [$tenantB, $userB] = $this->tenantWithUser();

        $this->actingAs($userA)->put(route('settings.gis-network-map.update'), [
            'map_provider' => 'demo',
            'default_latitude' => '1',
            'default_longitude' => '2',
            'default_zoom' => '5',
        ])->assertRedirect();

        $this->actingAs($userB)->getJson('/api/v1/network-map')
            ->assertOk()
            ->assertJsonPath('data.settings.default_latitude', null)
            ->assertJsonPath('data.settings.default_zoom', 1.4);

        $this->assertDatabaseHas('tenant_settings', ['tenant_id' => $tenantA->id, 'key' => 'gis.default_latitude', 'value' => '1']);
        $this->assertDatabaseMissing('tenant_settings', ['tenant_id' => $tenantB->id, 'key' => 'gis.default_latitude']);
    }
}