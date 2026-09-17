<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkDiscoveryAudit extends Model
{
    protected $fillable = ['tenant_id', 'router_id', 'discovered_network_resource_id', 'initiated_by_user_id', 'action', 'details', 'occurred_at'];

    protected $casts = ['details' => 'array', 'occurred_at' => 'datetime'];
}
