@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Index-First (13) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">01</span><span class="eyebrow__sep">·</span>Customers</p>
            <h1>Customer base</h1>
            <p class="page-header__meta">Every subscriber on this tenant, newest first. The customer code is the operator handle; the name is what the customer hears on the phone.</p>
        </div>
        <div class="page-header__aside">
            <a class="button button--primary" href="{{ route('customers.create') }}">Add customer</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="customer-register">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Register</p>
                <h2 class="panel__title" id="customer-register">Customers</h2>
            </div>
            <span class="panel__meta">{{ $customers->count() }} total · {{ $customers->where('status', 'active')->count() }} active</span>
        </div>
        <div class="panel__body panel__body--flush">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Connection</th>
                            <th class="cell-optional">Contact</th>
                            <th>State</th>
                            <th>Location</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            <tr>
                                <td>
                                    <a class="cell-key" href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a>
                                    <span class="cell-sub">{{ $customer->customer_code }}</span>
                                </td>
                                <td>
                                    @php($connection = $customer->connections->first())
                                    <span class="cell-key">{{ $connection?->access_mode_label ?? '—' }}</span>
                                    @if($connection)
                                        <span class="cell-sub">Mechanism: {{ $connection->network_mechanism_label }}</span>
                                    @endif
                                    <span class="cell-sub">{{ $connection?->metadata['network_identity'] ?? $connection?->networkAccount?->username ?? '—' }}</span>
                                    <span class="cell-sub">{{ $connection?->router?->name ?? '—' }} · {{ $connection?->discoveredNetworkResource?->management_state ?? 'UNMAPPED' }}</span>
                                </td>
                                <td class="cell-optional cell-muted">
                                    {{ $customer->phone ?: '—' }}
                                    @if ($customer->email)<span class="cell-sub">{{ $customer->email }}</span>@endif
                                </td>
                                <td><span class="ui-status-badge ui-status-badge--{{ $customer->status }}">{{ $customer->status }}</span></td>
                                <td class="mono-value">{{ $customer->latitude !== null && $customer->longitude !== null ? $customer->latitude.', '.$customer->longitude : 'Location not set' }}</td>
                                <td>
                                    <div class="cell-actions">
                                        <a class="button button--quiet button--sm" href="{{ route('customers.edit', $customer) }}">Edit</a>
                                        <a class="button button--quiet button--sm" href="{{ route('customers.show', $customer) }}">Open</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><p class="empty-state">No customers yet. Adopt a persisted Discovery identity explicitly or add a customer manually.</p></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection