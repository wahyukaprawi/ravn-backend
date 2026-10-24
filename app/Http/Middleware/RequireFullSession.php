<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiError;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFullSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && ! $token->can('*')) {
            return ApiError::mfaRequired()->toResponse($request->header('X-Request-Id'));
        }

        return $next($request);
    }
}
