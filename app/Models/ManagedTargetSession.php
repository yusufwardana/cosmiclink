<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A safe projection of one RouterOS session observed during read-only discovery
 * for an account the tenant has adopted. Rows are evidence, never handles: a
 * session is ACTIVE only until a later enumeration no longer reports it, at
 * which point it is superseded rather than deleted so the audit trail stays.
 *
 * @property int $tenant_id
 * @property int $router_id
 * @property int $network_account_id
 * @property string $session_ref
 * @property string $session_ref_type
 * @property string|null $session_name
 * @property string|null $service
 * @property string|null $address
 * @property string|null $caller_id
 * @property int|null $uptime_seconds
 * @property string $status
 * @property Carbon|null $observed_at
 * @property Carbon|null $superseded_at
 */
class ManagedTargetSession extends Model
{
    use HasFactory;

    public const STATE_ACTIVE = 'ACTIVE';

    public const STATE_SUPERSEDED = 'SUPERSEDED';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'network_account_id',
        'discovery_snapshot_id',
        'session_ref',
        'session_ref_type',
        'session_ref_fingerprint',
        'session_name',
        'service',
        'address',
        'caller_id',
        'uptime_seconds',
        'status',
        'observed_at',
        'superseded_at',
    ];

    protected $casts = [
        'uptime_seconds' => 'integer',
        'observed_at' => 'datetime',
        'superseded_at' => 'datetime',
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

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(NetworkDiscoverySnapshot::class, 'discovery_snapshot_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATE_ACTIVE;
    }
}
