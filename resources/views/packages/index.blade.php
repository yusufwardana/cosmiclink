@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Catalogue (11) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">03</span><span class="eyebrow__sep">·</span>Packages</p>
            <h1>Internet packages</h1>
            <p class="page-header__meta">Bandwidth tiers and the router profile each one provisions. {{ $packages->count() }} packages, {{ $packages->where('status', 'active')->count() }} active.</p>
        </div>
        <div class="page-header__aside">
            <a class="button button--primary" href="{{ route('packages.create') }}">Add package</a>
        </div>
    </div>

    @if ($packages->isEmpty())
        <section class="panel">
            <p class="empty-state">No packages yet. Every connection is provisioned against a package, so add one before connecting subscribers.</p>
        </section>
    @else
        <div class="ops-grid ops-grid--thirds">
            @foreach ($packages as $package)
                <article class="card stack">
                    <p class="panel__kicker">{{ $package->code }}</p>
                    <h2 class="panel__title"><a href="{{ route('packages.show', $package) }}">{{ $package->name }}</a></h2>
                    <p class="stat-card__value">{{ $package->download_mbps }}<span class="cell-muted">/{{ $package->upload_mbps }}</span></p>
                    <p class="cell-muted">Mbps down / up · Rp{{ number_format($package->monthly_price, 0, ',', '.') }} per month</p>
                    <dl class="spec">
                        <dt>Profile</dt>
                        <dd>{{ $package->network_profile }}</dd>
                        <dt>State</dt>
                        <dd><span class="ui-status-badge ui-status-badge--{{ $package->status }}">{{ $package->status }}</span></dd>
                    </dl>
                    <div class="panel__actions">
                        <form class="inline-form" method="post" action="{{ route('packages.status', [$package, $package->status === 'active' ? 'inactive' : 'active']) }}">
                            @csrf
                            <button class="button--quiet button--sm">{{ $package->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                        <a class="button button--quiet button--sm" href="{{ route('packages.edit', $package) }}">Edit</a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
@endsection