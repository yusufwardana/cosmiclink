<?php

namespace App\Actions;

use App\Models\MessageLog;
use App\Models\OutageIncident;
use App\Models\User;

class SendOutageIncidentNotification
{
    public function __construct(private readonly SendCustomerMessage $messenger) {}

    public function handle(OutageIncident $incident, string $event, User $user): void
    {
        abort_unless($incident->tenant_id === $user->tenant_id, 403);
        $template = $event === 'detected' ? 'outage_detected' : 'outage_resolved';
        $incident->loadMissing(['router', 'affectedConnections.customer']);

        foreach ($incident->affectedConnections as $connection) {
            $key = implode(':', ['outage', $incident->id, $connection->customer_id, $event]);
            if (MessageLog::where('idempotency_key', $key)->exists()) {
                continue;
            }

            $this->messenger->handle($connection->customer, $template, [
                'customer_name' => $connection->customer->name,
                'router_name' => $incident->router->name,
                'connection_code' => $connection->connection_code,
            ], $user, null, $connection, $key, $incident);
        }
    }
}
