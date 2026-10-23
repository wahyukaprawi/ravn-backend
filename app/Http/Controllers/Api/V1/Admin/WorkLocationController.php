<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\WorkLocation;
use App\Services\WorkLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkLocationController extends Controller
{
    public function __construct(
        private readonly WorkLocationService $locations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WorkLocation::query();

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
            'data' => collect($page->items())->map(fn (WorkLocation $location) => $this->payload($location))->all(),
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

        $validated = $request->validate($this->rules());
        $validated['radius_meters'] ??= 100;

        $location = $this->locations->create($validated, $request->user());

        return response()->json(['data' => $this->payload($location)], 201);
    }

    public function show(WorkLocation $workLocation): JsonResponse
    {
        return response()->json(['data' => $this->payload($workLocation)]);
    }

    public function update(Request $request, WorkLocation $workLocation): JsonResponse
    {
        $this->normalize($request);

        $validated = $request->validate($this->rules($workLocation));
        $location = $this->locations->update($workLocation, $validated, $request->user());

        return response()->json(['data' => $this->payload($location)]);
    }

    public function destroy(Request $request, WorkLocation $workLocation): JsonResponse
    {
        $this->locations->delete($workLocation, $request->user());

        return response()->json(['status' => 'ok']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        if (WorkLocation::withTrashed()->find($id) === null) {
            return ApiError::notFound('Work location not found.')->toResponse($request->header('X-Request-Id'));
        }

        $location = $this->locations->restore($id, $request->user());

        return response()->json(['data' => $this->payload($location)]);
    }

    protected function rules(?WorkLocation $location = null): array
    {
        $required = $location === null ? 'required' : 'sometimes';
        $ignore = $location?->getKey();

        $codeRule = Rule::unique('work_locations', 'code');

        if ($ignore !== null) {
            $codeRule->ignore($ignore);
        }

        return [
            'code' => [$required, 'string', 'max:50', $codeRule],
            'name' => [$required, 'string', 'max:150'],
            'address' => [$required, 'string', 'max:500'],
            'remote_address' => ['nullable', 'string', 'max:500'],
            'latitude' => [$required, 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'numeric', 'between:-180,180'],
            'radius_meters' => [
                'sometimes',
                'integer',
                'min:'.config('employees.geofence_radius_min_meters'),
                'max:'.config('employees.geofence_radius_max_meters'),
            ],
            'timezone' => [$required, 'timezone'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function normalize(Request $request): void
    {
        if ($request->has('code')) {
            $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        }
    }

    protected function payload(WorkLocation $location): array
    {
        return [
            'id' => $location->getKey(),
            'code' => $location->code,
            'name' => $location->name,
            'address' => $location->address,
            'remote_address' => $location->remote_address,
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'geofence_radius_meters' => $location->radius_meters,
            'timezone' => $location->timezone,
            'active' => $location->active,
            'created_at' => $location->created_at?->toIso8601String(),
            'deleted_at' => $location->deleted_at?->toIso8601String(),
        ];
    }
}
