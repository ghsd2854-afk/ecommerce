<?php

namespace Modules\Users\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = ['email', 'purpose', 'code_hash', 'attempts', 'expires_at'];

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

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
