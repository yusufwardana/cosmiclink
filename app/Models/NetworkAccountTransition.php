<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Immutable history entry for one managed-account lifecycle transition.
 *
 * The row records *what the lifecycle believed* at the moment of the change: the
 * previous and next management state, the RouterOS target identity that was
 * projected onto the account at that time, who performed it, and a digest of the
 * management scope. Scope contents are never duplicated here, only digested, so
 * the audit trail cannot become a second copy of the grant definition or a place
 * where credentials could leak.
 *
 * @property int $tenant_id
 * @property int $router_id
 * @property int $network_account_id
 * @property int $sequence
 * @property string $from_state
 * @property string $to_state
 * @property bool $policy_allowed
 * @property string|null $reason_code
 * @property int|null $performed_by
 * @property string|null $target_identity_type
 * @property string|null $target_identity_ref
 * @property string|null $target_identity_fingerprint
 * @property string|null $routeros_version
 * @property string|null $scope_digest
 * @property string|null $approval_reference
 * @property Carbon|null $occurred_at
 */
class NetworkAccountTransition extends Model
{
    use HasFactory;

    public const STATE_OBSERVED = 'OBSERVED';

    public const STATE_ADOPTED = 'ADOPTED';

    public const STATE_MANAGED = 'MANAGED';

    public const STATE_UNMANAGED = 'UNMANAGED';

    public const STATE_REVOKED = 'REVOKED';

    /**
     * The documented lifecycle graph. `policy_allowed` reports whether a recorded
     * change sits on this graph; Task 3's lifecycle is what refuses to perform a
     * change that does not.
     *
     * @var array<string, list<string>>
     */
    private const DOCUMENTED_TRANSITIONS = [
        self::STATE_OBSERVED => [self::STATE_ADOPTED, self::STATE_UNMANAGED],
        self::STATE_ADOPTED => [self::STATE_MANAGED, self::STATE_REVOKED, self::STATE_UNMANAGED],
        self::STATE_MANAGED => [self::STATE_UNMANAGED, self::STATE_REVOKED],
        self::STATE_UNMANAGED => [self::STATE_OBSERVED, self::STATE_ADOPTED],
        self::STATE_REVOKED => [self::STATE_OBSERVED],
    ];

    protected $fillable = [
        'tenant_id',
        'router_id',
        'network_account_id',
        'sequence',
        'from_state',
        'to_state',
        'policy_allowed',
        'reason_code',
        'performed_by',
        'target_identity_type',
        'target_identity_ref',
        'target_identity_fingerprint',
        'routeros_version',
        'scope_digest',
        'approval_reference',
        'occurred_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'policy_allowed' => 'boolean',
        'occurred_at' => 'datetime',
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

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return array_keys(self::DOCUMENTED_TRANSITIONS);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function documentedTransitions(): array
    {
        return self::DOCUMENTED_TRANSITIONS;
    }

    public static function isDocumentedTransition(string $fromState, string $toState): bool
    {
        return in_array($toState, self::DOCUMENTED_TRANSITIONS[$fromState] ?? [], true);
    }

    /**
     * Digest a management scope without storing it. Keys and list values are
     * canonicalised first so an equivalent scope cannot produce a different
     * digest and silently look like a different grant.
     *
     * @param  array<mixed>|null  $scope
     */
    public static function scopeDigest(?array $scope): ?string
    {
        if ($scope === null) {
            return null;
        }

        return hash('sha256', json_encode(self::canonicalScope($scope), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Append the next transition for an account. Sequence numbers are assigned
     * under a row lock so concurrent lifecycle work cannot both claim slot N, and
     * the identity columns are copied from the account's current projection so
     * the history shows what was believed at the time, not what is known now.
     *
     * @param  array<mixed>|null  $scope
     */
    public static function record(
        NetworkAccount $account,
        string $fromState,
        string $toState,
        ?User $user = null,
        ?string $reasonCode = null,
        ?array $scope = null,
        ?string $approvalReference = null,
        ?Carbon $occurredAt = null,
    ): self {
        foreach ([$fromState, $toState] as $state) {
            if (! in_array($state, self::states(), true)) {
                throw new InvalidArgumentException("Unknown management state [{$state}] for transition audit.");
            }
        }

        return DB::transaction(function () use ($account, $fromState, $toState, $user, $reasonCode, $scope, $approvalReference, $occurredAt): self {
            $locked = NetworkAccount::query()->lockForUpdate()->findOrFail($account->id);
            $sequence = (int) self::query()->where('network_account_id', $locked->id)->max('sequence') + 1;

            return self::query()->create([
                'tenant_id' => $locked->tenant_id,
                'router_id' => $locked->router_id,
                'network_account_id' => $locked->id,
                'sequence' => $sequence,
                'from_state' => $fromState,
                'to_state' => $toState,
                'policy_allowed' => self::isDocumentedTransition($fromState, $toState),
                'reason_code' => $reasonCode,
                'performed_by' => $user?->id,
                'target_identity_type' => $locked->router_identity_type,
                'target_identity_ref' => $locked->router_identity_ref,
                'target_identity_fingerprint' => $locked->router_identity_fingerprint,
                'routeros_version' => $locked->routeros_version,
                'scope_digest' => self::scopeDigest($scope),
                'approval_reference' => self::boundedReference($approvalReference),
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }

    /**
     * @param  array<mixed>  $scope
     * @return array<mixed>
     */
    private static function canonicalScope(array $scope): array
    {
        ksort($scope);

        foreach ($scope as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $value = self::canonicalScope($value);
            $scope[$key] = array_is_list($value) ? self::sortValues($value) : $value;
        }

        return $scope;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function sortValues(array $values): array
    {
        $order = [];

        foreach ($values as $key => $value) {
            $order[$key] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        asort($order, SORT_STRING);

        $sorted = [];

        foreach (array_keys($order) as $key) {
            $sorted[] = $values[$key];
        }

        return $sorted;
    }

    private static function boundedReference(?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        return ($reference === null || $reference === '') ? null : mb_substr($reference, 0, 64);
    }
}
