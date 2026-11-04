<?php

namespace Modules\Products\Services;

use Modules\Products\Contracts\ProductCatalog;
use Modules\Products\Contracts\ProductSummary;
use Modules\Products\Models\Product;

class EloquentProductCatalog implements ProductCatalog
{
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::whereIn('id', array_unique($ids))
            ->get(['id', 'name', 'price'])
            ->mapWithKeys(fn (Product $p) => [
                (int) $p->id => new ProductSummary((int) $p->id, $p->name, (string) $p->price),
            ])
            ->all();
    }
}
