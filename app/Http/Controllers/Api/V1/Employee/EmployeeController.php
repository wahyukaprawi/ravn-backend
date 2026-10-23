<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Presenters\EmployeePresenter;
use App\Http\Responses\ApiError;
use App\Models\Employee;
use App\Services\EmployeeService;
use App\Services\ManagerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly ManagerResolver $managers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = Employee::query()->with([
            'currentAssignment.department',
            'currentAssignment.position',
            'currentAssignment.manager',
            'currentAssignment.workLocation',
        ]);

        if (! $actor->hasPermissionTo('employees.view_all')) {
            $reportIds = $this->managers->directReportIds($actor);

            $query->where(function ($inner) use ($actor, $reportIds) {
                $inner->where('user_id', $actor->getKey());

                if ($reportIds !== []) {
                    $inner->orWhereIn('user_id', $reportIds);
                }
            });
        }

        if ($search = $request->query('search')) {
            $needle = '%'.mb_strtolower(trim((string) $search)).'%';

            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('LOWER(employee_no) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(work_email) LIKE ?', [$needle]);
            });
        }

        $today = now()->toDateString();

        foreach (['department_id' => 'department_id', 'position_id' => 'position_id', 'work_location_id' => 'work_location_id'] as $filter => $column) {
            if ($value = $request->query($filter)) {
                $query->whereHas('assignments', function ($assignment) use ($column, $value, $today) {
                    $assignment->where($column, $value)
                        ->whereNull('canceled_at')
                        ->where('effective_from', '<=', $today)
                        ->where(function ($end) use ($today) {
                            $end->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                        });
                });
            }
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->orderBy('employee_no')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Employee $employee) => EmployeePresenter::summary($employee))->all(),
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
        $request->merge([
            'employee_no' => mb_strtoupper(trim((string) $request->input('employee_no'))),
            'work_email' => $request->filled('work_email') ? mb_strtolower(trim((string) $request->input('work_email'))) : null,
        ]);

        $validated = $request->validate([
            'employee_no' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_no')],
            'user_id' => ['nullable', 'integer', Rule::unique('employees', 'user_id')->whereNotNull('user_id'), Rule::exists('users', 'id')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'work_email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'work_email')->whereNull('deleted_at')],
            'hire_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $employee = $this->employees->create($validated, $request->user());

        return response()->json(['data' => EmployeePresenter::profile($this->load($employee))], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        return response()->json(['data' => EmployeePresenter::profile($this->load($employee))]);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('update', $employee);

        if ($request->has('employee_no')) {
            $request->merge(['employee_no' => mb_strtoupper(trim((string) $request->input('employee_no')))]);
        }

        if ($request->has('work_email')) {
            $request->merge([
                'work_email' => $request->filled('work_email')
                    ? mb_strtolower(trim((string) $request->input('work_email')))
                    : null,
            ]);
        }

        $validated = $request->validate([
            'employee_no' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('employees', 'employee_no')->ignore($employee->getKey())],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::unique('employees', 'user_id')->whereNotNull('user_id')->ignore($employee->getKey()), Rule::exists('users', 'id')],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('employees', 'work_email')->whereNull('deleted_at')->ignore($employee->getKey())],
            'hire_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);

        $employee = $this->employees->update($employee, $validated, $request->user());

        return response()->json(['data' => EmployeePresenter::profile($this->load($employee))]);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('delete', $employee);

        $this->employees->delete($employee, $request->user());

        return response()->json(['status' => 'ok']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $employee = Employee::withTrashed()->find($id);

        if ($employee === null) {
            return ApiError::notFound('Employee not found.')->toResponse($request->header('X-Request-Id'));
        }

        Gate::authorize('restore', $employee);

        $employee = $this->employees->restore($id, $request->user());

        return response()->json(['data' => EmployeePresenter::profile($this->load($employee))]);
    }

    public function history(Employee $employee): JsonResponse
    {
        Gate::authorize('viewHistory', $employee);

        $employee->load([
            'user',
            'currentEmployment',
            'currentAssignment.department',
            'currentAssignment.position',
            'currentAssignment.manager',
            'currentAssignment.workLocation',
            'employments',
            'assignments.department',
            'assignments.position',
            'assignments.manager',
            'assignments.workLocation',
        ]);

        return response()->json(['data' => EmployeePresenter::profile($employee, true)]);
    }

    protected function load(Employee $employee): Employee
    {
        return $employee->load([
            'user',
            'currentEmployment',
            'currentAssignment.department',
            'currentAssignment.position',
            'currentAssignment.manager',
            'currentAssignment.workLocation',
        ]);
    }
}
