<?php

namespace App\Models;

use App\Services\Network\ControlledOperationReason;
use App\Support\Network\RouterResourceIdentity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit record for one controlled RouterOS target action decision.
 *
 * A row exists for every decision the managed lifecycle makes, including every
 * denial, so operators can prove *why* nothing happened without re-running the
 * engine. The row is deliberately a dead-end for secrets: the operator
 * confirmation phrase is reduced to a sha256 digest before it is written, the
 * RouterOS target is reduced to an identity fingerprint, and no provider
 * payload, credential, or plaintext confirmation column exists.
 *
 * @property int $tenant_id
 * @property int $router_id
 * @property int $network_account_id
 * @property string $operation
 * @property string $status
 * @property string|null $reason_code
 * @property string|null $failed_precondition
 * @property string|null $target_identity_type
 * @property string|null $target_identity_ref
 * @property string|null $target_identity_fingerprint
 * @property string|null $confirmation_digest
 * @property Carbon|null $decided_at
 */
class ManagedTargetAction extends Model
{
    use HasFactory;

    public const STATUS_DENIED = 'DENIED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_EXECUTING = 'EXECUTING';

    public const STATUS_SUCCEEDED = 'SUCCEEDED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_OUTCOME_UNKNOWN = 'OUTCOME_UNKNOWN';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'network_account_id',
        'customer_connection_id',
        'network_operation_log_id',
        'preflight_evidence_id',
        'requested_by',
        'operation',
        'status',
        'reason_code',
        'failed_precondition',
        'target_identity_type',
        'target_identity_ref',
        'target_identity_fingerprint',
        'confirmation_digest',
        'idempotency_key',
        'request_digest',
        'decided_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(NetworkAccount::class, 'network_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function preflightEvidence(): BelongsTo
    {
        return $this->belongsTo(ManagedTargetPreflightEvidence::class, 'preflight_evidence_id');
    }

    public function operationLog(): BelongsTo
    {
        return $this->belongsTo(NetworkOperationLog::class, 'network_operation_log_id');
    }

    /**
     * Persist one lifecycle decision. Only digests and references are written:
     * the confirmation phrase itself is hashed here and never stored, and no
     * caller-supplied array reaches the row.
     */
    public static function recordAttempt(
        NetworkAccount $account,
        ?User $user,
        string $operation,
        bool $allowed,
        ?string $reasonCode = null,
        ?string $failedPrecondition = null,
        ?RouterResourceIdentity $identity = null,
        ?string $confirmation = null,
        ?string $idempotencyKey = null,
        ?string $requestDigest = null,
        ?Carbon $decidedAt = null,
        ?int $preflightEvidenceId = null,
    ): self {
        return self::query()->create([
            'tenant_id' => $account->tenant_id,
            'router_id' => $account->router_id,
            'network_account_id' => $account->id,
            'customer_connection_id' => $account->connections()->first()?->id,
            'preflight_evidence_id' => $preflightEvidenceId,
            'requested_by' => $user?->id,
            'operation' => $operation,
            'status' => $allowed ? self::STATUS_APPROVED : self::STATUS_DENIED,
            'reason_code' => $reasonCode ?? ($allowed ? ControlledOperationReason::APPROVED : null),
            'failed_precondition' => $allowed ? null : $failedPrecondition,
            'target_identity_type' => $identity?->type,
            'target_identity_ref' => $identity?->ref,
            'target_identity_fingerprint' => $identity?->fingerprint(),
            'confirmation_digest' => self::confirmationDigest($confirmation),
            'idempotency_key' => $idempotencyKey,
            'request_digest' => $requestDigest,
            'decided_at' => $decidedAt ?? now(),
        ]);
    }

    /**
     * A confirmation phrase is only ever comparable, never readable back.
     */
    public static function confirmationDigest(?string $confirmation): ?string
    {
        $confirmation = $confirmation === null ? null : trim($confirmation);

        return ($confirmation === null || $confirmation === '')
            ? null
            : hash('sha256', $confirmation);
    }

    /**
     * True when the stored digest matches the phrase the operator just typed.
     * Comparison happens on digests so the plaintext never reaches the database.
     */
    public function confirms(?string $confirmation): bool
    {
        $digest = self::confirmationDigest($confirmation);

        return $digest !== null && is_string($this->confirmation_digest) && hash_equals($this->confirmation_digest, $digest);
    }
}
