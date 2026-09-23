@extends('layouts.app')

@section('content')
<div class="page-header page-header--command">
    <div>
        <p class="eyebrow"><span class="eyebrow__ord">00</span><span class="eyebrow__sep">//</span>Dashboard / Network Operations Command Center</p>
        <h1>ISP operations, automated.</h1>
        <p class="page-header__meta">Real-time network topology assembled from live CosmicLink discovery and monitoring records.</p>
    </div>
    <div class="page-header__aside">
        <a class="button button--quiet button--sm" href="{{ route('monitoring.index') }}">Monitoring console</a>
        <a class="button button--quiet button--sm" href="{{ route('traffic.index') }}">Traffic Intelligence</a>
    </div>
</div>

<div
    id="dashboard-noc-vue"
    data-base-url="{{ request()->getBaseUrl() }}"
    data-simulation="{{ config('monitoring.simulation') ? 'true' : 'false' }}"
    data-active-incident-count="{{ $activeIncidentCount }}"
>
    <div class="noc-loading">
        <p class="empty-state">Loading Network Operations Center...</p>
    </div>
</div>
@endsection
