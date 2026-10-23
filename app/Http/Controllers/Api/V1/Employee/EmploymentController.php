<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Presenters\EmployeePresenter;
use App\Models\Employee;
use App\Models\EmployeeEmployment;
use App\Services\EmploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmploymentController extends Controller
{
    public function __construct(
        private readonly EmploymentService $employments,
    ) {}

    public function index(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $items = $employee->employments()->orderBy('effective_from')->get();

        return response()->json([
            'data' => $items->map(fn (EmployeeEmployment $employment) => EmployeePresenter::employment($employment))->all(),
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        $validated = $request->validate([
            'employment_type' => ['required', Rule::in(EmployeeEmployment::TYPES)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);

        $employment = $this->employments->create($employee, $validated, $request->user());

        return response()->json(['data' => EmployeePresenter::employment($employment)], 201);
    }

    public function update(Request $request, EmployeeEmployment $employment): JsonResponse
    {
        Gate::authorize('view', $employment->employee);

        $validated = $request->validate([
            'employment_type' => ['sometimes', Rule::in(EmployeeEmployment::TYPES)],
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);

        $employment = $this->employments->update($employment, $validated, $request->user());

        return response()->json(['data' => EmployeePresenter::employment($employment)]);
    }

    public function terminate(Request $request, EmployeeEmployment $employment): JsonResponse
    {
        Gate::authorize('view', $employment->employee);

        $validated = $request->validate([
            'effective_to' => ['required', 'date_format:Y-m-d'],
        ]);

        $employment = $this->employments->terminate($employment, $validated['effective_to'], $request->user());

        return response()->json(['data' => EmployeePresenter::employment($employment)]);
    }
}
