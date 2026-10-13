<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, UsesTenantConnection;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'invite_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }

    public function branchRoles(): HasMany
    {
        return $this->hasMany(UserBranchRole::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
