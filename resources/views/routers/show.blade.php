@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) · design-system: design.md · designed-as-app --}}
@section('content')
    @php
        $health = $router->networkHealth;
        $healthState = $health?->health_state ?? 'unobserved';
        $provider = $health?->provider;
        $isReal = $provider && $provider !== 'fake';
        $monitoringLabel = $isReal ? 'REAL / ' . strtoupper($provider) : ($provider === 'fake' ? 'SIMULATED' : 'No observations');
        $meta = is_string($health?->metadata) ? json_decode($health->metadata, true) : ($health?->metadata ?? []);
        $mutationsEnabled = config('network.mutations_enabled');
        $operationsLabel = $mutationsEnabled ? 'ENABLED' : 'DISABLED / SAFE READ-ONLY';
    @endphp
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">11</span><span class="eyebrow__sep">·</span>Router</p>
            <h1>{{ $router->name }}</h1>
            <p class="page-header__meta">{{ $router->host }}:{{ $router->api_port }} · signed in as <em>{{ $router->username }}</em> · monitoring via <em>{{ $isReal ? strtoupper($provider) : 'none' }}</em>.</p>
        </div>
        <div class="page-header__aside">
            <a class="button button--primary" href="{{ route('routers.edit', $router) }}">Edit router</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="router-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Record</p>
                <h2 class="panel__title" id="router-record">Router record</h2>
            </div>
            <span class="panel__meta"><span class="ui-status-badge ui-status-badge--{{ $healthState }}">{{ $healthState }}</span></span>
        </div>
        <div class="panel__body panel__body--flush">
            <dl class="spec">
                <dt>Name</dt>
                <dd>{{ $router->name }}</dd>
                <dt>Host</dt>
                <dd class="mono-value">{{ $router->host }}</dd>
                <dt>API port</dt>
                <dd class="mono-value">{{ $router->api_port }}</dd>
                <dt>Username</dt>
                <dd class="mono-value">{{ $router->username }}</dd>
                <dt>Monitoring</dt>
                <dd><span class="ui-status-badge ui-status-badge--{{ $isReal ? 'online' : ($provider === 'fake' ? 'pending' : '') }}">{{ $monitoringLabel }}</span></dd>
                <dt>Status</dt>
                <dd><span class="ui-status-badge ui-status-badge--{{ $healthState }}">{{ $healthState }}</span></dd>
                <dt>Last observed</dt>
                <dd><time class="cell-time">{{ $health?->observed_at?->format('Y-m-d H:i') ?? 'Never' }}</time></dd>
                <dt>Operations</dt>
                <dd><span class="ui-status-badge ui-status-badge--{{ $mutationsEnabled ? 'active' : 'disabled' }}">{{ $operationsLabel }}</span></dd>
                <dt>Description</dt>
                <dd>{{ $router->description ?: '—' }}</dd>
            </dl>
        </div>
        <div class="panel__foot">
            <p class="console-note">A successful test verifies API connectivity. Real health state comes from the monitoring engine, not from this test.</p>
            <div class="panel__actions">
                <form class="inline-form" method="post" action="{{ route('routers.test', $router) }}">
                    @csrf
                    <button class="button--quiet button--sm">Test connection</button>
                </form>
            </div>
        </div>
    </section>

    @if ($health && count($meta))
    <section class="panel" aria-labelledby="router-telemetry">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Telemetry</p>
                <h2 class="panel__title" id="router-telemetry">Live RouterOS telemetry</h2>
            </div>
            <span class="panel__meta">via {{ strtoupper($provider) }}</span>
        </div>
        <div class="panel__body panel__body--flush">
            <dl class="spec">
                @if (!empty($meta['identity']))
                <dt>Identity</dt>
                <dd class="mono-value">{{ $meta['identity'] }}</dd>
                @endif
                @if (!empty($meta['version']))
                <dt>RouterOS</dt>
                <dd class="mono-value">{{ $meta['version'] }}</dd>
                @endif
                @if (!empty($meta['board']))
                <dt>Board</dt>
                <dd class="mono-value">{{ $meta['board'] }}</dd>
                @endif
                @if (!empty($meta['architecture']))
                <dt>Architecture</dt>
                <dd class="mono-value">{{ $meta['architecture'] }}</dd>
                @endif
                @if (isset($meta['uptime_seconds']))
                <dt>Uptime</dt>
                <dd class="mono-value">{{ floor($meta['uptime_seconds'] / 86400) }}d {{ floor(($meta['uptime_seconds'] % 86400) / 3600) }}h</dd>
                @endif
                @if (isset($meta['cpu_load_percent']))
                <dt>CPU load</dt>
                <dd>{{ $meta['cpu_load_percent'] }}%</dd>
                @endif
                @if (isset($meta['memory_used_percent']))
                <dt>Memory used</dt>
                <dd>{{ $meta['memory_used_percent'] }}%</dd>
                @endif
                @if (isset($meta['ppp_active_count']))
                <dt>PPP active</dt>
                <dd>{{ $meta['ppp_active_count'] }}</dd>
                @endif
            </dl>
        </div>
    </section>
    @endif
@endsection