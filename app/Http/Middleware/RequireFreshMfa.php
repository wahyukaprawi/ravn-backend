<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiError;
use App\Services\SessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFreshMfa
{
    public function __construct(
        private readonly SessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiError::unauthenticated()->toResponse($request->header('X-Request-Id'));
        }

        if (! $this->sessions->hasFreshMfa($user)) {
            return ApiError::mfaRequired()->toResponse($request->header('X-Request-Id'));
        }

        return $next($request);
    }
}
