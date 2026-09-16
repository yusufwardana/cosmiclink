<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class NetworkAccount extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'router_id', 'username', 'profile', 'status', 'metadata', 'encrypted_secret'];

    protected $hidden = ['encrypted_secret'];

    protected $casts = ['metadata' => 'array'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function connections()
    {
        return $this->hasMany(CustomerConnection::class);
    }

    public function setSecret(string $secret): void
    {
        $this->encrypted_secret = Crypt::encryptString($secret);
    }

    public function secret(): ?string
    {
        return $this->encrypted_secret ? Crypt::decryptString($this->encrypted_secret) : null;
    }
}
