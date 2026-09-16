<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NetworkOperationLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'router_id', 'customer_connection_id', 'initiated_by_user_id', 'operation', 'target', 'request_payload', 'result_payload', 'status', 'error_message', 'started_at', 'completed_at', 'created_at'];

    protected $casts = ['request_payload' => 'array', 'result_payload' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'created_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function customerConnection()
    {
        return $this->belongsTo(CustomerConnection::class);
    }
}
