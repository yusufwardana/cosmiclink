<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class NetworkAccount extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'router_id', 'username', 'profile', 'status', 'metadata', 'encrypted_secret', 'management_state', 'managed_at', 'managed_by_user_id', 'revoked_at', 'revoked_by_user_id', 'management_scope', 'router_identity_ref', 'router_identity_type', 'router_identity_fingerprint', 'routeros_version', 'router_identity_snapshot_id', 'router_identity_resource_id', 'router_identity_evidence_at'];

    protected $hidden = ['encrypted_secret'];

    protected $casts = ['metadata' => 'array', 'managed_at' => 'datetime', 'revoked_at' => 'datetime', 'management_scope' => 'array', 'router_identity_evidence_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function connections()
    {
        return $this->hasMany(CustomerConnection::class);
    }

    public function managedBy()
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /** Discovery snapshot the recorded RouterOS target identity came from. */
    public function identitySnapshot()
    {
        return $this->belongsTo(NetworkDiscoverySnapshot::class, 'router_identity_snapshot_id');
    }

    /** Adopted discovery resource the recorded RouterOS target identity came from. */
    public function identityResource()
    {
        return $this->belongsTo(DiscoveredNetworkResource::class, 'router_identity_resource_id');
    }

    public function managedTargetSessions()
    {
        return $this->hasMany(ManagedTargetSession::class, 'network_account_id');
    }

    public function preflightEvidence()
    {
        return $this->hasMany(ManagedTargetPreflightEvidence::class, 'network_account_id');
    }

    public function targetActions()
    {
        return $this->hasMany(ManagedTargetAction::class, 'network_account_id');
    }

    /** Ordered lifecycle history; slot 1 is the first recorded transition. */
    public function managementTransitions()
    {
        return $this->hasMany(NetworkAccountTransition::class, 'network_account_id')->orderBy('sequence');
    }

    public function setSecret(string $secret): void
    {
        $this->encrypted_secret = Crypt::encryptString($secret);
    }

    public function secret(): ?string
    {
        return $this->encrypted_secret ? Crypt::decryptString($this->encrypted_secret) : null;
    }
}
