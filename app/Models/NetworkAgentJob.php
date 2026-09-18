<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkAgentJob extends Model
{
    public const DISCOVER_ROUTER = 'DISCOVER_ROUTER';

    public const PENDING = 'PENDING';

    public const RUNNING = 'RUNNING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    protected $fillable = ['tenant_id', 'network_agent_id', 'router_id', 'initiated_by_user_id', 'job_type', 'status', 'attempt', 'claimed_at', 'completed_at', 'result_meta', 'error_code', 'error_message', 'lease_expires_at', 'last_progress_at', 'fence', 'lease_aware'];

    protected $casts = ['claimed_at' => 'datetime', 'completed_at' => 'datetime', 'result_meta' => 'array', 'lease_expires_at' => 'datetime', 'last_progress_at' => 'datetime', 'lease_aware' => 'boolean', 'attempt' => 'integer'];

    public function agent()
    {
        return $this->belongsTo(NetworkAgent::class, 'network_agent_id');
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
