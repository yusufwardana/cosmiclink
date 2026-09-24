<?php

namespace App\Services;

use App\Models\TenantSetting;

class GisNetworkMapSettings
{
    public const DEFAULTS = [
        'gis.map_provider' => 'esri_world_imagery',
        'gis.style_url' => '',
        'gis.default_latitude' => null,
        'gis.default_longitude' => null,
        'gis.default_zoom' => 1.4,
        'gis.auto_fit' => true,
        'gis.navigation_controls' => true,
        'gis.show_attribution' => true,
        'gis.show_router_name' => true,
        'gis.show_status' => true,
        'gis.show_telemetry' => true,
    ];

    public function get(int $tenantId): array
    {
        $stored = TenantSetting::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key');

        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $settings[substr($key, 4)] = $default;
        }
        foreach ($stored as $key => $value) {
            $shortKey = str_starts_with($key, 'gis.') ? substr($key, 4) : $key;
            $settings[$shortKey] = match ($shortKey) {
                'default_latitude', 'default_longitude' => $value === null || $value === '' ? null : (float) $value,
                'default_zoom' => (float) $value,
                'auto_fit', 'navigation_controls', 'show_attribution', 'show_router_name', 'show_status', 'show_telemetry' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => (string) $value,
            };
        }

        return $settings;
    }

    public function put(int $tenantId, array $values): array
    {
        foreach (self::DEFAULTS as $key => $default) {
            $shortKey = substr($key, 4);
            if (! array_key_exists($shortKey, $values)) continue;

            TenantSetting::updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => is_bool($values[$shortKey]) ? ($values[$shortKey] ? '1' : '0') : (string) ($values[$shortKey] ?? '')],
            );
        }

        return $this->get($tenantId);
    }
}