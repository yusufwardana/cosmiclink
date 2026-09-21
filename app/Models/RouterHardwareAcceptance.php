<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted, explicitly granted real-hardware acceptance for one router scope.
 *
 * The row records which exact Agent installation a human operator accepted for
 * real RouterOS mutations, together with the compatibility evidence it was
 * granted against (target, normalized RouterOS version, observed identity, and
 * discovery snapshot). It stores references and digests only - never credential
 * material - and it is revocable: any change to the bound Agent scope or the
 * underlying compatibility evidence fails the acceptance closed.
 */
class RouterHardwareAcceptance extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'router_id', 'agent_ref', 'installation_id', 'target', 'routeros_version', 'observed_identity', 'architecture', 'discovery_snapshot_id', 'accepted_by_user_id', 'confirmation_digest', 'accepted_at', 'revoked_by_user_id', 'revoked_at'];

    protected $casts = ['accepted_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
