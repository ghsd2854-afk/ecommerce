<?php

namespace Modules\Users\Contracts;

/**
 * Public API of the Users module for other modules.
 * Other modules never query the users table or use the User model directly.
 */
interface UserDirectory
{
    /**
     * Ids of all administrators.
     *
     * @return array<int, int>
     */
    public function adminIds(): array;
}
