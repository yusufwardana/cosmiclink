<div class="table-scroll">
    <table>
        <thead>
            <tr><th>Operation</th><th>Connection</th><th>Target</th><th>Status</th><th>Time</th></tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td>{{ $log->operation }}</td>
                    <td class="mono-value">{{ $log->customerConnection?->connection_code ?? '—' }}</td>
                    <td>{{ $log->target }}</td>
                    <td><span class="ui-status-badge ui-status-badge--{{ $log->status }}">{{ $log->status }}</span></td>
                    <td><time class="cell-time">{{ $log->created_at }}</time></td>
                </tr>
            @empty
                <tr><td colspan="5">No network operations recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
