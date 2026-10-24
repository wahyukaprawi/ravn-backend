<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequireFreshMfa;
use App\Http\Middleware\RequireFullSession;
use App\Http\Responses\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->alias([
            'fresh.mfa' => RequireFreshMfa::class,
            'session.full' => RequireFullSession::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'idempotency' => Idempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $requestId = AssignRequestId::current();

            $error = match (true) {
                $e instanceof ValidationException => ApiError::validation(
                    'The given data was invalid.',
                    $e->errors(),
                ),
                $e instanceof AuthenticationException => ApiError::unauthenticated(),
                $e instanceof AuthorizationException => ApiError::forbidden(),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiError::notFound(),
                $e instanceof TooManyRequestsHttpException => ApiError::rateLimited(
                    'Too many attempts. Please try again later.',
                    $e->getHeaders()['Retry-After'] ?? null,
                ),
                $e instanceof HttpException => match ($e->getStatusCode()) {
                    401 => ApiError::unauthenticated(),
                    403 => ApiError::forbidden(),
                    404 => ApiError::notFound(),
                    409 => ApiError::conflict($e->getMessage() ?: 'Conflict.'),
                    410 => ApiError::expired($e->getMessage() ?: 'This resource has expired.'),
                    422 => ApiError::validation($e->getMessage() ?: 'The given data was invalid.'),
                    429 => ApiError::rateLimited('Too many attempts. Please try again later.'),
                    default => new ApiError(
                        ApiError::CODE_SERVER_ERROR,
                        $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                        [],
                        $e->getStatusCode(),
                    ),
                },
                default => ApiError::server(),
            };

            if ($e instanceof TooManyRequestsHttpException) {
                return $error->toResponse($requestId)
                    ->withHeaders(['Retry-After' => $e->getHeaders()['Retry-After'] ?? 60]);
            }

            return $error->toResponse($requestId);
        });
    })->create();
