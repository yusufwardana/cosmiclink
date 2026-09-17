@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Index-First (13) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">08</span><span class="eyebrow__sep">·</span>Payments</p>
            <h1>Payments</h1>
            <p class="page-header__meta">
                {{ $payments->count() }} recorded ·
                <em>Rp{{ number_format($payments->sum('amount'), 0, ',', '.') }}</em> total ·
                {{ $payments->where('method', 'cash')->count() }} cash ·
                {{ $payments->where('method', 'bank_transfer')->count() }} transfer ·
                {{ $payments->where('method', 'manual')->count() }} manual
            </p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ route('billing.invoices.index') }}">Invoices</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="payment-register">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Ledger</p>
                <h2 class="panel__title" id="payment-register">Payments</h2>
            </div>
            <span class="panel__meta">{{ $payments->count() }} rows</span>
        </div>
        <div class="panel__body panel__body--flush">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Invoice</th>
                            <th class="cell-optional">Customer</th>
                            <th class="is-numeric">Amount</th>
                            <th class="cell-optional">Method</th>
                            <th class="cell-optional">Paid at</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            <tr>
                                <td class="mono-value">{{ $payment->payment_reference }}</td>
                                <td>
                                    <a class="cell-key" href="{{ route('billing.invoices.show', $payment->invoice) }}">{{ $payment->invoice?->invoice_number ?? '—' }}</a>
                                </td>
                                <td class="cell-optional">
                                    {{ $payment->customer?->name }}
                                    <span class="cell-sub">{{ $payment->customer?->customer_code }}</span>
                                </td>
                                <td class="is-numeric cell-amount">Rp{{ number_format($payment->amount, 0, ',', '.') }}</td>
                                <td class="cell-optional cell-muted">{{ str_replace('_', ' ', $payment->method) }}</td>
                                <td class="cell-optional"><time class="cell-time">{{ $payment->paid_at?->format('Y-m-d H:i') ?? '—' }}</time></td>
                                <td><span class="ui-status-badge ui-status-badge--{{ $payment->status }}">{{ $payment->status }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7"><p class="empty-state">No payments recorded yet. They arrive from manual entry on an invoice or from a simulated gateway event.</p></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection