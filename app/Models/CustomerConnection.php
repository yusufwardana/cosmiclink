<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerConnection extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'customer_id', 'internet_package_id', 'router_id', 'network_account_id', 'status', 'suspension_reason', 'provisioned_at', 'suspended_at', 'failed_at', 'failure_code', 'failure_message', 'metadata', 'monitoring_state'];

    protected $casts = ['provisioned_at' => 'datetime', 'suspended_at' => 'datetime', 'failed_at' => 'datetime', 'metadata' => 'array'];

    /**
     * Canonical business access mode with legacy compatibility.
     * Existing simple_queue rows represent static-IP access.
     */
    public function getAccessModeAttribute(): string
    {
        return (string) ($this->metadata['access_mode'] ?? match ($this->metadata['connection_mode'] ?? null) {
            'simple_queue' => 'static_ip',
            'hotspot' => 'hotspot',
            default => 'unknown',
        });
    }

    /**
     * Router/network implementation mechanism, kept separate from business
     * access mode while preserving the legacy connection_mode field.
     */
    public function getNetworkMechanismAttribute(): string
    {
        return (string) ($this->metadata['network_mechanism'] ?? match ($this->metadata['connection_mode'] ?? null) {
            'simple_queue' => 'simple_queue',
            'hotspot' => 'hotspot',
            default => 'unknown',
        });
    }

    public function getAccessModeLabelAttribute(): string
    {
        return match ($this->access_mode) {
            'static_ip' => 'STATIC IP',
            'hotspot' => 'HOTSPOT',
            default => strtoupper(str_replace('_', ' ', $this->access_mode)),
        };
    }

    public function getNetworkMechanismLabelAttribute(): string
    {
        return match ($this->network_mechanism) {
            'simple_queue' => 'Simple Queue',
            'hotspot' => 'Hotspot',
            default => strtoupper(str_replace('_', ' ', $this->network_mechanism)),
        };
    }

    protected static function booted(): void
    {
        static::created(function (self $connection) {
            $connection->connection_code = 'CON'.str_pad((string) $connection->id, 6, '0', STR_PAD_LEFT);
            $connection->saveQuietly();
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function automationAttempts()
    {
        return $this->hasMany(BillingAutomationAttempt::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function internetPackage()
    {
        return $this->belongsTo(InternetPackage::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function networkAccount()
    {
        return $this->belongsTo(NetworkAccount::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function operationLogs()
    {
        return $this->hasMany(NetworkOperationLog::class);
    }

    public function messageLogs()
    {
        return $this->hasMany(MessageLog::class);
    }

    public function healthObservations()
    {
        return $this->hasMany(HealthObservation::class, 'subject_id')->where('subject_type', 'connection');
    }

    public function networkHealth()
    {
        return $this->healthObservations()->latestOfMany('observed_at');
    }

    public function outageIncidents()
    {
        return $this->belongsToMany(OutageIncident::class, 'outage_affected_connections');
    }

    public function discoveredNetworkResources()
    {
        return $this->hasMany(DiscoveredNetworkResource::class);
    }

    public function discoveredNetworkResource()
    {
        return $this->hasOne(DiscoveredNetworkResource::class)->latestOfMany();
    }

    public function deviceObservations()
    {
        return $this->hasMany(DeviceObservation::class)->latest('last_seen_at');
    }
}
