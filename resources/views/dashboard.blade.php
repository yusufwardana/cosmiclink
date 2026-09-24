@extends('layouts.app')

@section('content')
<div class="page-header page-header--command">
    <div>
        <p class="eyebrow"><span class="eyebrow__ord">00</span><span class="eyebrow__sep">//</span>Dashboard / Network Operations</p>
        <h1>ISP operations, automated.</h1>
        <p class="page-header__meta">Real network health, discovery, traffic and outage state assembled from persisted CosmicLink records.</p>
    </div>
    <div class="page-header__aside">
        <a class="button button--quiet button--sm" href="{{ route('monitoring.index') }}">Monitoring console</a>
        <a class="button button--quiet button--sm" href="{{ route('traffic.index') }}">Traffic Intelligence</a>
    </div>
</div>

<div class="stat-grid stat-grid--telemetry">
    <article class="stat-card stat-card--accent">
        <span class="stat-card__label">Routers online</span>
        <span class="stat-card__value">{{ $onlineRouters }}/{{ $routerCount }}</span>
        <span class="stat-card__hint">{{ $routerHealth['degraded'] }} degraded · {{ $routerHealth['offline'] }} offline</span>
    </article>
    <article class="stat-card stat-card--accent">
        <span class="stat-card__label">Discovery resources</span>
        <span class="stat-card__value">{{ $discoveryResourceCount }}</span>
        <span class="stat-card__hint">persisted Discovery resources</span>
    </article>
    <article class="stat-card stat-card--accent">
        <span class="stat-card__label">Observed health</span>
        <span class="stat-card__value">{{ $routerHealthObserved }}</span>
        <span class="stat-card__hint">of {{ $routerCount }} routers observed</span>
    </article>
    <article class="stat-card {{ $activeIncidentCount > 0 ? 'stat-card--danger' : 'stat-card--accent' }}">
        <span class="stat-card__label">Active incidents</span>
        <span class="stat-card__value">{{ $activeIncidentCount }}</span>
        <span class="stat-card__hint">correlated outage records</span>
    </article>
</div>

<section class="panel dashboard-analytics" aria-labelledby="dashboard-analytics-heading">
    <div class="panel__head">
        <div>
            <p class="panel__kicker">Command center // 02</p>
            <h2 class="panel__title" id="dashboard-analytics-heading">Analytics</h2>
            <p class="panel__meta">Persisted customer, device, health, and traffic evidence.</p>
        </div>
        <div class="panel__actions">
            <a class="button button--quiet button--sm" href="{{ route('customers.index') }}">Customers →</a>
            <a class="button button--quiet button--sm" href="{{ route('network.devices.index') }}">Devices →</a>
            <a class="button button--quiet button--sm" href="{{ route('traffic.index') }}">Traffic →</a>
        </div>
    </div>
    <div class="panel__body">
        <div class="dashboard-analytics-grid">
            <article class="dashboard-analytics-card">
                <span class="dashboard-summary-label">Customers</span>
                <strong>{{ $customerCount }}</strong>
                <span class="dashboard-analytics-breakdown"><b>STATIC IP</b> {{ $staticCustomerCount }} · <b>HOTSPOT</b> {{ $hotspotCustomerCount }}</span>
                <a href="{{ route('customers.index') }}">View customers →</a>
            </article>
            <article class="dashboard-analytics-card">
                <span class="dashboard-summary-label">Observed devices</span>
                <strong>{{ $observedDeviceCount }}</strong>
                <span class="dashboard-analytics-breakdown"><b>Linked observations</b> {{ $linkedDeviceCount }} · <b>Unmapped observations</b> {{ $unmappedDeviceCount }} · <b>{{ $observedDeviceCount ? round(($linkedDeviceCount / $observedDeviceCount) * 100) : 0 }}%</b> linked</span>
                <a href="{{ route('network.devices.index') }}">View Device Directory →</a>
            </article>
            <article class="dashboard-analytics-card">
                <span class="dashboard-summary-label">Network health</span>
                <strong>{{ $routerHealth['online'] }}/{{ $routerCount }}</strong>
                <span class="dashboard-analytics-breakdown">{{ $routerHealth['offline'] }} offline · {{ $routerHealth['unknown'] }} unobserved/unknown</span>
                <a href="{{ route('monitoring.index') }}">View monitoring →</a>
            </article>
            <article class="dashboard-analytics-card dashboard-analytics-card--wide">
                <span class="dashboard-summary-label">Traffic</span>
                <strong>{{ $recentTrafficBucketCount > 0 ? 'Persisted data available' : 'No recent data' }}</strong>
                <span class="dashboard-analytics-breakdown">{{ $recentTrafficBucketCount > 0 ? 'Traffic Intelligence owns the detailed period/ranking views.' : 'No authoritative traffic summary is available for this tenant yet.' }}</span>
                <a href="{{ route('traffic.index') }}">Open Traffic Intelligence →</a>
            </article>
        </div>
    </div>
