<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function routers()
    {
        return $this->hasMany(Router::class);
    }

    public function networkAccounts()
    {
        return $this->hasMany(NetworkAccount::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function internetPackages()
    {
        return $this->hasMany(InternetPackage::class);
    }

    public function customerConnections()
    {
        return $this->hasMany(CustomerConnection::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function billingAutomationAttempts()
    {
        return $this->hasMany(BillingAutomationAttempt::class);
    }

    public function paymentRequests()
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function paymentProviderEvents()
    {
        return $this->hasMany(PaymentProviderEvent::class);
    }

    public function messageLogs()
    {
        return $this->hasMany(MessageLog::class);
    }

    public function operationLogs()
    {
        return $this->hasMany(NetworkOperationLog::class);
    }
}
