@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Split Studio (15) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">02</span><span class="eyebrow__sep">·</span>Customer</p>
            <h1>{{ $customer->name }}</h1>
            <p class="page-header__meta">
                @if ($customer->phone){{ $customer->phone }} · @endif
                Connections <em>{{ $customer->connections->count() }}</em> ·
                Invoices <em>{{ $customer->invoices->count() }}</em> ·
                Messages <em>{{ $customer->messageLogs->count() }}</em>
            </p>
        </div>
        <div class="page-header__aside">
            <a class="button button--quiet" href="{{ route('customers.edit', $customer) }}">Edit customer</a>
            <a class="button button--primary" href="{{ route('customers.connections.create', $customer) }}">Add connection</a>
        </div>
    </div>

    <div class="ops-grid ops-grid--split">
        <div class="ops-grid">
            <section class="panel" aria-labelledby="customer-record">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">Record</p>
                        <h2 class="panel__title" id="customer-record">Customer</h2>
                    </div>
                    <span class="panel__meta">{{ $customer->customer_code }}</span>
                </div>
                <div class="panel__body panel__body--flush">
                    <dl class="spec">
                        <dt>Code</dt>
                        <dd class="mono-value">{{ $customer->customer_code }}</dd>
                        <dt>State</dt>
                        <dd><span class="ui-status-badge ui-status-badge--{{ $customer->status }}">{{ $customer->status }}</span></dd>
                        <dt>Phone</dt>
                        <dd class="mono-value">{{ $customer->phone ?: '—' }}</dd>
                        <dt>Email</dt>
                        <dd>{{ $customer->email ?: '—' }}</dd>
                        <dt>Address</dt>
                        <dd>{{ $customer->address ?: '—' }}</dd>
                        <dt>Notes</dt>
                        <dd>{{ $customer->notes ?: '—' }}</dd>
                    </dl>
                </div>
            </section>
            <section class="panel" aria-labelledby="customer-connections">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">Fabric</p>
                        <h2 class="panel__title" id="customer-connections">Connections</h2>
                    </div>
                    <span class="panel__meta">{{ $customer->connections->count() }} lines</span>
                </div>
                @if ($customer->connections->isEmpty())
                    <div class="panel__body">
                        <p class="empty-state">No connection yet. Provision one to create the PPPoE account on a router.</p>
                    </div>
                @else
                    <ul class="rack">
                        @foreach ($customer->connections as $connection)
                            @php $health = $connection->networkHealth?->health_state ?? 'unknown'; @endphp
                            <li class="rack__row">
                                <span class="rack__id">
                                    <span class="rack__name">{{ $connection->internetPackage?->name ?? 'No package' }}</span>
                                    <span class="rack__sub">{{ $connection->connection_code }} · {{ $connection->internetPackage?->download_mbps }}/{{ $connection->internetPackage?->upload_mbps }} Mbps · {{ $connection->networkAccount?->username ?? 'no PPPoE account yet' }}</span>
                                </span>
                                <span class="rack__field">
                                    <span class="rack__field-label">Router</span>
                                    <span class="rack__field-value">{{ $connection->router?->name ?? '—' }}</span>
                                </span>
                                <span class="rack__field">
                                    <span class="rack__field-label">State</span>
                                    <span class="ui-status-badge ui-status-badge--{{ $connection->status }}">{{ $connection->status }}</span>
                                </span>
                                @if ($connection->suspension_reason)
                                    <span class="rack__field">
                                        <span class="rack__field-label">Reason</span>
                                        <span class="rack__field-value">{{ str_replace('_', ' ', $connection->suspension_reason) }}</span>
                                    </span>
                                @endif
                                <span class="rack__field">
                                    <span class="rack__field-label">Health</span>
                                    <span class="ui-status-badge ui-status-badge--{{ $health }}">{{ $health }}</span>
                                </span>
                                <span class="rack__field">
                                    <span class="rack__field-label">Checked</span>
                                    <span class="rack__field-value">{{ $connection->networkHealth?->observed_at?->format('Y-m-d H:i') ?? 'never' }}</span>
                                </span>
                                @if ($connection->provisioned_at)
                                    <span class="rack__field">
                                        <span class="rack__field-label">Provisioned</span>
                                        <span class="rack__field-value">{{ $connection->provisioned_at->format('Y-m-d') }}</span>
                                    </span>
                                @endif
                                <span class="rack__action">
                                    @if ($connection->status === 'active')
                                        <span class="cell-muted">In service</span>
                                    @else
                                        <form class="inline-form" method="post" action="{{ route('connections.provision', $connection) }}">
                                            @csrf
                                            <button type="submit" class="button--sm">{{ $connection->status === 'failed' ? 'Retry provisioning' : 'Provision connection' }}</button>
                                        </form>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="panel" aria-labelledby="customer-operations">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">Activity</p>
                        <h2 class="panel__title" id="customer-operations">Network operations</h2>
                    </div>
                    <span class="panel__meta">last {{ $recentLogs->count() }}</span>
                </div>
                <div class="panel__body panel__body--flush">
                    @include('network._logs', ['logs' => $recentLogs])
                </div>
            </section>
        </div>
        <div class="ops-grid">
            <section class="panel" aria-labelledby="customer-invoices">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">Revenue</p>
                        <h2 class="panel__title" id="customer-invoices">Invoices</h2>
                    </div>
                    <a class="panel__meta" href="{{ route('billing.invoices.index') }}">{{ $customer->invoices->count() }} raised</a>
                </div>
                <div class="panel__body">
                    @forelse ($customer->invoices->take(5) as $invoice)
                        <a class="feed__item" href="{{ route('billing.invoices.show', $invoice) }}">
                            <span class="feed__label">Rp{{ number_format($invoice->outstanding(), 0, ',', '.') }}</span>
                            <span class="feed__detail">{{ $invoice->invoice_number }} · {{ $invoice->status }}</span>
                            <time class="feed__time">{{ $invoice->due_date?->format('M j') }}</time>
                        </a>
                    @empty
                        <p class="empty-state">No invoices raised for this customer yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="panel" aria-labelledby="customer-payments">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">Revenue</p>
                        <h2 class="panel__title" id="customer-payments">Payments</h2>
                    </div>
                    <a class="panel__meta" href="{{ route('billing.payments.index') }}">{{ $customer->payments->count() }} recorded</a>
                </div>
                <div class="panel__body">
                    @forelse ($customer->payments->take(5) as $payment)
                        <div class="feed__item">
                            <span class="feed__label">Rp{{ number_format($payment->amount, 0, ',', '.') }}</span>
                            <span class="feed__detail">{{ $payment->payment_reference }} · {{ $payment->method }}</span>
                            <time class="feed__time">{{ $payment->paid_at?->format('M j, H:i') ?? '—' }}</time>
                        </div>
                    @empty
                        <p class="empty-state">No payments recorded for this customer yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="panel" aria-labelledby="customer-messages">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">Outreach</p>
                        <h2 class="panel__title" id="customer-messages">Messages</h2>
                    </div>
                    <a class="panel__meta" href="{{ route('messages.index') }}">{{ $customer->messageLogs->count() }} logged</a>
                </div>
                <div class="panel__body">
                    @forelse ($customer->messageLogs->take(5) as $message)
                        <div class="feed__item">
                            <span class="feed__label">{{ $message->template }}</span>
                            <span class="feed__detail">{{ $message->status }} · {{ $message->provider }}</span>
                            <time class="feed__time">{{ $message->created_at?->format('M j, H:i') }}</time>
                        </div>
                    @empty
                        <p class="empty-state">No messages sent to this customer yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="panel" aria-labelledby="customer-incidents">
                <div class="panel__head">
                    <div>
                        <p class="panel__kicker">Reliability</p>
                        <h2 class="panel__title" id="customer-incidents">Outages</h2>
                    </div>
                    <a class="panel__meta" href="{{ route('monitoring.index') }}">{{ $recentIncidents->count() }} recent</a>
                </div>
                <div class="panel__body">
                    @forelse ($recentIncidents as $incident)
                        <a class="feed__item" href="{{ route('monitoring.incidents.show', $incident) }}">
                            <span class="feed__label">{{ $incident->router?->name ?? 'Unassigned router' }}</span>
                            <span class="feed__detail">{{ $incident->status }}@if ($incident->resolved_at) · <em>resolved {{ $incident->resolved_at->format('M j, H:i') }}</em>@endif</span>
                            <time class="feed__time">{{ $incident->detected_at?->format('M j, H:i') }}</time>
                        </a>
                    @empty
                        <p class="empty-state">No correlated outages touching this customer.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection