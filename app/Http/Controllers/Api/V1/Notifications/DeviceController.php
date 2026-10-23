<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NotificationPresenter;
use App\Http\Responses\ApiError;
use App\Models\DeviceToken;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function store(Request $request, PushNotificationService $push, AuditRecorder $audit): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'device_id' => ['nullable', 'string', 'max:191'],
        ]);

        $device = $push->registerToken(
            $request->user(),
            $validated['token'],
            $validated['platform'],
            $validated['device_id'] ?? null,
        );

        $audit->record('device.registered', $device, null, [
            'platform' => $device->platform,
            'device_id' => $device->device_id,
        ], $request->user());

        return response()->json(['data' => NotificationPresenter::device($device)], 201);
    }

    public function destroy(Request $request, DeviceToken $device, PushNotificationService $push, AuditRecorder $audit): JsonResponse
    {
        if ((int) $device->user_id !== (int) $request->user()->getKey()) {
            return ApiError::notFound()->toResponse($request->header('X-Request-Id'));
        }

        $push->revoke($device);

        $audit->record('device.revoked', $device, null, [
            'platform' => $device->platform,
            'device_id' => $device->device_id,
        ], $request->user());

        return response()->json(['status' => 'ok']);
    }
}
