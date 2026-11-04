<?php

namespace Modules\Users\Services;

use Modules\Users\Contracts\UserDirectory;
use Modules\Users\Models\User;

class EloquentUserDirectory implements UserDirectory
{
    public function adminIds(): array
    {
        return User::where('role', 'admin')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
