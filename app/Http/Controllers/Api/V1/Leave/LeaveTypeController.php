<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        $types = LeaveType::query()
            ->where('active', true)
            ->with(['policies' => fn ($query) => $query->where('active', true)])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $types->map(fn (LeaveType $type) => [
                'id' => $type->getKey(),
                'code' => $type->code,
                'name' => $type->name,
                'requires_approval' => $type->requires_approval,
                'policies' => $type->policies
                    ->filter(fn (LeavePolicy $policy) => $policy->isEffectiveOn($today))
                    ->map(fn (LeavePolicy $policy) => [
                        'id' => $policy->getKey(),
                        'name' => $policy->name,
                        'annual_entitlement_days' => $policy->annual_entitlement_days,
                        'submission_deadline_days_before' => $policy->submission_deadline_days_before,
                        'effective_from' => $policy->effective_from?->toDateString(),
                        'effective_to' => $policy->effective_to?->toDateString(),
                    ])
                    ->values()
                    ->all(),
            ])->all(),
        ]);
    }
}
