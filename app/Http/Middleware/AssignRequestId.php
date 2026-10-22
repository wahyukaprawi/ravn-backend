<?php

namespace App\Http\Middleware;

use App\Audit\AuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header(self::HEADER);
        $requestId = is_string($incoming) && $incoming !== ''
            ? mb_substr($incoming, 0, 64)
            : (string) Str::uuid();

        $request->headers->set(self::HEADER, $requestId);

        app()->instance('request_id', $requestId);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    public static function current(): ?string
    {
        return app()->bound('request_id') ? app('request_id') : null;
    }

    public static function recorder(): AuditRecorder
    {
        return app(AuditRecorder::class);
    }
}
