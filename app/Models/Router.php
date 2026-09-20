<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Router extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'description', 'host', 'api_port', 'username', 'status', 'driver', 'last_seen_at', 'monitoring_state', 'observer_agent_ref', 'observer_installation_id', 'observer_credential_ref', 'observer_credential_purpose', 'observer_credential_version', 'observer_credential_status', 'observer_migration_state', 'observer_reference_bound_at', 'observer_reference_synced_at', 'observer_synced_agent_ref', 'observer_synced_installation_id', 'observer_synced_credential_ref', 'observer_synced_credential_purpose', 'observer_synced_credential_version', 'observer_synced_credential_status'];

    protected $hidden = ['encrypted_credentials'];

    protected $casts = ['last_seen_at' => 'datetime', 'observer_reference_bound_at' => 'datetime', 'observer_reference_synced_at' => 'datetime', 'observer_credential_version' => 'integer', 'observer_synced_credential_version' => 'integer'];

    public function setPassword(string $password): void
    {
        $this->encrypted_credentials = Crypt::encryptString($password);
    }

    public function password(): ?string
    {
        return $this->encrypted_credentials ? Crypt::decryptString($this->encrypted_credentials) : null;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function networkAccounts()
    {
        return $this->hasMany(NetworkAccount::class);
    }

    public function operationLogs()
    {
        return $this->hasMany(NetworkOperationLog::class);
    }

    public function healthObservations()
    {
        return $this->hasMany(HealthObservation::class, 'subject_id')->where('subject_type', 'router');
    }

    public function networkHealth()
    {
        return $this->healthObservations()->latestOfMany('observed_at');
    }

    public function outageIncidents()
    {
        return $this->hasMany(OutageIncident::class);
    }

    public function discoverySnapshots()
    {
        return $this->hasMany(NetworkDiscoverySnapshot::class);
    }

    public function discoveredResources()
    {
        return $this->hasMany(DiscoveredNetworkResource::class);
    }

    public function reconciliationEvidence()
    {
        return $this->hasMany(ReconciliationEvidence::class);
    }
}
