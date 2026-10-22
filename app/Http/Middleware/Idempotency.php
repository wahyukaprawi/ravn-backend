<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiError;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(
        private readonly int $ttlHours = 24,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $rawKey = $request->header(self::HEADER);

        if (! is_string($rawKey) || $rawKey === '') {
            return $next($request);
        }

        $rawKey = Str::limit($rawKey, 64, '');
        $actorId = Auth::id();
        $key = hash('sha256', ($actorId ?? 'guest').'|'.$rawKey);
        $hash = $this->hash($request);
        $requestId = $request->header('X-Request-Id');

        try {
            [$record, $created] = DB::transaction(function () use ($key, $actorId, $hash, $request) {
                $record = IdempotencyKey::query()
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($record !== null && $record->expires_at !== null && $record->expires_at->isPast()) {
                    $record->delete();

                    $record = null;
                }

                if ($record !== null) {
                    return [$record, false];
                }

                $record = IdempotencyKey::query()->create([
                    'key' => $key,
                    'actor_id' => $actorId,
                    'method' => $request->method(),
                    'path' => Str::limit($request->path(), 255, ''),
                    'request_hash' => $hash,
                    'response_status' => 0,
                    'response_body' => [],
                    'created_at' => now(),
                    'expires_at' => now()->addHours($this->ttlHours),
                ]);

                return [$record, true];
            });
        } catch (UniqueConstraintViolationException) {
            return ApiError::conflict('An identical request is still being processed.')->toResponse($requestId);
        }

        if (! $created) {
            if ($record->request_hash !== $hash) {
                return ApiError::idempotencyConflict()->toResponse($requestId);
            }

            if ($record->response_status === 0) {
                return ApiError::conflict('An identical request is still being processed.')->toResponse($requestId);
            }

            $response = response()->json($record->response_body, $record->response_status);
            $response->headers->set(self::HEADER, $rawKey);

            return $response;
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            IdempotencyKey::query()->whereKey($record->getKey())->delete();

            throw $exception;
        }

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            IdempotencyKey::query()->whereKey($record->getKey())->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
                'expires_at' => now()->addHours($this->ttlHours),
            ]);

            $response->headers->set(self::HEADER, $rawKey);
        } else {
            IdempotencyKey::query()->whereKey($record->getKey())->delete();
        }

        return $response;
    }

    protected function hash(Request $request): string
    {
        $body = $request->getContent();

        return hash('sha256', $request->method().'|'.$request->path().'|'.($body === '' ? '' : $body));
    }
}
