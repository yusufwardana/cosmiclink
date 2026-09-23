@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Index-First (13) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">10</span><span class="eyebrow__sep">·</span>Routers</p>
            <h1>Router fleet</h1>
            <p class="page-header__meta">The devices CosmicLink provisions against. State changes when a connection test succeeds or fails — last seen only moves on a successful test.</p>
        </div>
        <div class="page-header__aside">
            <a class="button button--primary" href="{{ route('routers.create') }}">Add router</a>
        </div>
    </div>

    <div class="stat-grid">
        <article class="stat-card stat-card--accent">
            <span class="stat-card__label">Routers</span>
            <span class="stat-card__value">{{ $routers->count() }}</span>
            <span class="stat-card__hint">on this tenant</span>
        </article>
        <article class="stat-card stat-card--accent">
            <span class="stat-card__label">Online</span>
            <span class="stat-card__value">{{ $routers->filter(fn ($r) => $r->networkHealth?->health_state === 'online')->count() }}</span>
            <span class="stat-card__hint">real monitoring confirmed</span>
        </article>
        <article class="stat-card stat-card--danger">
            <span class="stat-card__label">Offline</span>
            <span class="stat-card__value">{{ $routers->filter(fn ($r) => $r->networkHealth?->health_state === 'offline')->count() }}</span>
            <span class="stat-card__hint">last observation was offline</span>
        </article>
        <article class="stat-card stat-card--warning">
            <span class="stat-card__label">Unobserved</span>
            <span class="stat-card__value">{{ $routers->filter(fn ($r) => $r->networkHealth === null)->count() }}</span>
            <span class="stat-card__hint">no monitoring observation yet</span>
        </article>
    </div>

    <section class="panel" aria-labelledby="router-fleet">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Fleet</p>
                <h2 class="panel__title" id="router-fleet">Routers</h2>
            </div>
            <span class="panel__meta">{{ $routers->count() }} devices</span>
        </div>
        <div class="panel__body panel__body--flush">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Router</th>
                            <th class="cell-optional">Monitoring</th>
                            <th>Status</th>
                            <th class="cell-optional">Last observed</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($routers as $router)
                            @php
                                $health = $router->networkHealth;
                                $healthState = $health?->health_state ?? 'unobserved';
                                $provider = $health?->provider;
                                $isReal = $provider && $provider !== 'fake';
                                $monitoringLabel = $isReal ? strtoupper($provider) : ($provider === 'fake' ? 'SIMULATED' : '—');
                                $meta = is_string($health?->metadata) ? json_decode($health->metadata, true) : ($health?->metadata ?? []);
                                $mutationsEnabled = config('network.mutations_enabled');
                            @endphp
                            <tr>
                                <td>
                                    <a class="cell-key" href="{{ route('routers.show', $router) }}">{{ $router->name }}</a>
                                    <span class="cell-sub">{{ $router->host }}:{{ $router->api_port }}</span>
                                </td>
                                <td class="cell-optional">
                                    <span class="ui-status-badge ui-status-badge--{{ $isReal ? 'online' : ($provider === 'fake' ? 'pending' : '') }}">{{ $isReal ? 'REAL' : $monitoringLabel }}</span>
                                    @if ($isReal)
                                        <span class="cell-sub">{{ $monitoringLabel }}</span>
                                    @endif
                                </td>
                                <td><span class="ui-status-badge ui-status-badge--{{ $healthState }}">{{ $healthState }}</span></td>
                                <td class="cell-optional"><time class="cell-time">{{ $health?->observed_at?->format('Y-m-d H:i') ?? '—' }}</time></td>
                                <td>
                                    <div class="cell-actions">
                                        <form class="inline-form" method="post" action="{{ route('routers.test', $router) }}">
                                            @csrf
                                            <button class="button--quiet button--sm">Test</button>
                                        </form>
                                        <a class="button button--quiet button--sm" href="{{ route('routers.edit', $router) }}">Edit</a>
                                        <a class="button button--quiet button--sm" href="{{ route('routers.show', $router) }}">Open</a>
                                        <form class="inline-form" method="post" action="{{ route('routers.destroy', $router) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button--danger button--sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5"><p class="empty-state">No routers yet. Add the device CosmicLink should provision against, then test the connection before creating subscriber accounts on it.</p></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection