<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkAgent extends Model
{
    protected $fillable = ['tenant_id', 'identifier', 'name', 'token_hash', 'version', 'capabilities', 'metadata', 'last_seen_at'];

    protected $hidden = ['token_hash'];

    protected $casts = ['capabilities' => 'array', 'metadata' => 'array', 'last_seen_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function jobs()
    {
        return $this->hasMany(NetworkAgentJob::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at?->greaterThan(now()->subMinutes(5)) ?? false;
    }
}
