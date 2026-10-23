<?php

namespace App\Http\Controllers\Api\V1\Announcements;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AnnouncementPresenter;
use App\Http\Responses\ApiError;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $employeeId = $user->employee?->getKey();

        $query = $this->announcements->visibleQuery($user)
            ->with(['reads' => fn ($query) => $query->where('employee_id', $employeeId)])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereDoesntHave('reads', fn ($query) => $query->where('employee_id', $employeeId));
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (Announcement $announcement) => AnnouncementPresenter::summary(
                    $announcement,
                    $announcement->reads->first(),
                ))
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        if (! $this->announcements->canView($announcement, $request->user())) {
            return ApiError::notFound()->toResponse($request->header('X-Request-Id'));
        }

        $announcement->load(['reads' => fn ($query) => $query->where('employee_id', $request->user()->employee?->getKey())]);

        return response()->json([
            'data' => AnnouncementPresenter::detail($announcement, $announcement->reads->first()),
        ]);
    }

    public function read(Request $request, Announcement $announcement): JsonResponse
    {
        if (! $this->announcements->canView($announcement, $request->user())) {
            return ApiError::notFound()->toResponse($request->header('X-Request-Id'));
        }

        $read = $this->announcements->markRead($announcement, $request->user());

        return response()->json([
            'data' => AnnouncementPresenter::detail($announcement, $read),
        ]);
    }
}
