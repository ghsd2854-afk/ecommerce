<?php

namespace Modules\Products\Exceptions;

use RuntimeException;

/** Thrown by a listener of ProductDeleting when the product is still in use. */
class ProductInUseException extends RuntimeException
{
}
