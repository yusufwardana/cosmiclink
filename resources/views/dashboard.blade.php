@extends('layouts.app')

@section('content')
<div class="page-header page-header--command">
    <div>
        <p class="eyebrow"><span class="eyebrow__ord">00</span><span class="eyebrow__sep">//</span>Dashboard / CosmicLink Overview</p>
        <h1>ISP operations, automated.</h1>
        <p class="page-header__meta">Customer, network, billing and outage state assembled from live CosmicLink records.</p>
    </div>
    <div class="page-header__aside">
        <a class="button button--quiet button--sm" href="{{ route('monitoring.index') }}">Monitoring console</a>
    </div>
</div>

<div class="stat-grid stat-grid--telemetry">
    <article class="stat-card stat-card--accent stat-card--interactive">
        <span class="stat-card__label">Customers</span>
        <span class="stat-card__value" data-counter="{{ $customerCount }}">{{ $customerCount }}</span>
        <span class="stat-card__hint">{{ $activeCustomers }} active accounts</span>
    </article>
    <article class="stat-card stat-card--accent stat-card--interactive">
        <span class="stat-card__label">Online</span>
        <span class="stat-card__value" data-counter="{{ $activeConnections }}">{{ $activeConnections }}</span>
        <span class="stat-card__hint">of {{ $connectionCount }} total · {{ $failedConnections }} failed</span>
    </article>
    <article class="stat-card stat-card--warning stat-card--interactive">
        <span class="stat-card__label">Billing attention</span>
        <span class="stat-card__value" data-counter="{{ $overdueInvoices }}">{{ $overdueInvoices }}</span>
        <span class="stat-card__hint">overdue · Rp{{ number_format($outstandingAmount, 0, ',', '.') }} outstanding</span>
    </article>
    <article class="stat-card stat-card--danger stat-card--interactive">
        <span class="stat-card__label">Active incidents</span>
        <span class="stat-card__value" data-counter="{{ $activeIncidentCount }}">{{ $activeIncidentCount }}</span>
        <span class="stat-card__hint">{{ $routerHealth['offline'] }} routers offline · {{ $routerHealth['degraded'] }} degraded</span>
    </article>
</div>

