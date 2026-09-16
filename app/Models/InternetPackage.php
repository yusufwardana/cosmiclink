<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternetPackage extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'code', 'download_mbps', 'upload_mbps', 'monthly_price', 'network_profile', 'status', 'description'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connections()
    {
        return $this->hasMany(CustomerConnection::class);
    }
}
