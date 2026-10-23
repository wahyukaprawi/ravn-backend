<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Presenters\NotificationPresenter;
use App\Http\Responses\ApiError;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->appNotifications()->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (Notification $notification) => NotificationPresenter::notification($notification))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Notification $notification): JsonResponse
    {
        if (! $this->ownedBy($notification, $request->user())) {
            return ApiError::notFound()->toResponse($request->header('X-Request-Id'));
        }

        return response()->json(['data' => NotificationPresenter::notification($notification)]);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        if (! $this->ownedBy($notification, $request->user())) {
            return ApiError::notFound()->toResponse($request->header('X-Request-Id'));
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => NotificationPresenter::notification($notification)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = $request->user()->appNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    protected function ownedBy(Notification $notification, User $user): bool
    {
        return $notification->notifiable_type === $user->getMorphClass()
            && (int) $notification->notifiable_id === (int) $user->getKey();
    }
}
