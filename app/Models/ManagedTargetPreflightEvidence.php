<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * RouterOS target preflight evidence for one managed account and operation.
 *
 * The row is a bounded snapshot of what read-only evidence said before an
 * action was considered, keyed to the target identity fingerprint it was taken
 * against. Evidence is only ever an input to the ordered precondition
 * evaluation in the controlled gate: a READY row never authorises a mutation by
 * itself, and an expired or identity-mismatched row must be rejected again.
 *
 * @property int $tenant_id
 * @property int $router_id
 * @property int $network_account_id
 * @property string $operation
 * @property string $outcome
 * @property string|null $reason_code
 * @property string|null $target_identity_type
 * @property string|null $target_identity_ref
 * @property string|null $target_identity_fingerprint
 * @property string|null $account_identity_fingerprint
 * @property string|null $routeros_version
 * @property int|null $active_session_count
 * @property array<string, mixed>|null $evidence
 * @property Carbon|null $observed_at
 * @property Carbon|null $expires_at
 */
class ManagedTargetPreflightEvidence extends Model
{
    use HasFactory;

    public const OUTCOME_READY = 'READY';

    public const OUTCOME_DENIED = 'DENIED';

    public const OUTCOME_UNAVAILABLE = 'UNAVAILABLE';

    protected $table = 'managed_target_preflight_evidence';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'network_account_id',
        'discovery_snapshot_id',
        'network_reconciliation_evidence_id',
        'operation',
        'outcome',
        'reason_code',
        'target_identity_type',
        'target_identity_ref',
        'target_identity_fingerprint',
        'account_identity_fingerprint',
        'routeros_version',
        'active_session_count',
        'evidence',
        'observed_at',
        'expires_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'active_session_count' => 'integer',
        'observed_at' => 'datetime',
        'expires_at' => 'datetime',
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

    public function actions(): HasMany
    {
        return $this->hasMany(ManagedTargetAction::class, 'preflight_evidence_id');
    }

    public function isCurrent(Carbon $now): bool
    {
        return $this->expires_at === null || $this->expires_at->greaterThanOrEqualTo($now);
    }
}
