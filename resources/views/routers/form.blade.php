@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) form · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">11</span><span class="eyebrow__sep">·</span>Router</p>
            <h1>{{ $router->exists ? 'Edit router' : 'New router' }}</h1>
            <p class="page-header__meta">
                @if ($router->exists)
                    {{ $router->host }}:{{ $router->api_port }} · Credentials are stored encrypted and never echoed back into this form.
                @else
                    CosmicLink signs in to the device over the RouterOS API to create and change PPPoE accounts. The driver comes from network config, not from this form.
                @endif
            </p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ $router->exists ? route('routers.show', $router) : route('routers.index') }}">Cancel</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="router-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Record</p>
                <h2 class="panel__title" id="router-record">{{ $router->exists ? $router->name : 'New router' }}</h2>
            </div>
            <span class="panel__meta">{{ $router->exists ? $router->driver : 'Not yet registered' }}</span>
        </div>
        <div class="panel__body">
            <form class="spec-form" method="post" action="{{ $router->exists ? route('routers.update', $router) : route('routers.store') }}">
                @csrf
                @if ($router->exists) @method('PUT') @endif

                <h3>Identity</h3>
                <label for="name">Name @error('name')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="name" name="name" value="{{ old('name', $router->name) }}" required>

                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3">{{ old('description', $router->description) }}</textarea>

                <h3>Credentials</h3>
                <label for="host">Host @error('host')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="host" name="host" value="{{ old('host', $router->host) }}" required>

                <label for="api_port">API port <em>8728 is the RouterOS API.</em> @error('api_port')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="api_port" name="api_port" type="number" value="{{ old('api_port', $router->api_port ?: 8728) }}" required>

                <label for="username">Username @error('username')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="username" name="username" value="{{ old('username', $router->username) }}" required>

                <label for="password">Password <em>Stored encrypted. Never echoed back.</em> @error('password')<em class="field-error">{{ $message }}</em>@enderror</label>
                <input id="password" name="password" type="password" autocomplete="new-password">

                <div class="spec-form__actions">
                    <button type="submit">{{ $router->exists ? 'Save changes' : 'Create router' }}</button>
                    <p class="console-note">{{ $router->exists ? 'A blank password keeps the stored credential.' : 'Test the connection from the router record once it is saved.' }}</p>
                </div>
            </form>
        </div>
    </section>
@endsection