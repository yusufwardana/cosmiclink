<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'invoice_id', 'customer_id', 'provider', 'provider_reference', 'amount', 'currency', 'status', 'payment_url', 'qr_payload', 'expires_at', 'paid_at', 'metadata'];

    protected $casts = ['expires_at' => 'datetime', 'paid_at' => 'datetime', 'metadata' => 'array'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function events()
    {
        return $this->hasMany(PaymentProviderEvent::class);
    }
}
