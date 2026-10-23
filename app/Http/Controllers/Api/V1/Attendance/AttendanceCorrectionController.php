<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AttendancePresenter;
use App\Http\Responses\ApiError;
use App\Models\AttendanceCorrection;
use App\Services\AttendanceCorrectionService;
use App\Services\ManagerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceCorrectionController extends Controller
{
    public function __construct(
        private readonly AttendanceCorrectionService $corrections,
        private readonly ManagerResolver $managers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = AttendanceCorrection::query()->with([
            'requester',
            'approver',
            'executor',
            'session.user',
            'session.events',
            'session.schedule.location',
            'session.workLocation',
        ]);

        if (! $actor->hasPermissionTo('attendance.view_any')) {
            if ($actor->hasPermissionTo('attendance.view_scope')) {
                $query->where(function ($inner) use ($actor) {
                    $inner->where('requester_id', $actor->getKey())
                        ->orWhereHas('session', function ($session) use ($actor) {
                            $session->whereIn('user_id', $this->managers->directReportIds($actor));
                        });
                });
            } else {
                $query->where('requester_id', $actor->getKey());
            }
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AttendanceCorrection $correction) => AttendancePresenter::correction($correction))->all(),
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
        $validated = $request->validate([
            'attendance_session_id' => ['required', 'integer', 'exists:attendance_sessions,id'],
            'changes' => ['required', 'array'],
            'changes.check_in_at' => ['sometimes', 'date'],
            'changes.check_out_at' => ['sometimes', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $correction = $this->corrections->create(
            $request->user(),
            $validated,
            $request->file('evidence'),
        );

        return response()->json(['data' => AttendancePresenter::correction($correction)], 201);
    }

    public function show(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        Gate::authorize('view', $correction);

        $correction->load(['requester', 'approver', 'executor', 'session.user', 'session.events', 'session.schedule.location', 'session.workLocation']);

        return response()->json(['data' => AttendancePresenter::correction($correction)]);
    }

    public function submit(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $correction = $this->corrections->submit($correction, $request->user());

        return response()->json(['data' => AttendancePresenter::correction($correction)]);
    }

    public function approve(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $correction = $this->corrections->approve($correction, $request->user());

        return response()->json(['data' => AttendancePresenter::correction($correction)]);
    }

    public function reject(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $correction = $this->corrections->reject($correction, $request->user(), $validated['reason']);

        return response()->json(['data' => AttendancePresenter::correction($correction)]);
    }

    public function execute(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        if (! $request->hasHeader('Idempotency-Key')) {
            return ApiError::validation('Idempotency-Key is required.', [
                'Idempotency-Key' => ['Provide a unique key for this attendance mutation.'],
            ])->toResponse($request->header('X-Request-Id'));
        }

        $correction = $this->corrections->execute($correction, $request->user());

        return response()->json(['data' => AttendancePresenter::correction($correction)]);
    }

    public function cancel(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $correction = $this->corrections->cancel($correction, $request->user());

        return response()->json(['data' => AttendancePresenter::correction($correction)]);
    }

    public function evidence(Request $request, AttendanceCorrection $correction): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $correction);

        if ($correction->evidence_path === null || ! Storage::disk('local')->exists($correction->evidence_path)) {
            return ApiError::notFound('Evidence not found.')->toResponse($request->header('X-Request-Id'));
        }

        return Storage::disk('local')->download(
            $correction->evidence_path,
            'attendance-correction-'.$correction->getKey().'-evidence.'.pathinfo($correction->evidence_path, PATHINFO_EXTENSION),
        );
    }
}
