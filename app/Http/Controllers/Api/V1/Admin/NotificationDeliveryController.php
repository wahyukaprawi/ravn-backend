<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NotificationPresenter;
use App\Http\Responses\ApiError;
use App\Models\NotificationDelivery;
use App\Notifications\NotificationDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,processing,sent,failed,dead_letter,skipped'],
            'channel' => ['nullable', 'string', 'in:in_app,email,push'],
        ]);

        $query = NotificationDelivery::query()
            ->with('notification')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['channel'])) {
            $query->where('channel', $validated['channel']);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (NotificationDelivery $delivery) => NotificationPresenter::delivery($delivery))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function retry(
        Request $request,
        NotificationDelivery $delivery,
        NotificationDeliveryService $deliveries,
        AuditRecorder $audit,
    ): JsonResponse {
        if (! $deliveries->retry($delivery)) {
            return ApiError::conflict('Only failed or dead-letter deliveries can be retried.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $audit->record('notification.delivery.retried', $delivery, null, [
            'notification_id' => $delivery->notification_id,
            'channel' => $delivery->channel,
        ], $request->user());

        return response()->json([
            'data' => NotificationPresenter::delivery($delivery->fresh('notification')),
        ]);
    }
}
