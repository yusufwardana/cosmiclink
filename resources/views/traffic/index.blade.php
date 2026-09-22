@extends('layouts.app')

@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">06</span><span class="eyebrow__sep">·</span>Network intelligence</p>
            <h1>Traffic Intelligence</h1>
            <p class="page-header__meta">PostgreSQL analytics · subscriber and interface traffic remain separate</p>
        </div>
    </div>

    <div
        id="traffic-intelligence-vue"
        data-base-url="{{ request()->getBaseUrl() }}"
        data-filters='@json($filters)'
    >
        <section class="panel" aria-live="polite">
            <div class="panel__body">
                <p class="empty-state">Loading traffic intelligence. If no buckets exist, no historical data yet will be shown.</p>
                <p class="console-note">AUTHORITATIVE_TRAFFIC_UNAVAILABLE is shown explicitly; unavailable traffic is never displayed as zero.</p>
            </div>
        </section>
    </div>
@endsection