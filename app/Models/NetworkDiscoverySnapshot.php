<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkDiscoverySnapshot extends Model
{
    protected $fillable = ['tenant_id', 'router_id', 'initiated_by_user_id', 'provider', 'status', 'discovered_at', 'summary', 'snapshot', 'error'];

    protected $casts = ['discovered_at' => 'datetime', 'summary' => 'array', 'snapshot' => 'array'];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function resources()
    {
        return $this->hasMany(DiscoveredNetworkResource::class, 'discovery_snapshot_id');
    }
}
