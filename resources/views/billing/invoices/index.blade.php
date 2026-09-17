@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Index-First (13) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">06</span><span class="eyebrow__sep">·</span>Invoices</p>
            <h1>Invoices</h1>
            <p class="page-header__meta">
                {{ $invoices->count() }} raised ·
                <em>Rp{{ number_format($invoices->sum(fn ($invoice) => $invoice->outstanding()), 0, ',', '.') }}</em> outstanding ·
                {{ $invoices->where('status', 'unpaid')->count() }} unpaid ·
                {{ $invoices->where('status', 'overdue')->count() }} overdue
            </p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ route('billing.payments.index') }}">All payments</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="invoice-register">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Ledger</p>
                <h2 class="panel__title" id="invoice-register">Invoices</h2>
            </div>
            <span class="panel__meta">{{ $invoices->count() }} rows</span>
        </div>
        <div class="panel__body panel__body--flush">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th class="cell-optional">Customer</th>
                            <th class="cell-optional">Connection</th>
                            <th class="is-numeric">Total</th>
                            <th class="is-numeric">Outstanding</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoices as $invoice)
                            <tr>
                                <td>
                                    <a class="cell-key" href="{{ route('billing.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a>
                                    <span class="cell-sub">{{ $invoice->billing_period_start?->format('M Y') }} · due {{ $invoice->due_date?->format('M j') }}</span>
                                </td>
                                <td class="cell-optional">
                                    {{ $invoice->customer?->name }}
                                    <span class="cell-sub">{{ $invoice->customer?->customer_code }}</span>
                                </td>
                                <td class="cell-optional mono-value">{{ $invoice->connection?->connection_code ?? '—' }}</td>
                                <td class="is-numeric cell-muted">Rp{{ number_format($invoice->total, 0, ',', '.') }}</td>
                                <td class="is-numeric cell-amount">Rp{{ number_format($invoice->outstanding(), 0, ',', '.') }}</td>
                                <td><span class="ui-status-badge ui-status-badge--{{ $invoice->status }}">{{ $invoice->status }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><p class="empty-state">No invoices yet. The batch panel below bills provisioned connections for a month.</p></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <div class="ops-grid ops-grid--halves">
        <section class="panel" aria-labelledby="billing-generate">
            <div class="panel__head">
                <div>
                    <p class="panel__kicker">Batch</p>
                    <h2 class="panel__title" id="billing-generate">Generate invoices</h2>
                </div>
                <span class="panel__meta">{{ now()->format('M Y') }}</span>
            </div>
            <div class="panel__body">
                <form class="spec-form" method="post" action="{{ route('billing.generate') }}">
                    @csrf
                    <label for="period">Billing period <em>One invoice per provisioned connection that is active or suspended. Connections already invoiced for the month are skipped.</em> @error('period')<em class="field-error">{{ $message }}</em>@enderror</label>
                    <input id="period" name="period" type="month" value="{{ old('period', now()->format('Y-m')) }}" required>
                    <div class="spec-form__actions">
                        <button type="submit">Generate invoices</button>
                        <p class="console-note">Console equivalent: <span class="mono-value">billing:generate --period=YYYY-MM</span></p>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel" aria-labelledby="billing-overdue">
            <div class="panel__head">
                <div>
                    <p class="panel__kicker">Enforcement</p>
                    <h2 class="panel__title" id="billing-overdue">Overdue and isolation</h2>
                </div>
                <span class="panel__meta">Suspends service</span>
            </div>
            <div class="panel__body">
                <form class="spec-form" method="post" action="{{ route('billing.overdue') }}">
                    @csrf
                    <p class="console-note is-full">Marks every past-due invoice overdue, then suspends the connections behind them — the same two steps as <span class="mono-value">billing:mark-overdue</span> and <span class="mono-value">billing:enforce</span>, scoped to this tenant.</p>
                    <div class="spec-form__actions">
                        <button class="button--danger">Mark overdue and enforce isolation</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
@endsection