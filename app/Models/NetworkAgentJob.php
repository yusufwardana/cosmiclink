<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkAgentJob extends Model
{
    public const DISCOVER_ROUTER = 'DISCOVER_ROUTER';

    public const MUTATE_ENABLE_PPPOE = 'MUTATE_ENABLE_PPPOE';

    public const MUTATE_DISABLE_PPPOE = 'MUTATE_DISABLE_PPPOE';

    public const MUTATE_DISCONNECT_SESSION = 'MUTATE_DISCONNECT_SESSION';

    public const PENDING = 'PENDING';

    public const RUNNING = 'RUNNING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    public const UNKNOWN_OUTCOME = 'UNKNOWN_OUTCOME';

    public const POSTFLIGHT_MISMATCH = 'POSTFLIGHT_MISMATCH';

    protected $fillable = ['tenant_id', 'network_agent_id', 'router_id', 'network_account_id', 'network_operation_log_id', 'initiated_by_user_id', 'job_type', 'status', 'attempt', 'claimed_at', 'completed_at', 'result_meta', 'error_code', 'error_message', 'lease_expires_at', 'last_progress_at', 'fence', 'lease_aware', 'protocol_version', 'operation', 'execution_id', 'idempotency_key', 'request_digest', 'reservation_ref', 'installation_id', 'credential_ref', 'credential_purpose', 'credential_version', 'observer_credential_ref', 'observer_credential_purpose', 'observer_credential_version', 'target_identity_ref', 'account_ref', 'terminal_result_digest'];

    protected $casts = ['claimed_at' => 'datetime', 'completed_at' => 'datetime', 'result_meta' => 'array', 'lease_expires_at' => 'datetime', 'last_progress_at' => 'datetime', 'lease_aware' => 'boolean', 'attempt' => 'integer', 'credential_version' => 'integer', 'observer_credential_version' => 'integer'];

    public static function mutationTypes(): array
    {
        return [self::MUTATE_ENABLE_PPPOE, self::MUTATE_DISABLE_PPPOE, self::MUTATE_DISCONNECT_SESSION];
    }

    public function isMutation(): bool
    {
        return in_array($this->job_type, self::mutationTypes(), true);
    }

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

    public function networkAccount()
    {
        return $this->belongsTo(NetworkAccount::class);
    }

    public function operationLog()
    {
        return $this->belongsTo(NetworkOperationLog::class, 'network_operation_log_id');
    }
}
