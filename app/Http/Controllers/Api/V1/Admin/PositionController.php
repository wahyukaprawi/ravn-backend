<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\Position;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    public function __construct(
        private readonly OrganizationService $organization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Position::query();

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
            'data' => collect($page->items())->map(fn (Position $position) => $this->payload($position))->all(),
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
            'code' => ['required', 'string', 'max:50', Rule::unique('positions', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $position = $this->organization->createPosition($validated, $request->user());

        return response()->json(['data' => $this->payload($position)], 201);
    }

    public function show(Position $position): JsonResponse
    {
        return response()->json(['data' => $this->payload($position)]);
    }

    public function update(Request $request, Position $position): JsonResponse
    {
        $this->normalize($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('positions', 'code')->whereNull('deleted_at')->ignore($position->getKey())],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $position = $this->organization->updatePosition($position, $validated, $request->user());

        return response()->json(['data' => $this->payload($position)]);
    }

    public function destroy(Request $request, Position $position): JsonResponse
    {
        $this->organization->deletePosition($position, $request->user());

        return response()->json(['status' => 'ok']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        if (Position::withTrashed()->find($id) === null) {
            return ApiError::notFound('Position not found.')->toResponse($request->header('X-Request-Id'));
        }

        $position = $this->organization->restorePosition($id, $request->user());

        return response()->json(['data' => $this->payload($position)]);
    }

    protected function normalize(Request $request): void
    {
        if ($request->has('code')) {
            $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        }
    }

    protected function payload(Position $position): array
    {
        return [
            'id' => $position->getKey(),
            'code' => $position->code,
            'name' => $position->name,
            'description' => $position->description,
            'created_at' => $position->created_at?->toIso8601String(),
            'deleted_at' => $position->deleted_at?->toIso8601String(),
        ];
    }
}
