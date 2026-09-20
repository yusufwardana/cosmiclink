<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NetworkOperationLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'router_id', 'customer_connection_id', 'network_account_id', 'initiated_by_user_id', 'operation', 'idempotency_key', 'request_digest', 'execution_id', 'provider', 'execution_mode', 'target', 'request_payload', 'result_payload', 'preflight_evidence', 'postflight_evidence', 'status', 'outcome', 'safety_scope', 'reserved_at', 'error_message', 'failure_code', 'started_at', 'completed_at', 'resolved_by_user_id', 'resolved_at', 'resolution_note', 'created_at'];

    protected $casts = ['request_payload' => 'array', 'result_payload' => 'array', 'preflight_evidence' => 'array', 'postflight_evidence' => 'array', 'started_at' => 'datetime', 'reserved_at' => 'datetime', 'completed_at' => 'datetime', 'resolved_at' => 'datetime', 'created_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

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

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function agentJob()
    {
        return $this->hasOne(NetworkAgentJob::class);
    }
}
