<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Http\Controllers\Controller;
use App\Http\Presenters\LeavePresenter;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveApprovalController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $items = LeaveRequest::query()
            ->with(['user', 'type', 'manager', 'workflow.steps'])
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->whereNotNull('workflow_instance_id')
            ->orderBy('id')
            ->get()
            ->filter(function (LeaveRequest $leaveRequest) use ($actor) {
                if ((int) $leaveRequest->user_id === (int) $actor->getKey()) {
                    return false;
                }

                if ($leaveRequest->workflow === null) {
                    return false;
                }

                $step = $this->workflow->currentStep($leaveRequest->workflow);

                return $step !== null && $this->workflow->canAct($step, $actor);
            })
            ->values();

        return response()->json([
            'data' => $items->map(fn (LeaveRequest $leaveRequest) => LeavePresenter::request($leaveRequest))->all(),
            'meta' => ['total' => $items->count()],
        ]);
    }

    public function decide(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->leave->decide($leaveRequest, $validated['action'], $request->user(), [
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'data' => LeavePresenter::request($result),
        ]);
    }

    public function cancellations(Request $request): JsonResponse
    {
        $actor = $request->user();

        $items = LeaveCancellationRequest::query()
            ->with(['requester', 'manager', 'workflow.steps'])
            ->where('status', LeaveCancellationRequest::STATUS_PENDING)
            ->orderBy('id')
            ->get()
            ->filter(function (LeaveCancellationRequest $cancellation) use ($actor) {
                if ((int) $cancellation->requester_id === (int) $actor->getKey()) {
                    return false;
                }

                if ($cancellation->workflow_instance_id === null) {
                    return $actor->hasPermissionTo('leave_requests.view_any')
                        && $actor->hasPermissionTo('workflow.reassign');
                }

                $step = $this->workflow->currentStep($cancellation->workflow);

                return $step !== null && $this->workflow->canAct($step, $actor);
            })
            ->values();

        return response()->json([
            'data' => $items->map(fn (LeaveCancellationRequest $cancellation) => LeavePresenter::cancellation($cancellation))->all(),
            'meta' => ['total' => $items->count()],
        ]);
    }

    public function decideCancellation(Request $request, LeaveCancellationRequest $cancellation): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->leave->decideCancellation($cancellation, $validated['action'], $request->user(), [
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'data' => LeavePresenter::cancellation($result),
        ]);
    }
}
