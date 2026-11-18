<?php

namespace Modules\Users\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Requirement 3 (read replica): account data is always read from the PRIMARY database.
     * Login, token checks, OTP codes and password resets must see a change at once
     * (a new account, a new password, a fresh OTP code); a replica that is a moment
     * behind would refuse a correct password or code. These are single-row lookups
     * by key, so this costs almost nothing.
     */
    public function newQuery()
    {
        return parent::newQuery()->useWritePdo();
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return ['role' => $this->role];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
