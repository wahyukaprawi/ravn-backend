<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiError
{
    public const CODE_VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const CODE_UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const CODE_FORBIDDEN = 'FORBIDDEN';

    public const CODE_NOT_FOUND = 'NOT_FOUND';

    public const CODE_EXPIRED = 'EXPIRED';

    public const CODE_CONFLICT = 'CONFLICT';

    public const CODE_IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';

    public const CODE_RATE_LIMITED = 'RATE_LIMITED';

    public const CODE_SERVER_ERROR = 'SERVER_ERROR';

    public const CODE_MFA_REQUIRED = 'MFA_REQUIRED';

    public const CODE_INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $details = [],
        public readonly int $status = 400,
    ) {}

    public function toResponse(?string $requestId = null): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->code,
                'message' => $this->message,
                'details' => (object) $this->details,
                'request_id' => $requestId,
            ],
        ], $this->status);
    }

    public static function unauthenticated(string $message = 'Authentication required.'): self
    {
        return new self(self::CODE_UNAUTHENTICATED, $message, [], 401);
    }

    public static function invalidCredentials(): self
    {
        return new self(self::CODE_INVALID_CREDENTIALS, 'These credentials do not match our records.', [], 401);
    }

    public static function forbidden(string $message = 'This action is unauthorized.'): self
    {
        return new self(self::CODE_FORBIDDEN, $message, [], 403);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(self::CODE_NOT_FOUND, $message, [], 404);
    }

    public static function expired(string $message = 'This resource has expired.'): self
    {
        return new self(self::CODE_EXPIRED, $message, [], 410);
    }

    public static function validation(string $message, array $details = []): self
    {
        return new self(self::CODE_VALIDATION_FAILED, $message, $details, 422);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self(self::CODE_CONFLICT, $message, $details, 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            self::CODE_IDEMPOTENCY_CONFLICT,
            'Idempotency-Key was used with a different request payload.',
            [],
            409,
        );
    }

    public static function rateLimited(string $message, ?int $retryAfter = null): self
    {
        return new self(
            self::CODE_RATE_LIMITED,
            $message,
            $retryAfter === null ? [] : ['retry_after' => $retryAfter],
            429,
        );
    }

    public static function mfaRequired(): self
    {
        return new self(self::CODE_MFA_REQUIRED, 'Multi-factor authentication is required.', [], 403);
    }

    public static function server(): self
    {
        return new self(self::CODE_SERVER_ERROR, 'Something went wrong.', [], 500);
    }
}
