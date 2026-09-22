<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficBucket extends Model
{
    protected $fillable = ['tenant_id', 'router_id', 'source_type', 'subject_key', 'bucket_started_at', 'upload_bytes', 'download_bytes', 'sample_count'];

    protected $casts = ['bucket_started_at' => 'datetime', 'upload_bytes' => 'integer', 'download_bytes' => 'integer', 'sample_count' => 'integer'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
