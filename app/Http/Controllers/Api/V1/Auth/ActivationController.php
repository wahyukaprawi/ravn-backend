<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\User;
use App\Services\ActivationTokenService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class ActivationController extends Controller
{
    public function __construct(
        private readonly ActivationTokenService $tokens,
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $activation = $this->tokens->findUsable($validated['token']);

        if ($activation === null) {
            return ApiError::validation('This activation link is invalid or has expired.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $user = $activation->user;

        if ($user === null || $user->status !== User::STATUS_PENDING_ACTIVATION) {
            return ApiError::validation('This activation link is invalid or has expired.')
                ->toResponse($request->header('X-Request-Id'));
        }

        if ($user->invitation_expires_at !== null && $user->invitation_expires_at->isPast()) {
            return ApiError::validation('This invitation has expired. Ask an administrator to reissue it.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $token = DB::transaction(function () use ($user, $activation, $validated) {
            $user->forceFill([
                'password' => $validated['password'],
                'status' => User::STATUS_ACTIVE,
                'activated_at' => now(),
            ])->save();

            $this->tokens->consume($activation);

            $this->audit->record(
                'auth.activate',
                $user,
                ['status' => User::STATUS_PENDING_ACTIVATION],
                ['status' => User::STATUS_ACTIVE],
                $user,
            );

            return $this->sessions->issueToken($user, $validated['device_name']);
        });

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->fresh()->status,
            ],
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        return response()->json([
            'status' => 'ok',
            'message' => 'If the address matches an account awaiting activation, a new invitation has been sent.',
        ]);
    }
}
