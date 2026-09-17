<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutageIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'router' => ['id' => $this->router_id, 'name' => $this->whenLoaded('router', fn () => $this->router->name)], 'detected_at' => $this->detected_at, 'acknowledged_at' => $this->acknowledged_at, 'resolved_at' => $this->resolved_at, 'correlation_count' => $this->correlation_count, 'affected_customers' => $this->whenLoaded('affectedConnections', fn () => $this->affectedConnections->pluck('customer_id')->unique()->count()), 'affected_connections' => $this->whenLoaded('affectedConnections', fn () => $this->affectedConnections->map(fn ($connection) => ['id' => $connection->id, 'code' => $connection->connection_code, 'customer' => $connection->customer->name, 'status' => $connection->status]))];
    }
}
