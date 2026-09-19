<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class NetworkAccount extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'router_id', 'username', 'profile', 'status', 'metadata', 'encrypted_secret', 'management_state', 'managed_at', 'managed_by_user_id', 'revoked_at', 'revoked_by_user_id', 'management_scope'];

    protected $hidden = ['encrypted_secret'];

    protected $casts = ['metadata' => 'array', 'managed_at' => 'datetime', 'revoked_at' => 'datetime', 'management_scope' => 'array'];

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

    public function managedBy()
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
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
