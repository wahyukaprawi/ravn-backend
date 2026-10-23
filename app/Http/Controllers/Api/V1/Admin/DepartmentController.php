<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\Department;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Department::query();

        if ($search = $request->query('search')) {
            $needle = '%'.mb_strtolower(trim((string) $search)).'%';
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('LOWER(code) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$needle]);
            });
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $page = $query->orderBy('code')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Department $department) => $this->payload($department))->all(),
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
        $this->normalize($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('departments', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $department = $this->organization->createDepartment($validated, $request->user());

        return response()->json(['data' => $this->payload($department)], 201);
    }

    public function show(Department $department): JsonResponse
    {
        return response()->json(['data' => $this->payload($department)]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->normalize($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('departments', 'code')->whereNull('deleted_at')->ignore($department->getKey())],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $department = $this->organization->updateDepartment($department, $validated, $request->user());

        return response()->json(['data' => $this->payload($department)]);
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->organization->deleteDepartment($department, $request->user());

        return response()->json(['status' => 'ok']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        if (Department::withTrashed()->find($id) === null) {
            return ApiError::notFound('Department not found.')->toResponse($request->header('X-Request-Id'));
        }

        $department = $this->organization->restoreDepartment($id, $request->user());

        return response()->json(['data' => $this->payload($department)]);
    }

    protected function normalize(Request $request): void
    {
        if ($request->has('code')) {
            $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        }
    }

    protected function payload(Department $department): array
    {
        return [
            'id' => $department->getKey(),
            'code' => $department->code,
            'name' => $department->name,
            'description' => $department->description,
            'created_at' => $department->created_at?->toIso8601String(),
            'deleted_at' => $department->deleted_at?->toIso8601String(),
        ];
    }
}
