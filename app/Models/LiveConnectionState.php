<?php

namespace App\Models;

use App\Services\Monitoring\LiveConnectionStatus;
use Illuminate\Database\Eloquent\Model;

class LiveConnectionState extends Model
{
    protected $fillable = [
        'tenant_id', 'router_id', 'customer_connection_id', 'state', 'signal_source',
        'confidence', 'upload_bps', 'download_bps', 'latency_ms', 'failure_streak',
        'first_seen_at', 'last_seen_at', 'observed_at', 'metadata',
    ];

    protected $casts = [
        'state' => LiveConnectionStatus::class,
        'confidence' => 'integer',
        'upload_bps' => 'integer',
        'download_bps' => 'integer',
        'latency_ms' => 'integer',
        'failure_streak' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'observed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function connection()
    {
        return $this->belongsTo(CustomerConnection::class, 'customer_connection_id');
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
