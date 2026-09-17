@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">11</span><span class="eyebrow__sep">·</span>Router</p>
            <h1>{{ $router->name }}</h1>
            <p class="page-header__meta">{{ $router->host }}:{{ $router->api_port }} · signed in as <em>{{ $router->username }}</em> over the {{ $router->driver }} driver.</p>
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
            <span class="panel__meta">{{ $router->driver }}</span>
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
                <dt>Driver</dt>
                <dd class="mono-value">{{ $router->driver }}</dd>
                <dt>State</dt>
                <dd><span class="ui-status-badge ui-status-badge--{{ $router->status }}">{{ $router->status }}</span></dd>
                <dt>Last seen</dt>
                <dd><time class="cell-time">{{ $router->last_seen_at?->format('Y-m-d H:i') ?? 'Never' }}</time></dd>
                <dt>Description</dt>
                <dd>{{ $router->description ?: '—' }}</dd>
            </dl>
        </div>
        <div class="panel__foot">
            <p class="console-note">A successful test sets the state back to available and moves last seen to now. A failed test marks the router unavailable.</p>
            <div class="panel__actions">
                <form class="inline-form" method="post" action="{{ route('routers.test', $router) }}">
                    @csrf
                    <button class="button--quiet button--sm">Test connection</button>
                </form>
            </div>
        </div>
    </section>
@endsection