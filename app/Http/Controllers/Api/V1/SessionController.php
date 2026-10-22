<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\PersonalAccessToken;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAccessToken()?->getKey();

        $sessions = $user->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->getKey(),
                'device_name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'current' => $token->getKey() === $currentId,
            ])
            ->values()
            ->all();

        return response()->json(['sessions' => $sessions]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $revoked = $this->sessions->revokeById($user, $id);

        if (! $revoked) {
            return ApiError::notFound('Session not found.')->toResponse($request->header('X-Request-Id'));
        }

        $this->audit->record('session.revoke', $user, null, ['token_id' => $id], $user);

        return response()->json(['status' => 'ok']);
    }
}
