<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly AuditRecorder $audit,
    ) {}

    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'amount' => ['required', 'numeric', 'between:-365,365', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $employee = User::query()->findOrFail($validated['user_id']);
        $type = LeaveType::query()->findOrFail($validated['leave_type_id']);

        $before = $this->balances->getOrCreateBalance($employee, (int) $type->getKey(), (int) $validated['year']);

        $balance = $this->balances->adjust(
            $request->user(),
            $employee,
            (int) $type->getKey(),
            (int) $validated['year'],
            (float) $validated['amount'],
            $validated['reason'],
        );

        $this->audit->record('leave.balance.adjusted', $balance, [
            'adjusted' => (float) $before->adjusted,
        ], [
            'adjusted' => (float) $balance->adjusted,
            'amount' => (float) $validated['amount'],
            'year' => (int) $validated['year'],
            'leave_type_id' => (int) $type->getKey(),
            'user_id' => (int) $employee->getKey(),
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'data' => [
                'user_id' => $employee->getKey(),
                'leave_type_id' => $type->getKey(),
                'year' => $balance->year,
                'entitled' => (float) $balance->entitled,
                'used' => (float) $balance->used,
                'reserved' => (float) $balance->reserved,
                'adjusted' => (float) $balance->adjusted,
                'available' => $balance->available(),
            ],
        ]);
    }
}
