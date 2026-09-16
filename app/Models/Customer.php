<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'phone', 'email', 'address', 'notes', 'status'];

    protected static function booted(): void
    {
        static::created(function (self $customer) {
            $customer->customer_code = 'CL'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT);
            $customer->saveQuietly();
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connections()
    {
        return $this->hasMany(CustomerConnection::class);
    }
}
