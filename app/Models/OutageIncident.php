<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutageIncident extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'router_id', 'status', 'detected_at', 'acknowledged_at', 'resolved_at', 'correlation_count', 'evidence_window_minutes'];

    protected $casts = ['detected_at' => 'datetime', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function affectedConnections()
    {
        return $this->belongsToMany(CustomerConnection::class, 'outage_affected_connections')->withPivot('customer_id')->withTimestamps();
    }

    public function messageLogs()
    {
        return $this->hasMany(MessageLog::class);
    }
}
