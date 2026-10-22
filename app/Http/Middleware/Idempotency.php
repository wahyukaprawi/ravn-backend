<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiError;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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

        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        $key = Str::limit($key, 64, '');
        $actorId = Auth::id();
        $hash = $this->hash($request);
        $requestId = $request->header('X-Request-Id');

        $existing = DB::transaction(function () use ($key, $hash, $requestId) {
            $record = IdempotencyKey::query()
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return null;
            }

            if ($record->expires_at !== null && $record->expires_at->isPast()) {
                $record->delete();

                return null;
            }

            if ($record->request_hash !== $hash) {
                return new class($requestId)
                {
                    public function __construct(private ?string $requestId) {}

                    public function toResponse()
                    {
                        return ApiError::conflict('Idempotency-Key was used with a different request payload.')
                            ->toResponse($this->requestId);
                    }
                };
            }

            return new class($record)
            {
                public function __construct(private IdempotencyKey $record) {}

                public function toResponse(): JsonResponse
                {
                    $response = response()->json(
                        $this->record->response_body,
                        $this->record->response_status,
                    );

                    return $response->header(self::HEADER, $this->record->key);
                }
            };
        });

        if (is_object($existing) && method_exists($existing, 'toResponse')) {
            $resp = $existing->toResponse();

            if ($resp instanceof JsonResponse && ! $resp->headers->has(self::HEADER)) {
                $resp->headers->set(self::HEADER, $key);
            }

            return $resp;
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            DB::transaction(function () use ($key, $actorId, $hash, $request, $response) {
                IdempotencyKey::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'actor_id' => $actorId,
                        'method' => $request->method(),
                        'path' => Str::limit($request->path(), 255, ''),
                        'request_hash' => $hash,
                        'response_status' => $response->getStatusCode(),
                        'response_body' => $response->getData(true),
                        'created_at' => now(),
                        'expires_at' => now()->addHours($this->ttlHours),
                    ],
                );
            });

            $response->headers->set(self::HEADER, $key);
        }

        return $response;
    }

    protected function hash(Request $request): string
    {
        $body = $request->getContent();

        return hash('sha256', $request->method().'|'.$request->path().'|'.($body === '' ? '' : $body));
    }
}
