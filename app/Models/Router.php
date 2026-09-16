<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Router extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'description', 'host', 'api_port', 'username', 'status', 'driver', 'last_seen_at'];

    protected $hidden = ['encrypted_credentials'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function setPassword(string $password): void
    {
        $this->encrypted_credentials = Crypt::encryptString($password);
    }

    public function password(): ?string
    {
        return $this->encrypted_credentials ? Crypt::decryptString($this->encrypted_credentials) : null;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function networkAccounts()
    {
        return $this->hasMany(NetworkAccount::class);
    }

    public function operationLogs()
    {
        return $this->hasMany(NetworkOperationLog::class);
    }
}
