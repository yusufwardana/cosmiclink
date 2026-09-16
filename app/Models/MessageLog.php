<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageLog extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'customer_id', 'invoice_id', 'outage_incident_id', 'customer_connection_id', 'channel', 'provider', 'recipient', 'template', 'rendered_content', 'status', 'provider_message_id', 'attempted_at', 'sent_at', 'failed_at', 'failure_code', 'failure_message', 'metadata', 'idempotency_key'];

    protected $casts = ['attempted_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'metadata' => 'array'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function connection()
    {
        return $this->belongsTo(CustomerConnection::class, 'customer_connection_id');
    }

    public function outageIncident()
    {
        return $this->belongsTo(OutageIncident::class);
    }
}
