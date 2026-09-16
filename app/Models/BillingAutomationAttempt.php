<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingAutomationAttempt extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'invoice_id', 'customer_connection_id', 'action', 'status', 'attempted_at', 'completed_at', 'failure_code', 'failure_message'];

    protected $casts = ['attempted_at' => 'datetime', 'completed_at' => 'datetime'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function connection()
    {
        return $this->belongsTo(CustomerConnection::class, 'customer_connection_id');
    }
}
