<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['nullable', 'string', 'max:191'],
        ]);

        $user = $request->user();

        $this->sessions->revokeCurrent($user);

        if (! empty($validated['device_id'])) {
            DeviceToken::query()
                ->where('user_id', $user->getKey())
                ->where('device_id', $validated['device_id'])
                ->update(['active' => false]);
        }

        $this->audit->record('auth.logout', $user, null, [
            'device_id' => $validated['device_id'] ?? null,
        ], $user);

        return response()->json(['status' => 'ok']);
    }
}
