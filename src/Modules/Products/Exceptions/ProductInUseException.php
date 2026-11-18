<?php

namespace Modules\Products\Exceptions;

use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Business rule: a product that is still needed by another module (e.g. it appears
 * in an order) cannot be deleted.
 *
 * Thrown by a listener of the ProductDeleting event. It renders itself as a
 * 409 Conflict in the API's unified error format (App\Http\ApiError).
 * (All the other API errors - 401, 403, 404, 405, 422, 429, 500 - are rendered
 * centrally in bootstrap/app.php.)
 */
class ProductInUseException extends RuntimeException
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct('This product is part of existing orders and cannot be deleted.');
    }

    /** An expected business rule, not a failure: nothing to write to the error log. */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        return ApiError::response(409, 'PRODUCT_IN_USE', $this->getMessage(), ['product_id' => $this->productId]);
    }
}
