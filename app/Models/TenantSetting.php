<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    protected $fillable = ['tenant_id', 'key', 'value'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}