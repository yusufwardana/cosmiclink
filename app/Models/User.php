<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'tenant_id',
        'email',
        'password',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Whether this user may dispatch network (RouterOS / PPPoE) operations.
     *
     * Capability check only: tenancy is enforced separately by the policies.
     * A missing, empty, or unlisted role fails closed. The `role` column is
     * deliberately absent from $fillable, so it cannot be escalated through
     * ordinary mass assignment.
     */
    public function isNetworkOperator(): bool
    {
        $roles = (array) config('network.operator_roles');

        return $this->role !== null && in_array((string) $this->role, $roles, true);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
