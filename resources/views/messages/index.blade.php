@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Index-First (13) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">14</span><span class="eyebrow__sep">·</span>Messages</p>
            <h1>Message log</h1>
            <p class="page-header__meta">Outbound customer communication recorded by CosmicLink. The provider is simulated in this environment — nothing is sent externally.</p>
        </div>
        <div class="page-header__aside"><span class="topbar__mode">SIMULATION MODE</span></div>
    </div>

    <section class="panel" aria-labelledby="message-ledger">
        <div class="panel__head"><div><p class="panel__kicker">Outreach</p><h2 class="panel__title" id="message-ledger">Messages</h2></div><span class="panel__meta">{{ $messages->count() }} logged</span></div>
        <div class="panel__body panel__body--flush"><div class="table-scroll"><table><thead><tr><th>Customer</th><th>Template</th><th class="cell-optional">Channel</th><th class="cell-optional">Recipient</th><th>State</th><th>Content</th></tr></thead><tbody>
            @forelse ($messages as $message)
                <tr>
                    <td class="cell-key">{{ $message->customer?->name ?? '—' }}<span class="cell-sub">{{ $message->customer?->customer_code }}</span></td>
                    <td class="mono-value">{{ $message->template }}</td>
                    <td class="cell-optional cell-muted">{{ $message->channel }} · {{ $message->provider }}</td>
                    <td class="cell-optional mono-value">{{ $message->recipient }}</td>
                    <td><span class="ui-status-badge ui-status-badge--{{ $message->status }}">{{ $message->status }}</span></td>
                    <td>{{ $message->rendered_content }}</td>
                </tr>
            @empty
                <tr><td colspan="6"><p class="empty-state">No messages logged yet. Payment reminders and outage notifications appear here after their actions run.</p></td></tr>
            @endforelse
        </tbody></table></div></div>
    </section>
@endsection