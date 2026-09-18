<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MonitoringResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'subject_id' => $this->subject_id, 'name' => $this->subject_type === 'router' ? $this->subject?->name : $this->subject?->connection_code, 'customer' => $this->subject_type === 'connection' ? $this->subject?->customer?->name : null, 'router' => $this->subject_type === 'connection' ? $this->subject?->router?->name : null, 'lifecycle_status' => $this->subject_type === 'connection' ? $this->subject?->status : null, 'subject_type' => $this->subject_type, 'health_state' => $this->health_state, 'reachable' => $this->reachable, 'online' => $this->online, 'latency_ms' => $this->latency_ms, 'packet_loss_percent' => $this->packet_loss_percent, 'observed_at' => $this->observed_at, 'provider' => $this->provider, 'metadata' => $this->metadata];
    }
}
