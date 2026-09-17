@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">04</span><span class="eyebrow__sep">·</span>Package</p>
            <h1>{{ $package->name }}</h1>
            <p class="page-header__meta">{{ $package->download_mbps }}/{{ $package->upload_mbps }} Mbps · Rp{{ number_format($package->monthly_price, 0, ',', '.') }} per month · provisioned on the <em>{{ $package->network_profile }}</em> router profile.</p>
        </div>
        <div class="page-header__aside">
            <a class="button button--primary" href="{{ route('packages.edit', $package) }}">Edit package</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="package-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Record</p>
                <h2 class="panel__title" id="package-record">Package record</h2>
            </div>
            <span class="panel__meta">{{ $package->code }}</span>
        </div>
        <div class="panel__body panel__body--flush">
            <dl class="spec">
                <dt>Name</dt>
                <dd>{{ $package->name }}</dd>
                <dt>Code</dt>
                <dd class="mono-value">{{ $package->code }}</dd>
                <dt>State</dt>
                <dd><span class="ui-status-badge ui-status-badge--{{ $package->status }}">{{ $package->status }}</span></dd>
                <dt>Download</dt>
                <dd>{{ $package->download_mbps }} Mbps</dd>
                <dt>Upload</dt>
                <dd>{{ $package->upload_mbps }} Mbps</dd>
                <dt>Monthly price</dt>
                <dd class="cell-amount">Rp{{ number_format($package->monthly_price, 0, ',', '.') }}</dd>
                <dt>Router profile</dt>
                <dd class="mono-value">{{ $package->network_profile }}</dd>
                <dt>Description</dt>
                <dd>{{ $package->description ?: '—' }}</dd>
            </dl>
        </div>
        <div class="panel__foot">
            <p class="console-note">Inactive packages stay on the record and drop out of the connection form.</p>
            <div class="panel__actions">
                <form class="inline-form" method="post" action="{{ route('packages.status', [$package, $package->status === 'active' ? 'inactive' : 'active']) }}">
                    @csrf
                    <button class="button--quiet button--sm">{{ $package->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                </form>
            </div>
        </div>
    </section>
@endsection