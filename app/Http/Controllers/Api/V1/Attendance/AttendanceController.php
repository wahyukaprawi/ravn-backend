<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AttendancePresenter;
use App\Http\Responses\ApiError;
use App\Models\AttendanceSession;
use App\Services\AttendanceService;
use App\Services\ManagerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ManagerResolver $managers,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $status = $this->attendance->status($request->user());

        return response()->json([
            'data' => [
                'schedule' => $status['schedule'] === null ? null : AttendancePresenter::schedule($status['schedule']),
                'location' => AttendancePresenter::location($status['location'] ?? null),
                'work_date' => $status['work_date'] ?? null,
                'timezone' => $status['timezone'] ?? null,
                'scheduled_start_at' => $status['scheduled_start_at'] ?? null,
                'scheduled_end_at' => $status['scheduled_end_at'] ?? null,
                'is_workday' => $status['is_workday'] ?? null,
                'session' => AttendancePresenter::session($status['session'] ?? null),
                'can_check_in' => $status['can_check_in'],
                'can_check_out' => $status['can_check_out'],
                'reason' => $status['reason'] ?? null,
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $actor = $request->user();
        $scope = (string) $request->query('scope', 'mine');

        $query = AttendanceSession::query()->with([
            'user',
            'events',
            'schedule.location',
            'workLocation',
        ]);

        if ($scope === 'mine') {
            $query->where('user_id', $actor->getKey());
        } elseif ($scope === 'team') {
            if (! $actor->hasPermissionTo('attendance.view_scope')) {
                return ApiError::forbidden('You are not allowed to view team attendance.')
                    ->toResponse($request->header('X-Request-Id'));
            }

            $query->whereIn('user_id', $this->managers->directReportIds($actor));
        } elseif ($scope === 'all') {
            if (! $actor->hasPermissionTo('attendance.view_any')) {
                return ApiError::forbidden('You are not allowed to view all attendance.')
                    ->toResponse($request->header('X-Request-Id'));
            }
        } else {
            return ApiError::validation('Unknown scope.', ['scope' => ['Allowed values are mine, team and all.']])
                ->toResponse($request->header('X-Request-Id'));
        }

        if ($from = $request->query('from')) {
            $query->whereDate('work_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('work_date', '<=', $to);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($userId = $request->query('user_id')) {
            if ($scope !== 'all') {
                return ApiError::forbidden('Filtering by another user requires the all scope.')
                    ->toResponse($request->header('X-Request-Id'));
            }

            $query->where('user_id', $userId);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->orderByDesc('work_date')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AttendanceSession $session) => AttendancePresenter::session($session))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, AttendanceSession $session): JsonResponse
    {
        Gate::authorize('view', $session);

        $session->load(['user', 'events', 'schedule.location', 'workLocation']);

        return response()->json(['data' => AttendancePresenter::session($session)]);
    }

    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['nullable', 'string', 'max:100'],
        ]);

        $challenge = $this->attendance->issueChallenge($request->user(), $validated);

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->challenge_id,
                'expires_at' => $challenge->expires_at->toIso8601String(),
                'max_attempts' => $challenge->max_attempts,
            ],
        ], 201);
    }

    public function checkIn(Request $request): JsonResponse
    {
        if (! $request->hasHeader('Idempotency-Key')) {
            return ApiError::validation('Idempotency-Key is required.', [
                'Idempotency-Key' => ['Provide a unique key for this attendance mutation.'],
            ])->toResponse($request->header('X-Request-Id'));
        }

        $validated = $this->validatePunch($request);
        $result = $this->attendance->checkIn($request->user(), $validated, (string) $request->header('Idempotency-Key'));

        return response()->json([
            'data' => [
                'event' => AttendancePresenter::event($result['event']),
                'session' => AttendancePresenter::session($result['session']),
            ],
        ], $result['replayed'] ? 200 : 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        if (! $request->hasHeader('Idempotency-Key')) {
            return ApiError::validation('Idempotency-Key is required.', [
                'Idempotency-Key' => ['Provide a unique key for this attendance mutation.'],
            ])->toResponse($request->header('X-Request-Id'));
        }

        $validated = $this->validatePunch($request);
        $result = $this->attendance->checkOut($request->user(), $validated, (string) $request->header('Idempotency-Key'));

        return response()->json([
            'data' => [
                'event' => AttendancePresenter::event($result['event']),
                'session' => AttendancePresenter::session($result['session']),
            ],
        ], $result['replayed'] ? 200 : 201);
    }

    protected function validatePunch(Request $request): array
    {
        return $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['required', 'numeric', 'min:0', 'max:10000'],
            'mock_location_detected' => ['nullable', 'boolean'],
            'device_integrity' => ['nullable', 'array'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'face_challenge_id' => ['required', 'string', 'max:64'],
            'face_verified' => ['required', 'boolean'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