<div class="ops-grid ops-grid--split">
    <section class="panel panel--primary" aria-labelledby="fabric-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">[NET] Network health // 01</p>
                <h2 class="panel__title" id="fabric-heading">Network health</h2>
            </div>
            <span class="panel__meta panel__meta--terminal"><span class="terminal-tag">[LIVE]</span>{{ $onlineRouters }}/{{ $routerCount }} routers available</span>
        </div>
        <div class="panel__body panel__body--flush">
            <figure class="apparatus apparatus--compact">
                <div class="apparatus__stack">
                    <div class="apparatus__node apparatus__node--core">
                        <span class="apparatus__kicker">Internet / Core uplink</span>
                        <span class="apparatus__value">{{ $routerCount }}</span>
                        <span class="apparatus__note">{{ $onlineRouters }} routers available</span>
                    </div>
                    <span class="apparatus__link" aria-hidden="true"></span>
                    <div class="apparatus__node">
                        <span class="apparatus__kicker">PPPoE fabric</span>
                        <span class="apparatus__value">{{ $activeAccounts }}</span>
                        <span class="apparatus__note">{{ $accountCount }} subscriber accounts · {{ $disabledAccounts }} disabled</span>
                    </div>
                    <span class="apparatus__bus" aria-hidden="true"></span>
                    <ul class="apparatus__branches">
                        <li class="apparatus__branch apparatus__branch--ok">
                            <span class="apparatus__kicker">Online</span>
                            <span class="apparatus__value">{{ $activeConnections }}</span>
                            <span class="apparatus__note">active connections</span>
                        </li>
                        <li class="apparatus__branch apparatus__branch--warn">
                            <span class="apparatus__kicker">Provisioning</span>
                            <span class="apparatus__value">{{ $pendingConnections }}</span>
                            <span class="apparatus__note">pending connections</span>
                        </li>
                        <li class="apparatus__branch apparatus__branch--alert">
                            <span class="apparatus__kicker">Faulted</span>
                            <span class="apparatus__value">{{ $failedConnections }}</span>
                            <span class="apparatus__note">failed connections</span>
                        </li>
                    </ul>
                </div>
                <figcaption class="apparatus__caption">
                    <span>Total connections <b>{{ $connectionCount }}</b></span>
                    <span>Suspended for billing <b>{{ $billingSuspendedConnections }}</b></span>
                    <span>Last observation <b>{{ $latestObservationAt ?? 'none recorded' }}</b></span>
                </figcaption>
            </figure>
        </div>
    </section>

    <section class="panel panel--diagnostic" aria-labelledby="health-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Telemetry</p>
                <h2 class="panel__title" id="health-heading">Network health</h2>
            </div>
            <span class="panel__meta">{{ $routerHealthObserved }} of {{ $routerCount }} routers observed</span>
        </div>
        <div class="panel__body">
            @php($healthTotal = max(array_sum($routerHealth), 1))
            <div class="health-bar" role="img" aria-label="Router health distribution: {{ $routerHealth['online'] }} online, {{ $routerHealth['degraded'] }} degraded, {{ $routerHealth['offline'] }} offline, {{ $routerHealth['unknown'] }} unobserved">
                @foreach ($routerHealth as $state => $count)
                    @if ($count > 0)
                        <span class="health-bar__seg health-bar__seg--{{ $state }}" style="width: {{ round($count / $healthTotal * 100, 2) }}%"></span>
                    @endif
                @endforeach
            </div>
            <ul class="health-legend health-legend--readout">
                @foreach ($routerHealth as $state => $count)
                    <li><span class="health-legend__dot health-legend__dot--{{ $state }}" aria-hidden="true"></span>{{ ucfirst($state) }} <span class="health-legend__value">{{ $count }}</span></li>
                @endforeach
            </ul>
            <dl class="meta-list health-facts">
                <div><dt>Last observation</dt><dd class="mono-value">{{ $latestObservationAt ?? '—' }}</dd></div>
                <div><dt>Accounts disabled</dt><dd class="mono-value">{{ $disabledAccounts }}</dd></div>
                <div><dt>Suspended for billing</dt><dd class="mono-value">{{ $billingSuspendedConnections }}</dd></div>
            </dl>
            @if ($routerTelemetry)
                @php($uptimeSeconds = (int) ($routerTelemetry['uptime_seconds'] ?? 0))
                <dl class="meta-list health-facts">
                    <div><dt>Observed router</dt><dd class="mono-value">{{ $routerTelemetry['router'] ?? '—' }}</dd></div>
                    <div><dt>Router identity</dt><dd class="mono-value">{{ $routerTelemetry['identity'] ?? '—' }}</dd></div>
                    <div><dt>RouterOS version</dt><dd class="mono-value">{{ $routerTelemetry['version'] ?? '—' }}</dd></div>
                    <div><dt>Board</dt><dd class="mono-value">{{ $routerTelemetry['board'] ?? '—' }}</dd></div>
                    <div><dt>Uptime</dt><dd class="mono-value">{{ $uptimeSeconds > 0 ? intdiv($uptimeSeconds, 86400).'d '.intdiv($uptimeSeconds % 86400, 3600).'h '.intdiv($uptimeSeconds % 3600, 60).'m' : '—' }}</dd></div>
                    <div><dt>CPU load</dt><dd class="mono-value">{{ isset($routerTelemetry['cpu_load_percent']) ? $routerTelemetry['cpu_load_percent'].'%' : '—' }}</dd></div>
                    <div><dt>Memory used</dt><dd class="mono-value">{{ isset($routerTelemetry['memory_used_percent']) ? $routerTelemetry['memory_used_percent'].'%' : '—' }}</dd></div>
                </dl>
            @endif
        </div>
    </section>
