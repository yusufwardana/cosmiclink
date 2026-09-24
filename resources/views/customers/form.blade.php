@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) form · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">02</span><span class="eyebrow__sep">·</span>Customer</p>
            <h1>{{ $customer->exists ? 'Edit customer' : 'New customer' }}</h1>
            <p class="page-header__meta">
                @if ($customer->exists)
                    {{ $customer->customer_code }} · This form writes the customer record only. Connections, invoices and messages are managed from the customer page.
                @else
                    The customer code is assigned on save — CL followed by six digits.
                @endif
            </p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}">Cancel</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="customer-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Record</p>
                <h2 class="panel__title" id="customer-record">{{ $customer->exists ? $customer->customer_code : 'New customer' }}</h2>
            </div>
            <span class="panel__meta">{{ $customer->exists ? 'Editing' : 'Creating' }}</span>
        </div>
        <div class="panel__body">
            <form class="spec-form" method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
                @csrf
                @if ($customer->exists) @method('PUT') @endif

                <h3>Identity</h3>
                <label for="name">Name @error('name')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="name" name="name" value="{{ old('name', $customer->name) }}" required>

                <label for="phone">Phone @error('phone')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="phone" name="phone" value="{{ old('phone', $customer->phone) }}">

                <label for="email">Email @error('email')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="email" name="email" type="email" value="{{ old('email', $customer->email) }}">

                <h3>Site</h3>
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="3">{{ old('address', $customer->address) }}</textarea>

                <label for="latitude">Latitude @error('latitude')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="latitude" name="latitude" type="number" step="0.000001" min="-90" max="90" value="{{ old('latitude', $customer->latitude) }}">

                <label for="longitude">Longitude @error('longitude')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="longitude" name="longitude" type="number" step="0.000001" min="-180" max="180" value="{{ old('longitude', $customer->longitude) }}">
                <div id="customer-location-picker" data-default-latitude="{{ $gisSettings['default_latitude'] ?? '' }}" data-default-longitude="{{ $gisSettings['default_longitude'] ?? '' }}" data-default-zoom="{{ $gisSettings['default_zoom'] ?? 1.4 }}" data-latitude="{{ old('latitude', $customer->latitude) ?? '' }}" data-longitude="{{ old('longitude', $customer->longitude) ?? '' }}"></div>
                <p class="field-hint">Customer location is optional and must be supplied explicitly. It is never inferred from network identity.</p>

                @if (! $customer->exists)
                    <h3>Service</h3>
                    <p class="field-hint">Monthly service only in V1. Creating this record does not provision or modify RouterOS.</p>
                    <label for="router_id">Router @error('router_id')<em class="field-error">{{ $message }}</em>@enderror</label>
                    <select id="router_id" name="router_id" required>
                        <option value="">Select router</option>
                        @foreach (($routers ?? collect()) as $router)
                            <option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }} · {{ $router->host }}</option>
                        @endforeach
                    </select>

                    <label for="connection_mode">Connection mode @error('connection_mode')<em class="field-error">{{ $message }}</em>@enderror</label>
                    <select id="connection_mode" name="connection_mode" required>
                        <option value="simple_queue" @selected(old('connection_mode', 'simple_queue') === 'simple_queue')>Static IP / Simple Queue</option>
                        <option value="hotspot" @selected(old('connection_mode') === 'hotspot')>Hotspot Account</option>
                    </select>

                    <label for="network_identity"><span data-connection-identity-label>Network identity / IP</span> @error('network_identity')<em class="field-error">{{ $message }}</em>@enderror</label>
                    <input id="network_identity" name="network_identity" value="{{ old('network_identity') }}" placeholder="10.10.12.50/32" required>

                    <label for="internet_package_id">Package <span class="field-hint">optional</span> @error('internet_package_id')<em class="field-error">{{ $message }}</em>@enderror</label>
                    <select id="internet_package_id" name="internet_package_id">
                        <option value="">No package selected</option>
                        @foreach (($packages ?? collect()) as $package)
                            <option value="{{ $package->id }}" @selected(old('internet_package_id') == $package->id)>{{ $package->name }} · {{ $package->download_mbps }}/{{ $package->upload_mbps }} Mbps</option>
                        @endforeach
                    </select>
                @else
                    @php($currentConnection = $customer->connections()->with('router')->latest('id')->first())
                    @if ($currentConnection)
                        <h3>Current service</h3>
                        <div class="customer-form-connection-summary">
                            <div><span class="cell-sub">Access mode</span><strong>{{ $currentConnection->access_mode_label }}</strong></div>
                            <div><span class="cell-sub">Network mechanism</span><strong>{{ $currentConnection->network_mechanism_label }}</strong></div>
                            <div><span class="cell-sub">Router</span><strong>{{ $currentConnection->router?->name ?? '—' }}</strong></div>
                            <div><span class="cell-sub">Stable identity</span><strong class="mono-value">{{ $currentConnection->metadata['network_identity'] ?? $currentConnection->networkAccount?->username ?? '—' }}</strong></div>
                        </div>
                        <p class="field-hint">Connection mode and stable network identity are managed by the existing connection/adoption workflows and are not changed by this customer record form.</p>
                    @endif
                @endif

                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3">{{ old('notes', $customer->notes) }}</textarea>

                <h3>Lifecycle</h3>
                <label for="status">State @error('status')<em class="field-error">{{ $message }}</em>@enderror</label>
                <select id="status" name="status">
                    <option value="active" @selected(old('status', $customer->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $customer->status) === 'inactive')>Inactive</option>
                </select>

                <div class="spec-form__actions">
                    <button type="submit">{{ $customer->exists ? 'Save changes' : 'Create customer' }}</button>
                    <p class="console-note">{{ $customer->exists ? 'Changes are written to '.$customer->customer_code.' on save.' : 'New customers appear at the top of the customer register.' }}</p>
                </div>
            </form>
        </div>
    </section>
    @if (! $customer->exists)
        <script>
            (() => {
                const mode = document.getElementById('connection_mode');
                const identity = document.getElementById('network_identity');
                const label = document.querySelector('[data-connection-identity-label]');
                const sync = () => {
                    const hotspot = mode?.value === 'hotspot';
                    if (label) label.textContent = hotspot ? 'Hotspot username / identity' : 'Network identity / IP';
                    if (identity) identity.placeholder = hotspot ? 'feri-hotspot' : '10.10.12.50/32';
                };
                mode?.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endif
@endsection