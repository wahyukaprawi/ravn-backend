<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
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
        $user = $request->user();

        $this->sessions->revokeCurrent($user);

        $this->audit->record('auth.logout', $user, null, null, $user);

        return response()->json(['status' => 'ok']);
    }
}
