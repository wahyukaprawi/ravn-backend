<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', 'alpha_dash', Rule::unique('leave_types', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'requires_approval' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $type = LeaveType::query()->create([
            'code' => mb_strtolower($validated['code']),
            'name' => $validated['name'],
            'requires_approval' => $validated['requires_approval'] ?? true,
            'active' => $validated['active'] ?? true,
        ]);

        $this->audit->record('leave.type.created', $type, null, $type->only(['code', 'name', 'requires_approval', 'active']));

        return response()->json(['data' => $this->payload($type)], 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $before = $leaveType->only(['name', 'requires_approval', 'active']);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'requires_approval' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $leaveType->fill($validated)->save();

        $this->audit->record('leave.type.updated', $leaveType, $before, $leaveType->only(['name', 'requires_approval', 'active']));

        return response()->json(['data' => $this->payload($leaveType)]);
    }

    protected function payload(LeaveType $type): array
    {
        return [
            'id' => $type->getKey(),
            'code' => $type->code,
            'name' => $type->name,
            'requires_approval' => $type->requires_approval,
            'active' => $type->active,
        ];
    }
}
