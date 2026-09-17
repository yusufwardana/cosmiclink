@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Tabular spec sheet (F3) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">09</span><span class="eyebrow__sep">·</span>Payment request</p>
            <h1>{{ $paymentRequest->provider_reference }}</h1>
            <p class="page-header__meta">Simulated gateway request for {{ $paymentRequest->invoice?->invoice_number ?? 'an invoice' }} · no real money or QRIS payment is processed.</p>
        </div>
        <div class="page-header__aside">
            @if ($paymentRequest->invoice)
                <a class="button button--quiet" href="{{ route('billing.invoices.show', $paymentRequest->invoice) }}">Invoice</a>
            @endif
        </div>
    </div>

    <section class="panel" aria-labelledby="payment-request-record">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Gateway record</p>
                <h2 class="panel__title" id="payment-request-record">Simulated payment</h2>
            </div>
            <span class="panel__meta">{{ $paymentRequest->provider }}</span>
        </div>
        <div class="panel__body panel__body--flush">
            <dl class="spec">
                <dt>Provider</dt><dd class="mono-value">{{ $paymentRequest->provider }}</dd>
                <dt>Reference</dt><dd class="mono-value">{{ $paymentRequest->provider_reference }}</dd>
                <dt>Amount</dt><dd class="cell-amount">Rp{{ number_format($paymentRequest->amount, 0, ',', '.') }} {{ $paymentRequest->currency }}</dd>
                <dt>State</dt><dd><span class="ui-status-badge ui-status-badge--{{ $paymentRequest->status }}">{{ $paymentRequest->status }}</span></dd>
                <dt>Expires</dt><dd><time class="cell-time">{{ $paymentRequest->expires_at?->format('Y-m-d H:i') ?? '—' }}</time></dd>
                <dt>Payment representation</dt><dd class="mono-value">{{ $paymentRequest->payment_url }}</dd>
            </dl>
        </div>
        @if ($paymentRequest->status === 'pending')
            <div class="panel__foot">
                <p class="console-note">This action creates a provider event, records the payment and updates the invoice — entirely inside the fake gateway.</p>
                <form class="inline-form" method="post" action="{{ route('billing.payment-requests.simulate-success', $paymentRequest) }}">
                    @csrf
                    <button type="submit">Simulate successful payment</button>
                </form>
            </div>
        @endif
    </section>

    <section class="panel" aria-labelledby="payment-events">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Evidence</p>
                <h2 class="panel__title" id="payment-events">Payment event history</h2>
            </div>
            <span class="panel__meta">{{ $paymentRequest->events->count() }} events</span>
        </div>
        <div class="panel__body panel__body--flush">
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Event</th><th>State</th><th>Received</th><th>Processed</th></tr></thead>
                    <tbody>
                        @forelse ($paymentRequest->events as $event)
                            <tr>
                                <td class="cell-key">{{ $event->event_type }}</td>
                                <td><span class="ui-status-badge ui-status-badge--{{ $event->status }}">{{ $event->status }}</span></td>
                                <td><time class="cell-time">{{ $event->received_at?->format('Y-m-d H:i') ?? '—' }}</time></td>
                                <td><time class="cell-time">{{ $event->processed_at?->format('Y-m-d H:i') ?? '—' }}</time></td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><p class="empty-state">No provider events recorded yet.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection