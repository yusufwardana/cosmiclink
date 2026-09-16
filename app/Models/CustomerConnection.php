<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerConnection extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'customer_id', 'internet_package_id', 'router_id', 'network_account_id', 'status', 'suspension_reason', 'provisioned_at', 'suspended_at', 'failed_at', 'failure_code', 'failure_message', 'metadata', 'monitoring_state'];

    protected $casts = ['provisioned_at' => 'datetime', 'suspended_at' => 'datetime', 'failed_at' => 'datetime', 'metadata' => 'array'];

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
}
