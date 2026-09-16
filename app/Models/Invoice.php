<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'customer_id', 'customer_connection_id', 'invoice_number', 'billing_period_start', 'billing_period_end', 'issue_date', 'due_date', 'subtotal', 'discount', 'total', 'paid_amount', 'status', 'paid_at'];

    protected $casts = ['billing_period_start' => 'date', 'billing_period_end' => 'date', 'issue_date' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime'];

    protected static function booted(): void
    {
        static::created(function (self $invoice) {
            $invoice->invoice_number = 'INV-'.$invoice->billing_period_start->format('Ym').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
            $invoice->saveQuietly();
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function connection()
    {
        return $this->belongsTo(CustomerConnection::class, 'customer_connection_id');
    }

    public function customerConnection()
    {
        return $this->connection();
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function automationAttempts()
    {
        return $this->hasMany(BillingAutomationAttempt::class);
    }

    public function paymentRequests()
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function outstanding(): int
    {
        return max(0, $this->total - $this->paid_amount);
    }
}
