<?php

namespace Modules\Products\Events;

/**
 * Fired before a product is deleted. Any module that still needs the product
 * (e.g. Orders) can veto the deletion by throwing ProductInUseException.
 * Products does not know which modules listen.
 */
final class ProductDeleting
{
    public function __construct(public readonly int $productId)
    {
    }
}