</div>

<div class="ops-grid ops-grid--split">
    <section class="panel panel--action" aria-labelledby="outages-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">[INC] Operations</p>
                <h2 class="panel__title" id="outages-heading">Active outages</h2>
            </div>
            <a class="panel__meta" href="{{ route('monitoring.index') }}#outage-incidents">All incidents</a>
        </div>
        <div class="panel__body">
            @forelse ($activeIncidents as $incident)
                <article class="incident incident--{{ $incident->status }}">
                    <div class="incident__head">
                        <span class="rack__context" aria-hidden="true">[INC]</span>
                        <span class="ui-status-badge ui-status-badge--{{ $incident->status }}">{{ $incident->status }}</span>
                        <span class="incident__router">{{ $incident->router?->name ?? 'Unassigned router' }}</span>
                        <span class="incident__stamp">{{ $incident->detected_at?->format('Y-m-d H:i') }}</span>
                    </div>
                    <div class="incident__body">
                        @include('monitoring._stages', ['incident' => $incident])
                        <p class="console-note">{{ $incident->affected_connections_count }} affected connections · {{ $incident->correlation_count }} observations correlated within {{ $incident->evidence_window_minutes }} minutes</p>
                    </div>
                </article>
            @empty
                <p class="empty-state empty-state--nominal">No active correlated outages. Observed network state is nominal.</p>
            @endforelse
        </div>
    </section>

    <section class="panel" aria-labelledby="billing-ops-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Billing operations // 02</p>
                <h2 class="panel__title" id="billing-ops-heading">Billing operations</h2>
            </div>
            <span class="panel__meta">{{ $unpaidInvoices }} unpaid · {{ $overdueInvoices }} overdue</span>
        </div>
        <div class="panel__body billing-ops">
            <form class="billing-ops__period" method="post" action="{{ route('billing.generate') }}">
                @csrf
                <label>Billing period<input name="period" type="month" value="{{ now()->format('Y-m') }}" required></label>
                <button type="submit">Generate monthly invoices</button>
            </form>
            <div class="billing-ops__actions">
                <form method="post" action="{{ route('billing.overdue') }}">
                    @csrf
                    <button type="submit" class="button--danger">Mark overdue and enforce isolation</button>
                </form>
            </div>
            <p class="console-note">Outstanding balance Rp{{ number_format($outstandingAmount, 0, ',', '.') }} across unpaid and overdue invoices.</p>
        </div>
    </section>
</div>

<div class="ops-grid ops-grid--halves">
    <section class="panel" aria-labelledby="payments-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Payment stream // 03</p>
                <h2 class="panel__title" id="payments-heading">Recent payments</h2>
            </div>
            <a class="panel__meta" href="{{ route('billing.payments.index') }}">All payments</a>
        </div>
        <div class="panel__body">
            @forelse ($recentPayments as $payment)
                <div class="feed__item">
                    <span class="feed__label">Rp{{ number_format($payment->amount, 0, ',', '.') }}</span>
                    <span class="feed__detail">{{ $payment->payment_reference }} · {{ $payment->method }}</span>
                    <time class="feed__time">{{ $payment->paid_at?->format('M j, H:i') ?? '—' }}</time>
                </div>
            @empty
                <p class="empty-state">No payments recorded yet.</p>
            @endforelse
        </div>
    </section>

    <section class="panel" aria-labelledby="logs-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Operation log // 04</p>
                <h2 class="panel__title" id="logs-heading">Recent network operations</h2>
            </div>
            <a class="panel__meta" href="{{ route('network.logs.index') }}">All logs</a>
        </div>
        <div class="panel__body panel__body--flush">
            @include('network._logs', ['logs' => $recentLogs])
        </div>
    </section>
</div>
@endsection

