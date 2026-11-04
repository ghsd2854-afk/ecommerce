<?php

namespace Modules\Products\Contracts;

/**
 * Read-only copy of the product data other modules are allowed to see.
 */
final class ProductSummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $price,
    ) {
    }
}
