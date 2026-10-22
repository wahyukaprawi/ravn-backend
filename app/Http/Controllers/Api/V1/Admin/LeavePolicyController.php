<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeavePolicyController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')],
            'name' => ['required', 'string', 'max:150'],
            'annual_entitlement_days' => ['required', 'integer', 'min:0', 'max:365'],
            'submission_deadline_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $policy = LeavePolicy::query()->create([
            'leave_type_id' => $validated['leave_type_id'],
            'name' => $validated['name'],
            'annual_entitlement_days' => $validated['annual_entitlement_days'],
            'submission_deadline_days_before' => $validated['submission_deadline_days_before'] ?? null,
            'effective_from' => $validated['effective_from'] ?? null,
            'effective_to' => $validated['effective_to'] ?? null,
            'active' => $validated['active'] ?? true,
        ]);

        $this->audit->record('leave.policy.created', $policy, null, $policy->only([
            'leave_type_id',
            'name',
            'annual_entitlement_days',
            'submission_deadline_days_before',
            'effective_from',
            'effective_to',
            'active',
        ]));

        return response()->json(['data' => $this->payload($policy)], 201);
    }

    public function update(Request $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $before = $leavePolicy->only([
            'name',
            'annual_entitlement_days',
            'submission_deadline_days_before',
            'effective_from',
            'effective_to',
            'active',
        ]);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'annual_entitlement_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'submission_deadline_days_before' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $leavePolicy->fill($validated)->save();

        $this->audit->record('leave.policy.updated', $leavePolicy, $before, $leavePolicy->only([
            'name',
            'annual_entitlement_days',
            'submission_deadline_days_before',
            'effective_from',
            'effective_to',
            'active',
        ]));

        return response()->json(['data' => $this->payload($leavePolicy)]);
    }

    protected function payload(LeavePolicy $policy): array
    {
        return [
            'id' => $policy->getKey(),
            'leave_type_id' => $policy->leave_type_id,
            'name' => $policy->name,
            'annual_entitlement_days' => $policy->annual_entitlement_days,
            'submission_deadline_days_before' => $policy->submission_deadline_days_before,
            'effective_from' => $policy->effective_from?->toDateString(),
            'effective_to' => $policy->effective_to?->toDateString(),
            'active' => $policy->active,
        ];
    }
}
