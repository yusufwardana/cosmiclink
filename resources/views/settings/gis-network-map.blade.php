@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <p class="eyebrow"><span class="eyebrow__ord">08</span><span class="eyebrow__sep">//</span>Settings</p>
        <h1>GIS Network Map</h1>
        <p class="page-header__meta">Configure basemap, default map position, zoom, and GIS display preferences.</p>
    </div>
</div>

<form class="panel settings-form" method="post" action="{{ route('settings.gis-network-map.update') }}">
    @csrf
    @method('PUT')
    <div class="panel__body">
        <div class="settings-form__section">
            <p class="panel__kicker">Basemap</p>
            <label>Map provider
                <select name="map_provider" id="map_provider">
                    <option value="esri_world_imagery" @selected(in_array($settings['map_provider'], ['demo', 'esri_world_imagery'], true))>Esri World Imagery</option>
                </select>
            </label>
            <label>Custom Map Style URL
                <input name="style_url" type="url" value="{{ old('style_url', $settings['style_url']) }}" placeholder="https://example.test/style.json">
                @error('style_url')<em class="field-error">{{ $message }}</em>@enderror
            </label>
            <p class="console-note">Esri World Imagery is the default satellite basemap. Esri attribution remains enabled.</p>
        </div>

        <div class="settings-form__section">
            <p class="panel__kicker">Default view</p>
            <p class="console-note">Default map position controls where the map opens when no infrastructure location is available. It does not change router or customer locations.</p>
            <div class="settings-form__row">
                <label>Default latitude<input name="default_latitude" type="number" step="0.000001" min="-90" max="90" value="{{ old('default_latitude', $settings['default_latitude']) }}">@error('default_latitude')<em class="field-error">{{ $message }}</em>@enderror</label>
                <label>Default longitude<input name="default_longitude" type="number" step="0.000001" min="-180" max="180" value="{{ old('default_longitude', $settings['default_longitude']) }}">@error('default_longitude')<em class="field-error">{{ $message }}</em>@enderror</label>
                <label>Default zoom<input name="default_zoom" type="number" step="0.1" min="0" max="22" value="{{ old('default_zoom', $settings['default_zoom']) }}">@error('default_zoom')<em class="field-error">{{ $message }}</em>@enderror</label>
            </div>
            <p class="console-note">Use the router’s separate Set Location workflow to persist actual infrastructure coordinates.</p>
        </div>

        <div class="settings-form__section">
            <p class="panel__kicker">Map behavior</p>
            @foreach ([['auto_fit', 'Auto fit infrastructure'], ['navigation_controls', 'Show map navigation controls'], ['show_attribution', 'Show map attribution']] as [$key, $label])
                <label class="settings-checkbox"><input type="checkbox" name="{{ $key }}" value="1" @checked($settings[$key])> {{ $label }}</label>
            @endforeach
        </div>

        <div class="settings-form__section">
            <p class="panel__kicker">Marker display</p>
            @foreach ([['show_router_name', 'Show router name'], ['show_status', 'Show operational status'], ['show_telemetry', 'Show telemetry in detail panel']] as [$key, $label])
                <label class="settings-checkbox"><input type="checkbox" name="{{ $key }}" value="1" @checked($settings[$key])> {{ $label }}</label>
            @endforeach
        </div>

        <div class="settings-form__actions">
            <button class="button button--primary" type="submit">Save Settings</button>
        </div>
    </div>
</form>
@endsection