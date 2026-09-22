<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficSample extends Model
{
    protected $fillable = ['traffic_collection_id', 'tenant_id', 'router_id', 'source_type', 'source_key', 'subject_key', 'upload_bytes', 'download_bytes', 'upload_delta_bytes', 'download_delta_bytes', 'delta_status', 'observed_at', 'metadata'];

    protected $casts = ['observed_at' => 'datetime', 'metadata' => 'array', 'upload_bytes' => 'integer', 'download_bytes' => 'integer', 'upload_delta_bytes' => 'integer', 'download_delta_bytes' => 'integer'];

    public function collection()
    {
        return $this->belongsTo(TrafficCollection::class, 'traffic_collection_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