</section>

<div class="ops-grid ops-grid--split dashboard-overview-grid">
    <section class="panel panel--primary" aria-labelledby="dashboard-health-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">[NET] Operations overview // 01</p>
                <h2 class="panel__title" id="dashboard-health-heading">Network health</h2>
            </div>
            <span class="panel__meta panel__meta--terminal"><span class="terminal-tag">[{{ $routerHealthObserved ? 'OBSERVED' : 'UNKNOWN' }}]</span> {{ $latestObservationAt ?? 'No data yet' }}</span>
        </div>
        <div class="panel__body">
            <div class="dashboard-health-grid">
                <div class="dashboard-health-state dashboard-health-state--online"><span class="dashboard-health-state__value">{{ $routerHealth['online'] }}</span><span>Online</span></div>
                <div class="dashboard-health-state dashboard-health-state--warning"><span class="dashboard-health-state__value">{{ $routerHealth['degraded'] }}</span><span>Warning</span></div>
                <div class="dashboard-health-state dashboard-health-state--offline"><span class="dashboard-health-state__value">{{ $routerHealth['offline'] }}</span><span>Offline</span></div>
                <div class="dashboard-health-state"><span class="dashboard-health-state__value">{{ $routerHealth['unknown'] }}</span><span>Unknown</span></div>
            </div>
            @if ($routerTelemetry)
                <div class="cosmic-terminal cosmic-terminal--compact dashboard-telemetry">
                    <div class="cosmic-terminal__line"><span class="cosmic-terminal__label">router</span><span class="cosmic-terminal__value">{{ $routerTelemetry['router'] ?? '—' }}</span></div>
                    <div class="cosmic-terminal__line"><span class="cosmic-terminal__label">RouterOS</span><span class="cosmic-terminal__value">{{ $routerTelemetry['version'] ?? '—' }}</span></div>
                    <div class="cosmic-terminal__line"><span class="cosmic-terminal__label">CPU / memory</span><span class="cosmic-terminal__value">{{ isset($routerTelemetry['cpu_load_percent']) ? $routerTelemetry['cpu_load_percent'].'%' : '—' }} / {{ isset($routerTelemetry['memory_used_percent']) ? $routerTelemetry['memory_used_percent'].'%' : '—' }}</span></div>
                </div>
            @else
                <p class="empty-state">No router telemetry observed yet.</p>
            @endif
        </div>
    </section>

</div>

<div class="ops-grid ops-grid--halves dashboard-summary-grid">
    <section class="panel" aria-labelledby="dashboard-incidents-heading">
        <div class="panel__head">
            <div><p class="panel__kicker">Operations // 03</p><h2 class="panel__title" id="dashboard-incidents-heading">Outage summary</h2></div>
            <a class="panel__meta" href="{{ route('monitoring.index') }}#outage-incidents">Monitoring →</a>
        </div>
        <div class="panel__body">
            @forelse ($activeIncidents as $incident)
                <div class="feed__item"><span class="feed__label">{{ $incident->router?->name ?? 'Unassigned router' }}</span><span class="feed__detail">{{ $incident->status }} · {{ $incident->affected_connections_count }} affected connections</span><time class="feed__time">{{ $incident->detected_at?->format('M j, H:i') ?? '—' }}</time></div>
            @empty
                <p class="empty-state">No active correlated outages. Observed network state is nominal.</p>
            @endforelse
        </div>
    </section>
    <section class="panel" aria-labelledby="dashboard-traffic-heading">
        <div class="panel__head">
            <div><p class="panel__kicker">Network intelligence // 04</p><h2 class="panel__title" id="dashboard-traffic-heading">Traffic summary</h2></div>
            <a class="panel__meta" href="{{ route('traffic.index') }}">Traffic Intelligence →</a>
        </div>
        <div class="panel__body dashboard-traffic-summary">
            <div><span class="dashboard-summary-label">Active network accounts</span><strong>{{ $activeAccounts }}</strong></div>
            <div><span class="dashboard-summary-label">Latest observation</span><strong>{{ $latestObservationAt ?? 'No data yet' }}</strong></div>
            <div><span class="dashboard-summary-label">Traffic source</span><strong>Persisted analytics</strong></div>
        </div>
    </section>
</div>
@endsection
