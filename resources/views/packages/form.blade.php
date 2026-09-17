@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) form · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">04</span><span class="eyebrow__sep">·</span>Package</p>
            <h1>{{ $package->exists ? 'Edit package' : 'New package' }}</h1>
            <p class="page-header__meta">
                @if ($package->exists)
                    {{ $package->code }} · Editing the tier changes what future connections are provisioned with. Existing connections keep the profile they were built on until they are re-provisioned.
                @else
                    A package is the bandwidth tier a connection is provisioned against — speeds, price and the router profile the account is created with.
                @endif
            </p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ $package->exists ? route('packages.show', $package) : route('packages.index') }}">Cancel</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="package-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Record</p>
                <h2 class="panel__title" id="package-record">{{ $package->exists ? $package->code : 'New package' }}</h2>
            </div>
            <span class="panel__meta">{{ $package->exists ? 'Editing' : 'Creating' }}</span>
        </div>
        <div class="panel__body">
            <form class="spec-form" method="post" action="{{ $package->exists ? route('packages.update', $package) : route('packages.store') }}">
                @csrf
                @if ($package->exists) @method('PUT') @endif

                <h3>Identity</h3>
                <label for="name">Name @error('name')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="name" name="name" value="{{ old('name', $package->name) }}" required>

                <label for="code">Code @error('code')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="code" name="code" value="{{ old('code', $package->code) }}" required>

                <label for="status">State @error('status')<em class="field-error">{{ $message }}</em>@enderror</label>
                <select id="status" name="status">
                    <option value="active" @selected(old('status', $package->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $package->status) === 'inactive')>Inactive</option>
                </select>

                <h3>Bandwidth</h3>
                <label for="download_mbps">Download Mbps @error('download_mbps')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="download_mbps" name="download_mbps" type="number" value="{{ old('download_mbps', $package->download_mbps) }}" required>

                <label for="upload_mbps">Upload Mbps @error('upload_mbps')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="upload_mbps" name="upload_mbps" type="number" value="{{ old('upload_mbps', $package->upload_mbps) }}" required>

                <label for="network_profile">Router profile <em>Sent to the router as the PPPoE profile when a connection is provisioned.</em> @error('network_profile')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="network_profile" name="network_profile" value="{{ old('network_profile', $package->network_profile) }}" required>

                <h3>Commercials</h3>
                <label for="monthly_price">Monthly price (Rp) @error('monthly_price')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="monthly_price" name="monthly_price" type="number" value="{{ old('monthly_price', $package->monthly_price) }}" required>

                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3">{{ old('description', $package->description) }}</textarea>

                <div class="spec-form__actions">
                    <button type="submit">{{ $package->exists ? 'Save changes' : 'Create package' }}</button>
                    <p class="console-note">Inactive packages stay on the record but drop out of the connection form — only active tiers can be selected there.</p>
                </div>
            </form>
        </div>
    </section>
@endsection