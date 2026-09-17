@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) form · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">05</span><span class="eyebrow__sep">·</span>Connection</p>
            <h1>Add connection</h1>
            <p class="page-header__meta">For <em>{{ $customer->name }}</em> ({{ $customer->customer_code }}). The connection is saved as pending — provisioning creates the PPPoE account on the router and activates the line.</p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ route('customers.show', $customer) }}">Cancel</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="connection-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Record</p>
                <h2 class="panel__title" id="connection-record">Connection</h2>
            </div>
            <span class="panel__meta">{{ $customer->customer_code }}</span>
        </div>
        <div class="panel__body">
            <form class="spec-form" method="post" action="{{ route('customers.connections.store', $customer) }}">
                @csrf

                <h3>Provisioning</h3>
                <label for="internet_package_id">Internet package <em>Only active packages are listed.</em> @error('internet_package_id')<em class="field-error">{{ $message }}</em>@enderror</label>
                <select id="internet_package_id" name="internet_package_id" required>
                    <option value="">Select package</option>
                    @foreach ($packages as $package)
                        <option value="{{ $package->id }}" @selected(old('internet_package_id') == $package->id)>{{ $package->name }} — {{ $package->download_mbps }}/{{ $package->upload_mbps }} Mbps</option>
                    @endforeach
                </select>

                <label for="router_id">Router @error('router_id')<em class="field-error">{{ $message }}</em>@enderror</label>
                <select id="router_id" name="router_id" required>
                    <option value="">Select router</option>
                    @foreach ($routers as $router)
                        <option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }} — {{ $router->host }}</option>
                    @endforeach
                </select>

                <div class="spec-form__actions">
                    <button type="submit">Create connection</button>
                    <p class="console-note">{{ $packages->count() }} active packages · {{ $routers->count() }} routers. The account username is the customer code.</p>
                </div>
            </form>
        </div>
    </section>
@endsection