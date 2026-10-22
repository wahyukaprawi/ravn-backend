<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Http\Controllers\Controller;
use App\Http\Presenters\LeavePresenter;
use App\Http\Responses\ApiError;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $scope = (string) $request->query('scope', 'mine');

        $query = LeaveRequest::query()->with(['user', 'type', 'manager', 'workflow.steps']);

        if ($scope === 'mine') {
            $query->where('user_id', $actor->getKey());
        } elseif ($scope === 'team') {
            if (! $actor->hasPermissionTo('leave_requests.view_scope')) {
                return ApiError::forbidden('You are not allowed to view team leave requests.')
                    ->toResponse($request->header('X-Request-Id'));
            }

            $query->whereIn('user_id', User::query()->where('manager_id', $actor->getKey())->pluck('id'));
        } elseif ($scope === 'all') {
            if (! $actor->hasPermissionTo('leave_requests.view_any')) {
                return ApiError::forbidden('You are not allowed to view all leave requests.')
                    ->toResponse($request->header('X-Request-Id'));
            }
        } else {
            return ApiError::validation('Unknown scope.', ['scope' => ['Allowed values are mine, team and all.']])
                ->toResponse($request->header('X-Request-Id'));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($leaveTypeId = $request->query('leave_type_id')) {
            $query->where('leave_type_id', $leaveTypeId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('end_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('start_date', '<=', $to);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $page = $query->orderByDesc('start_date')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (LeaveRequest $leaveRequest) => LeavePresenter::request($leaveRequest))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')->where('active', true),
            ],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $leaveRequest = $this->leave->submit($request->user(), $validated);

        return response()->json([
            'data' => LeavePresenter::request($leaveRequest),
        ], 201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        Gate::authorize('view', $leaveRequest);

        return response()->json([
            'data' => LeavePresenter::request($leaveRequest),
        ]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        Gate::authorize('cancel', $leaveRequest);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $result = $this->leave->requestCancellation($leaveRequest, $request->user(), $validated['reason']);

        if ($result['mode'] === 'canceled') {
            return response()->json([
                'data' => LeavePresenter::request($result['request']),
                'cancellation' => null,
            ]);
        }

        return response()->json([
            'data' => LeavePresenter::request($leaveRequest->fresh(['user', 'type', 'manager', 'workflow.steps'])),
            'cancellation' => LeavePresenter::cancellation($result['cancellation']),
        ], 202);
    }

    public function assignApprover(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $validated = $request->validate([
            'manager_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', User::STATUS_ACTIVE),
            ],
        ]);

        $leaveRequest = $this->leave->assignApprover($leaveRequest, $request->user(), (int) $validated['manager_id']);

        return response()->json([
            'data' => LeavePresenter::request($leaveRequest),
        ]);
    }
}
