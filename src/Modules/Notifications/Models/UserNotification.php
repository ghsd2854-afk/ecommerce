<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $table = 'user_notifications';

    protected $fillable = ['user_id', 'type', 'message', 'read_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'read_at' => 'datetime'];
    }
}
