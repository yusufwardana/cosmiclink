<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoveredNetworkResource extends Model
{
    protected $fillable = ['tenant_id', 'router_id', 'discovery_snapshot_id', 'customer_connection_id', 'network_account_id', 'resource_type', 'external_ref', 'name', 'management_state', 'fingerprint', 'normalized_data', 'first_seen_at', 'last_seen_at'];

    protected $casts = ['normalized_data' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function customerConnection()
    {
        return $this->belongsTo(CustomerConnection::class);
    }

    public function networkAccount()
    {
        return $this->belongsTo(NetworkAccount::class);
    }

    public function discoverySnapshot()
    {
        return $this->belongsTo(NetworkDiscoverySnapshot::class);
    }

    public function reconciliationEvidence()
    {
        return $this->hasMany(ReconciliationEvidence::class, 'adopted_resource_id');
    }
}
