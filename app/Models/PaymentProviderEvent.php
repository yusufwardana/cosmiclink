<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProviderEvent extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'payment_request_id', 'invoice_id', 'provider', 'provider_event_id', 'event_type', 'status', 'received_at', 'processed_at', 'failure_code', 'failure_message', 'sanitized_payload'];

    protected $casts = ['received_at' => 'datetime', 'processed_at' => 'datetime', 'sanitized_payload' => 'array'];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
