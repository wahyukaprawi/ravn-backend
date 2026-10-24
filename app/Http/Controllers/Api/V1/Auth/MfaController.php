<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Http\Responses\UserSessionPayload;
use App\Services\MfaService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function enroll(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($this->mfa->isEnrolled($user)) {
            return ApiError::conflict('Multi-factor authentication is already enrolled.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $enrollment = $this->mfa->enroll($user);

        $this->audit->record('mfa.enroll', $user, null, ['type' => 'totp'], $user);

        return response()->json([
            'secret' => $enrollment['secret'],
            'otpauth_url' => $enrollment['otpauth_url'],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        $result = $this->mfa->confirm($user, $validated['code']);

        if ($result === null) {
            $this->audit->record('mfa.confirm', $user, null, null, $user, 'failure');

            return ApiError::validation('The verification code is invalid.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $completion = $this->sessions->completeMfaEnrollment($user);

        $this->audit->record('mfa.confirm', $user, null, null, $user);

        $payload = [
            'status' => 'ok',
            'recovery_codes' => $result['recovery_codes'],
            'user' => UserSessionPayload::from($user),
        ];

        if ($completion['token'] !== null) {
            $payload['token'] = $completion['token'];
        }

        return response()->json($payload);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $this->mfa->verify($user, $validated['code'])) {
            $this->audit->record('mfa.verify', $user, null, null, $user, 'failure');

            return ApiError::validation('The verification code is invalid.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $this->sessions->markMfaVerified($user);

        $this->audit->record('mfa.verify', $user, null, null, $user);

        return response()->json(['status' => 'ok']);
    }

    public function stepUp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $this->mfa->isEnrolled($user)) {
            return ApiError::mfaRequired()->toResponse($request->header('X-Request-Id'));
        }

        if (! $this->mfa->verify($user, $validated['code'])) {
            $this->audit->record('mfa.step_up', $user, null, null, $user, 'failure');

            return ApiError::validation('The verification code is invalid.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $this->sessions->markMfaVerified($user);

        $this->audit->record('mfa.step_up', $user, null, null, $user);

        return response()->json([
            'status' => 'ok',
            'mfa_verified_at' => now()->toIso8601String(),
        ]);
    }
}
