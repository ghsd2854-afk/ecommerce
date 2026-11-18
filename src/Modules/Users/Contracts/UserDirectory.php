<?php

namespace Modules\Users\Contracts;


interface UserDirectory
{
    /**
     *
     *
     * @return array<int, int>
     */
    public function adminIds(): array;


    public function emailOf(int $userId): ?string;
}