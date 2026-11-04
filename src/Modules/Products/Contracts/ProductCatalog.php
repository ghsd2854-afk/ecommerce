<?php

namespace Modules\Products\Contracts;

/**
 * Public API of the Products module for other modules.
 * Other modules never query the products table or use the Product model directly.
 */
interface ProductCatalog
{
    /**
     * Products that exist, keyed by id. Unknown ids are simply missing from the result.
     *
     * @param  array<int, int>  $ids
     * @return array<int, ProductSummary>
     */
    public function findMany(array $ids): array;
}
