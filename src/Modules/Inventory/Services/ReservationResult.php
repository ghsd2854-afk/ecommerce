<?php

namespace Modules\Inventory\Services;

final class ReservationResult
{
    /**
     * @param  array<int, array{product_id: int, requested: int, available: int}>  $shortages
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $shortages = [],
    ) {
    }
}
