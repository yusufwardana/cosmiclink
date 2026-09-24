<?php

namespace App\Http\Controllers;

use App\Services\GisNetworkMapSettings;
use Illuminate\Http\Request;

class GisNetworkMapSettingsController extends Controller
{
    public function edit(Request $request, GisNetworkMapSettings $settings)
    {
        return view('settings.gis-network-map', ['settings' => $settings->get($request->user()->tenant_id)]);
    }

    public function update(Request $request, GisNetworkMapSettings $settings)
    {
        $data = $request->validate([
            'map_provider' => ['required', 'in:demo,esri_world_imagery,custom'],
            'style_url' => ['nullable', 'url:http,https', 'required_if:map_provider,custom'],
            'default_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'default_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'default_zoom' => ['required', 'numeric', 'between:0,22'],
            'auto_fit' => ['nullable', 'boolean'],
            'navigation_controls' => ['nullable', 'boolean'],
            'show_attribution' => ['nullable', 'boolean'],
            'show_router_name' => ['nullable', 'boolean'],
            'show_status' => ['nullable', 'boolean'],
            'show_telemetry' => ['nullable', 'boolean'],
        ]);

        $data['style_url'] = $data['style_url'] ?? '';
        if ($data['map_provider'] === 'demo') {
            $data['map_provider'] = 'esri_world_imagery';
        }
        foreach (['auto_fit', 'navigation_controls', 'show_attribution', 'show_router_name', 'show_status', 'show_telemetry'] as $key) {
            $data[$key] = $request->boolean($key);
        }

        $settings->put($request->user()->tenant_id, $data);

        return redirect()->route('settings.gis-network-map.index')->with('status', 'GIS Network Map settings saved.');
    }
}