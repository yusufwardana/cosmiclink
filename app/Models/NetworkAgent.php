<?php

namespace App\Models;

use App\Services\Network\NetworkAgentHealthService;
use Illuminate\Database\Eloquent\Model;

class NetworkAgent extends Model
{
    protected $fillable = ['tenant_id', 'identifier', 'name', 'token_id', 'token_hash', 'version', 'capabilities', 'metadata', 'last_seen_at', 'observed_health'];

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

    public function currentJob()
    {
        return $this->hasOne(NetworkAgentJob::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('status', NetworkAgentJob::RUNNING));
    }

    public function recentFailure()
    {
        return $this->hasOne(NetworkAgentJob::class)->ofMany(['id' => 'max'], fn ($query) => $query->whereNotNull('error_code'));
    }

    public function isOnline(): bool
    {
        return app(NetworkAgentHealthService::class)->health($this) === 'ONLINE';
    }
}
