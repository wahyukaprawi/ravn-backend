<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Http\Responses\UserSessionPayload;
use App\Services\AuthenticationService;
use App\Services\MfaService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly MfaService $mfa,
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = $this->auth->attempt($validated['email'], $validated['password']);

        if ($user === null) {
            $this->audit->record(
                'auth.login',
                null,
                null,
                ['email' => $this->auth->normalizeEmail($validated['email'])],
                null,
                'failure',
            );

            return ApiError::invalidCredentials()->toResponse($request->header('X-Request-Id'));
        }

        if ($this->auth->mfaEnrollmentRequired($user)) {
            $enrollment = $this->sessions->issueMfaEnrollmentToken($user, $validated['device_name']);

            $this->audit->record(
                'auth.login.mfa_enrollment_required',
                $user,
                null,
                ['device_name' => $validated['device_name']],
                $user,
            );

            return response()->json([
                'mfa_enrollment_required' => true,
                'token' => $enrollment['token'],
                'expires_at' => $enrollment['expires_at'],
                'user' => UserSessionPayload::from($user),
            ]);
        }

        $requiresMfa = $this->auth->mfaRequired($user);

        if ($requiresMfa) {
            $code = $validated['code'] ?? null;

            if ($code === null || ! $this->mfa->verify($user, $code)) {
                $this->audit->record('auth.login.mfa', $user, null, ['outcome' => 'rejected'], $user, 'failure');

                return ApiError::invalidCredentials()->toResponse($request->header('X-Request-Id'));
            }
        }

        $token = $this->sessions->issueToken($user, $validated['device_name'], $requiresMfa || ! $this->mfa->requiresMfa($user));

        $this->auth->recordLogin($user);

        $this->audit->record('auth.login', $user, null, ['device_name' => $validated['device_name']], $user);

        return response()->json([
            'token' => $token,
            'user' => UserSessionPayload::from($user),
        ]);
    }
}
