<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Presenters\EmployeePresenter;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    public function __construct(
        private readonly AssignmentService $assignments,
    ) {}

    public function index(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $items = $employee->assignments()
            ->with(['department', 'position', 'manager', 'workLocation', 'employment'])
            ->orderBy('effective_from')
            ->get();

        return response()->json([
            'data' => $items->map(fn (EmployeeAssignment $assignment) => EmployeePresenter::assignment($assignment))->all(),
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $validated = $request->validate([
            'employee_employment_id' => [
                'required',
                'integer',
                Rule::exists('employee_employments', 'id')->where('employee_id', $employee->getKey()),
            ],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);

        $assignment = $this->assignments->create($employee, $validated, $request->user());

        return response()->json(['data' => EmployeePresenter::assignment($assignment)], 201);
    }

    public function update(Request $request, EmployeeAssignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment->employee);

        $validated = $request->validate([
            'employee_employment_id' => ['sometimes', 'integer', Rule::exists('employee_employments', 'id')],
            'department_id' => ['sometimes', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'position_id' => ['sometimes', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')],
            'manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'work_location_id' => ['sometimes', 'integer', Rule::exists('work_locations', 'id')->whereNull('deleted_at')],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);

        $assignment = $this->assignments->update($assignment, $validated, $request->user());

        return response()->json(['data' => EmployeePresenter::assignment($assignment)]);
    }

    public function destroy(Request $request, EmployeeAssignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment->employee);

        $assignment = $this->assignments->cancel($assignment, $request->user());

        return response()->json(['data' => EmployeePresenter::assignment($assignment)]);
    }
}
