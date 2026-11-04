<?php

namespace Modules\Inventory\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The inventory service is stopped, unreachable, or did not answer before the deadline.
 * Rendered as a clear JSON error, so the user never waits forever.
 */
class InventoryUnavailableException extends RuntimeException
{
    public const GRPC_DEADLINE_EXCEEDED = 4;

    public function __construct(
        public readonly string $operation,
        public readonly int $grpcCode,
        public readonly string $grpcDetails,
        public readonly int $timeoutMs,
    ) {
        parent::__construct("Inventory {$operation} failed (gRPC code {$grpcCode}): {$grpcDetails}");
    }

    public function isTimeout(): bool
    {
        return $this->grpcCode === self::GRPC_DEADLINE_EXCEEDED;
    }

    public function report(): bool
    {
        Log::warning($this->getMessage());

        return true;
    }

    public function render(): JsonResponse
    {
        if ($this->isTimeout()) {
            return response()->json([
                'message' => "The inventory service did not respond within {$this->timeoutMs} ms. "
                    .'Nothing was changed, please try again.',
            ], 504);
        }

        return response()->json([
            'message' => 'The inventory service is currently unavailable. Nothing was changed, please try again later.',
        ], 503);
    }
}
