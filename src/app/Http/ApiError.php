<?php

namespace App\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The ONE place that builds API error responses.
 *
 * Every API error, whatever its cause, has the same shape:
 *
 *   {
 *     "status":  409,                    // HTTP status code (also in the response line)
 *     "code":    "INSUFFICIENT_STOCK",   // stable machine-readable error code
 *     "message": "Human readable text",  // safe to show to the user
 *     "errors":  { ... },                // only for validation errors (422): field => messages
 *     "details": { ... }                 // only when there is extra data (e.g. shortages)
 *   }
 *
 * Exceptions are converted centrally in bootstrap/app.php; controllers and module
 * exceptions use ApiError::response() for their business errors.
 */
final class ApiError
{
    private const CODES = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        409 => 'CONFLICT',
        413 => 'PAYLOAD_TOO_LARGE',
        415 => 'UNSUPPORTED_MEDIA_TYPE',
        422 => 'VALIDATION_FAILED',
        429 => 'TOO_MANY_REQUESTS',
        500 => 'INTERNAL_ERROR',
        503 => 'SERVICE_UNAVAILABLE',
        504 => 'GATEWAY_TIMEOUT',
    ];

    private const MESSAGES = [
        400 => 'The request is not valid.',
        401 => 'Authentication is required. Log in and send the access token.',
        403 => 'You do not have permission to perform this action.',
        404 => 'Resource not found.',
        405 => 'Method not allowed.',
        409 => 'The request conflicts with the current state of the resource.',
        413 => 'The request is too large.',
        422 => 'The given data is invalid.',
        429 => 'Too many attempts. Please try again later.',
        500 => 'An unexpected error occurred. Please try again later.',
        503 => 'The service is temporarily unavailable. Please try again later.',
        504 => 'The service did not respond in time. Please try again.',
    ];

    /**
     * @param  array<string, mixed>  $details  extra data placed under "details"
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, ?string $code = null, ?string $message = null, array $details = [], array $headers = []): JsonResponse
    {
        $body = [
            'status' => $status,
            'code' => $code ?? self::CODES[$status] ?? 'ERROR',
            'message' => $message ?? self::MESSAGES[$status] ?? 'Error.',
        ];

        if ($details !== []) {
            $body['details'] = $details;
        }

        return response()->json($body, $status, $headers);
    }

    /** Converts any exception raised while handling an API request to the unified format. */
    public static function fromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            $response = self::response(422, 'VALIDATION_FAILED', self::MESSAGES[422]);
            $response->setData($response->getData(true) + ['errors' => $e->errors()]);

            return $response;
        }

        if ($e instanceof AuthenticationException) {
            return self::response(401);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();

            $keepMessage = in_array($status, [400, 403, 409], true) && $e->getMessage() !== '';
            $details = $status === 429 && isset($headers['Retry-After'])
                ? ['retry_after_seconds' => (int) $headers['Retry-After']]
                : [];

            return self::response($status, null, $keepMessage ? $e->getMessage() : null, $details, $headers);
        }

        $details = config('app.debug')
            ? ['debug' => ['exception' => get_class($e), 'error' => $e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()]]
            : [];

        return self::response(500, 'INTERNAL_ERROR', null, $details);
    }
}
