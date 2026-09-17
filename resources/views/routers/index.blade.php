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
            <span class="stat-card__label">Available</span>
            <span class="stat-card__value">{{ $routers->where('status', 'available')->count() }}</span>
            <span class="stat-card__hint">profiles can be pushed</span>
        </article>
        <article class="stat-card stat-card--danger">
            <span class="stat-card__label">Unavailable</span>
            <span class="stat-card__value">{{ $routers->where('status', 'unavailable')->count() }}</span>
            <span class="stat-card__hint">last test did not answer</span>
        </article>
        <article class="stat-card stat-card--warning">
            <span class="stat-card__label">Never contacted</span>
            <span class="stat-card__value">{{ $routers->whereNull('last_seen_at')->count() }}</span>
            <span class="stat-card__hint">no successful test recorded</span>
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
                            <th class="cell-optional">Driver</th>
                            <th>State</th>
                            <th class="cell-optional">Last seen</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($routers as $router)
                            <tr>
                                <td>
                                    <a class="cell-key" href="{{ route('routers.show', $router) }}">{{ $router->name }}</a>
                                    <span class="cell-sub">{{ $router->host }}:{{ $router->api_port }}</span>
                                </td>
                                <td class="cell-optional mono-value">{{ $router->driver }}</td>
                                <td><span class="ui-status-badge ui-status-badge--{{ $router->status }}">{{ $router->status }}</span></td>
                                <td class="cell-optional"><time class="cell-time">{{ $router->last_seen_at?->format('Y-m-d H:i') ?? '—' }}</time></td>
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