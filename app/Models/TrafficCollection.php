<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficCollection extends Model
{
    protected $fillable = ['tenant_id', 'router_id', 'collected_at', 'provider', 'datasets', 'enrichment_collected', 'metadata'];

    protected $casts = ['collected_at' => 'datetime', 'datasets' => 'array', 'enrichment_collected' => 'boolean', 'metadata' => 'array'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function samples()
    {
        return $this->hasMany(TrafficSample::class);
    }
}
