<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MacVendorRegistry extends Model
{
    protected $table = 'mac_vendor_registry';

    protected $fillable = ['prefix', 'prefix_length', 'vendor', 'source', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}