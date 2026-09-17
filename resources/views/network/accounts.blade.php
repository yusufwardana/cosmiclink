@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Index-First (13) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">12</span><span class="eyebrow__sep">·</span>Network accounts</p>
            <h1>Simulated PPPoE</h1>
            <p class="page-header__meta">Subscriber accounts mirrored on the router fabric. This screen calls the fake network driver; it does not touch a live RouterOS device.</p>
        </div>
        <div class="page-header__aside"><span class="topbar__mode">SIMULATION MODE</span></div>
    </div>

    <div class="ops-grid ops-grid--split">
        <section class="panel" aria-labelledby="account-create">
            <div class="panel__head"><div><p class="panel__kicker">Provisioning</p><h2 class="panel__title" id="account-create">Create account</h2></div><span class="panel__meta">{{ $routers->count() }} routers</span></div>
            <div class="panel__body"><form class="spec-form" method="post" action="{{ route('network.accounts.store') }}">
                @csrf
                <label for="router_id">Router @error('router_id')<em class="field-error">{{ $message }}</em>@enderror</label><select id="router_id" name="router_id" required><option value="">Select router</option>@foreach ($routers as $router)<option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }} — {{ $router->host }}</option>@endforeach</select>
                <label for="username">Username @error('username')<em class="field-error">{{ $message }}</em>@enderror</label><input id="username" name="username" value="{{ old('username') }}" required>
                <label for="profile">Profile @error('profile')<em class="field-error">{{ $message }}</em>@enderror</label><input id="profile" name="profile" placeholder="HOME-10M" value="{{ old('profile') }}" required>
                <div class="spec-form__actions"><button type="submit">Create account</button><p class="console-note">The fake driver records the operation and returns a predictable result.</p></div>
            </form></div>
        </section>
        <section class="panel" aria-labelledby="account-register">
            <div class="panel__head"><div><p class="panel__kicker">Fabric</p><h2 class="panel__title" id="account-register">Account register</h2></div><span class="panel__meta">{{ $accounts->count() }} accounts</span></div>
            <div class="panel__body"><p class="console-note">Disable preserves the account record. Disconnect removes the simulated subscriber from the router and leaves the audit trail intact.</p></div>
        </section>
    </div>

    <section class="panel" aria-labelledby="account-ledger">
        <div class="panel__head"><div><p class="panel__kicker">Ledger</p><h2 class="panel__title" id="account-ledger">Network accounts</h2></div><span class="panel__meta">{{ $accounts->count() }} rows</span></div>
        <div class="panel__body panel__body--flush"><div class="table-scroll"><table><thead><tr><th>Account</th><th>Router</th><th>Profile</th><th>State</th><th class="cell-actions">Actions</th></tr></thead><tbody>
            @forelse ($accounts as $account)
                <tr>
                    <td class="cell-key">{{ $account->username }}<span class="cell-sub">account {{ $account->id }}</span></td>
                    <td>{{ $account->router?->name ?? '—' }}<span class="cell-sub">{{ $account->router?->host }}</span></td>
                    <td class="mono-value">{{ $account->profile }}</td>
                    <td><span class="ui-status-badge ui-status-badge--{{ $account->status }}">{{ $account->status }}</span></td>
                    <td><div class="cell-actions">
                        <form class="inline-form" method="post" action="{{ route('network.accounts.status', [$account, $account->status === 'active' ? 'disabled' : 'active']) }}">@csrf<button class="button--quiet button--sm">{{ $account->status === 'active' ? 'Disable' : 'Enable' }}</button></form>
                        <form class="inline-form" method="post" action="{{ route('network.accounts.disconnect', $account) }}">@csrf<button class="button--danger button--sm">Disconnect</button></form>
                        <form class="inline-form" method="post" action="{{ route('network.accounts.profile', $account) }}">@csrf @method('PUT')<input class="inline-input" name="profile" value="{{ $account->profile }}" aria-label="New profile"><button class="button--quiet button--sm">Change profile</button></form>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="5"><p class="empty-state">No simulated accounts yet. Create one above or provision a customer connection.</p></td></tr>
            @endforelse
        </tbody></table></div></div>
    </section>
@endsection