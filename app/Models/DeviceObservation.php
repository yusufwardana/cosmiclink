<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceObservation extends Model
{
    protected $fillable = [
        'tenant_id', 'router_id', 'customer_connection_id', 'source', 'network_identity',
        'ip_address', 'mac_address', 'interface', 'server', 'metadata', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function router() { return $this->belongsTo(Router::class); }
    public function customerConnection() { return $this->belongsTo(CustomerConnection::class); }
}