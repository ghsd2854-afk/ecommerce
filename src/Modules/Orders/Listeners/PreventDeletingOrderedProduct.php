<?php

namespace Modules\Orders\Listeners;

use Modules\Orders\Models\OrderItem;
use Modules\Products\Events\ProductDeleting;
use Modules\Products\Exceptions\ProductInUseException;

/**
 * Orders answers the Products module's public event: a product that appears in
 * an order cannot be deleted. Orders only reads its OWN table (order_items).
 */
class PreventDeletingOrderedProduct
{
    public function handle(ProductDeleting $event): void
    {
        if (OrderItem::where('product_id', $event->productId)->exists()) {
            throw new ProductInUseException('This product is part of existing orders.');
        }
    }
}
