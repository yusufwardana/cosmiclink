@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">07</span><span class="eyebrow__sep">·</span>Invoice</p>
            <h1>{{ $invoice->invoice_number }}</h1>
            <p class="page-header__meta">{{ $invoice->customer?->name }} · {{ $invoice->connection?->connection_code ?? 'no connection' }} · <em>Rp{{ number_format($invoice->outstanding(), 0, ',', '.') }}</em> outstanding</p>
        </div>
        <div class="page-header__aside">
            @if ($invoice->customer)<a class="button button--quiet" href="{{ route('customers.show', $invoice->customer) }}">Customer</a>@endif
            <a class="button button--quiet" href="{{ route('billing.invoices.index') }}">All invoices</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="invoice-record">
        <div class="panel__head"><div><p class="panel__kicker">Record</p><h2 class="panel__title" id="invoice-record">Invoice</h2></div><span class="panel__meta">{{ $invoice->billing_period_start?->format('M Y') }}</span></div>
        <div class="panel__body panel__body--flush">
            <dl class="spec">
                <dt>Customer</dt><dd>{{ $invoice->customer?->name ?? '—' }}<span class="cell-sub">{{ $invoice->customer?->customer_code }}</span></dd>
                <dt>Connection</dt><dd class="mono-value">{{ $invoice->connection?->connection_code ?? '—' }}</dd>
                <dt>Period</dt><dd>{{ $invoice->billing_period_start?->format('Y-m-d') }} – {{ $invoice->billing_period_end?->format('Y-m-d') }}</dd>
                <dt>Issued</dt><dd><time class="cell-time">{{ $invoice->issue_date?->format('Y-m-d') ?? '—' }}</time></dd>
                <dt>Due</dt><dd><time class="cell-time">{{ $invoice->due_date?->format('Y-m-d') ?? '—' }}</time></dd>
                <dt>Subtotal</dt><dd class="cell-amount">Rp{{ number_format($invoice->subtotal, 0, ',', '.') }}</dd>
                <dt>Discount</dt><dd class="cell-amount">Rp{{ number_format($invoice->discount, 0, ',', '.') }}</dd>
                <dt>Total</dt><dd class="cell-amount">Rp{{ number_format($invoice->total, 0, ',', '.') }}</dd>
                <dt>Paid</dt><dd class="cell-amount">Rp{{ number_format($invoice->paid_amount, 0, ',', '.') }}</dd>
                <dt>Outstanding</dt><dd class="cell-amount">Rp{{ number_format($invoice->outstanding(), 0, ',', '.') }}</dd>
                <dt>State</dt><dd><span class="ui-status-badge ui-status-badge--{{ $invoice->status }}">{{ $invoice->status }}</span></dd>
            </dl>
        </div>
        @if ($invoice->status !== 'paid' && $invoice->status !== 'cancelled')
            <div class="panel__foot">
                <p class="console-note">Reminders and payment requests use the simulated gateways — no real money moves.</p>
                <div class="panel__actions">
                    <form class="inline-form" method="post" action="{{ route('billing.reminders.store', $invoice) }}">@csrf<button class="button--quiet button--sm">Send payment reminder</button></form>
                    <form class="inline-form" method="post" action="{{ route('billing.payment-requests.store', $invoice) }}">@csrf<button class="button--quiet button--sm">Generate simulated payment request</button></form>
                </div>
            </div>
        @endif
    </section>

    <section class="panel" aria-labelledby="invoice-items">
        <div class="panel__head"><div><p class="panel__kicker">Detail</p><h2 class="panel__title" id="invoice-items">Items</h2></div><span class="panel__meta">{{ $invoice->items->count() }} lines</span></div>
        <div class="panel__body panel__body--flush">
            @if ($invoice->items->isEmpty())
                <p class="empty-state">No line items on this invoice.</p>
            @else
                <div class="table-scroll"><table><thead><tr><th>Description</th><th class="is-numeric">Quantity</th><th class="is-numeric">Unit price</th><th class="is-numeric">Amount</th></tr></thead><tbody>
                    @foreach ($invoice->items as $item)
                        <tr><td class="cell-key">{{ $item->description }}</td><td class="is-numeric cell-muted">{{ $item->quantity }}</td><td class="is-numeric cell-muted">Rp{{ number_format($item->unit_price, 0, ',', '.') }}</td><td class="is-numeric cell-amount">Rp{{ number_format($item->amount, 0, ',', '.') }}</td></tr>
                    @endforeach
                </tbody></table></div>
            @endif
        </div>
    </section>

    @if ($invoice->status !== 'paid' && $invoice->status !== 'cancelled')
        <section class="panel" aria-labelledby="invoice-payment">
            <div class="panel__head"><div><p class="panel__kicker">Revenue</p><h2 class="panel__title" id="invoice-payment">Record a manual payment</h2></div><span class="panel__meta">Max Rp{{ number_format($invoice->outstanding(), 0, ',', '.') }}</span></div>
            <div class="panel__body"><form class="spec-form" method="post" action="{{ route('billing.payments.store', $invoice) }}">
                @csrf
                <label for="amount">Amount (Rp) @error('amount')<em class="field-error">{{ $message }}</em>@enderror</label><input id="amount" name="amount" type="number" min="1" max="{{ $invoice->outstanding() }}" value="{{ old('amount') }}" required>
                <label for="payment_reference">Reference @error('payment_reference')<em class="field-error">{{ $message }}</em>@enderror</label><input id="payment_reference" name="payment_reference" value="{{ old('payment_reference') }}" required>
                <label for="method">Method @error('method')<em class="field-error">{{ $message }}</em>@enderror</label><select id="method" name="method"><option value="manual">Manual</option><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option></select>
                <div class="spec-form__actions"><button type="submit">Record payment</button><p class="console-note">Against {{ $invoice->invoice_number }} · Rp{{ number_format($invoice->outstanding(), 0, ',', '.') }} currently outstanding.</p></div>
            </form></div>
        </section>
    @endif

    <div class="ops-grid ops-grid--thirds">
        <section class="panel" aria-labelledby="invoice-requests"><div class="panel__head"><div><p class="panel__kicker">Gateway</p><h2 class="panel__title" id="invoice-requests">Payment requests</h2></div><span class="panel__meta">{{ $invoice->paymentRequests->count() }}</span></div><div class="panel__body">
            @forelse ($invoice->paymentRequests as $request)<a class="feed__item" href="{{ route('billing.payment-requests.show', $request) }}"><span class="feed__label">{{ $request->provider_reference }}</span><span class="feed__detail">{{ $request->provider }}</span><span class="ui-status-badge ui-status-badge--{{ $request->status }}">{{ $request->status }}</span></a>@empty<p class="empty-state">No payment request generated.</p>@endforelse
        </div></section>
        <section class="panel" aria-labelledby="invoice-payments"><div class="panel__head"><div><p class="panel__kicker">Settlements</p><h2 class="panel__title" id="invoice-payments">Payments</h2></div><span class="panel__meta">{{ $invoice->payments->count() }}</span></div><div class="panel__body">
            @forelse ($invoice->payments as $payment)<div class="feed__item"><span class="feed__label">Rp{{ number_format($payment->amount, 0, ',', '.') }}</span><span class="feed__detail">{{ $payment->payment_reference }} · {{ $payment->method }}</span><time class="feed__time">{{ $payment->paid_at?->format('M j, H:i') }}</time></div>@empty<p class="empty-state">No payments recorded.</p>@endforelse
        </div></section>
        <section class="panel" aria-labelledby="invoice-automation"><div class="panel__head"><div><p class="panel__kicker">System</p><h2 class="panel__title" id="invoice-automation">Automation</h2></div><span class="panel__meta">{{ $invoice->automationAttempts->count() }}</span></div><div class="panel__body">
            @forelse ($invoice->automationAttempts as $attempt)<div class="feed__item"><span class="feed__label">{{ $attempt->action }}</span><span class="feed__detail">{{ $attempt->status }}</span><time class="feed__time">{{ $attempt->attempted_at?->format('M j, H:i') }}</time></div>@empty<p class="empty-state">No automation attempts recorded.</p>@endforelse
        </div></section>
    </div>
@endsection