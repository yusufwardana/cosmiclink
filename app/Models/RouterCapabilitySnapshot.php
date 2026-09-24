<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouterCapabilitySnapshot extends Model
{
    protected $fillable = [
        'tenant_id', 'router_id', 'routeros_version', 'routeros_major', 'architecture',
        'board', 'capabilities', 'source', 'verified_at',
    ];

    protected $casts = [
        'routeros_major' => 'integer',
        'capabilities' => 'array',
        'verified_at' => 'datetime',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
