<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\User;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class PasswordResetController extends Controller
{
    public const TTL_MINUTES = 60;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $user->isActive()) {
            $plain = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($plain), 'created_at' => now()],
            );

            $this->audit->record('auth.password.forgot', $user, null, ['email' => $email]);
        }

        return response()->json([
            'status' => 'ok',
            'message' => 'If the address matches an account, a password reset link has been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if ($record === null || ! Hash::check($validated['token'], $record->token)) {
            return ApiError::validation('This password reset link is invalid or has expired.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $ttl = self::TTL_MINUTES;

        if (now()->gt(Carbon::parse($record->created_at)->addMinutes($ttl))) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return ApiError::validation('This password reset link is invalid or has expired.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! $user->isActive()) {
            return ApiError::validation('This password reset link is invalid or has expired.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $token = DB::transaction(function () use ($user, $email, $validated) {
            $user->forceFill(['password' => $validated['password']])->save();

            DB::table('password_reset_tokens')->where('email', $email)->delete();

            $this->sessions->revokeAll($user);

            $this->audit->record('auth.password.reset', $user, null, null, $user);

            return $this->sessions->issueToken($user, $validated['device_name']);
        });

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ],
        ]);
    }
}
