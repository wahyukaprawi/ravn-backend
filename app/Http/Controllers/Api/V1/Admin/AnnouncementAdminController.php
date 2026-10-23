<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AnnouncementPresenter;
use App\Http\Responses\ApiError;
use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementAdminController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(Request $request): JsonResponse
    {
        $query = Announcement::query()
            ->with(['audiences', 'author'])
            ->withCount(['reads as read_count'])
            ->orderByDesc('id');

        $status = $request->query('status');

        if (is_string($status) && in_array($status, [
            Announcement::STATUS_DRAFT,
            Announcement::STATUS_SCHEDULED,
            Announcement::STATUS_PUBLISHED,
            Announcement::STATUS_ARCHIVED,
        ], true)) {
            $query->where('status', $status);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (Announcement $announcement) => AnnouncementPresenter::admin($announcement))
                ->all(),
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
        $announcement = $this->announcements->create(
            $request->user(),
            $this->validatePayload($request),
        );

        return response()->json([
            'data' => AnnouncementPresenter::admin($this->present($announcement)),
        ], 201);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        return response()->json([
            'data' => AnnouncementPresenter::admin($this->present($announcement)),
        ]);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        if (! in_array($announcement->status, [Announcement::STATUS_DRAFT, Announcement::STATUS_SCHEDULED], true)) {
            return ApiError::conflict('Only draft or scheduled announcements can be edited.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $updated = $this->announcements->update(
            $announcement,
            $request->user(),
            $this->validatePayload($request),
        );

        return response()->json([
            'data' => AnnouncementPresenter::admin($this->present($updated)),
        ]);
    }

    public function publish(Request $request, Announcement $announcement): JsonResponse
    {
        if ($announcement->status === Announcement::STATUS_ARCHIVED) {
            return ApiError::conflict('Archived announcements cannot be published.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $published = $this->announcements->publish($announcement, $request->user());

        return response()->json([
            'data' => AnnouncementPresenter::admin($this->present($published)),
        ]);
    }

    public function archive(Request $request, Announcement $announcement): JsonResponse
    {
        if (! in_array($announcement->status, [Announcement::STATUS_PUBLISHED, Announcement::STATUS_ARCHIVED], true)) {
            return ApiError::conflict('Only published announcements can be archived.')
                ->toResponse($request->header('X-Request-Id'));
        }

        $archived = $this->announcements->archive($announcement, $request->user());

        return response()->json([
            'data' => AnnouncementPresenter::admin($this->present($archived)),
        ]);
    }

    public function analytics(Request $request, Announcement $announcement): JsonResponse
    {
        return response()->json([
            'data' => array_merge(
                ['announcement_id' => $announcement->getKey()],
                AnnouncementPresenter::analytics($this->announcements->analytics($announcement)),
            ),
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'audiences' => ['required', 'array', 'min:1', 'max:20'],
            'audiences.*.type' => ['required', 'string', Rule::in(AnnouncementAudience::TYPES)],
            'audiences.*.id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function present(Announcement $announcement): Announcement
    {
        return $announcement->load(['audiences', 'author'])->loadCount(['reads as read_count']);
    }
}
