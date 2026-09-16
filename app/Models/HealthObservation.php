<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthObservation extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'subject_type', 'subject_id', 'health_state', 'reachable', 'online', 'latency_ms', 'packet_loss_percent', 'observed_at', 'provider', 'metadata'];

    protected $casts = ['reachable' => 'boolean', 'online' => 'boolean', 'observed_at' => 'datetime', 'metadata' => 'array'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subject()
    {
        return match ($this->subject_type) {
            'router' => $this->belongsTo(Router::class, 'subject_id'),
            'connection' => $this->belongsTo(CustomerConnection::class, 'subject_id'),
            default => null,
        };
    }
}
