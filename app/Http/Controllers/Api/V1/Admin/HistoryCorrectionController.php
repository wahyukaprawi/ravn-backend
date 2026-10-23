<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiError;
use App\Models\HistoryCorrection;
use App\Services\HistoryCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryCorrectionController extends Controller
{
    public function __construct(
        private readonly HistoryCorrectionService $corrections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = HistoryCorrection::query()->with(['requester', 'approver', 'executor']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $page = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (HistoryCorrection $correction) => $this->payload($correction))->all(),
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
            'entity_type' => ['required', Rule::in(HistoryCorrection::ENTITIES)],
            'entity_id' => ['required', 'integer', 'min:1'],
            'changes' => ['required', 'array'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $correction = $this->corrections->create(
            $validated,
            $request->file('evidence'),
            $request->user(),
        );

        return response()->json(['data' => $this->payload($correction)], 201);
    }

    public function show(HistoryCorrection $correction): JsonResponse
    {
        return response()->json(['data' => $this->payload($correction->load(['requester', 'approver', 'executor']))]);
    }

    public function submit(Request $request, HistoryCorrection $correction): JsonResponse
    {
        $correction = $this->corrections->submit($correction, $request->user());

        return response()->json(['data' => $this->payload($correction)]);
    }

    public function approve(Request $request, HistoryCorrection $correction): JsonResponse
    {
        $correction = $this->corrections->approve($correction, $request->user());

        return response()->json(['data' => $this->payload($correction)]);
    }

    public function reject(Request $request, HistoryCorrection $correction): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $correction = $this->corrections->reject($correction, $request->user(), $validated['reason']);

        return response()->json(['data' => $this->payload($correction)]);
    }

    public function execute(Request $request, HistoryCorrection $correction): JsonResponse
    {
        $correction = $this->corrections->execute($correction, $request->user());

        return response()->json(['data' => $this->payload($correction)]);
    }

    public function cancel(Request $request, HistoryCorrection $correction): JsonResponse
    {
        $correction = $this->corrections->cancel($correction, $request->user());

        return response()->json(['data' => $this->payload($correction)]);
    }

    public function evidence(Request $request, HistoryCorrection $correction): StreamedResponse|JsonResponse
    {
        if ($correction->evidence_path === null || ! Storage::disk('local')->exists($correction->evidence_path)) {
            return ApiError::notFound('Evidence not found.')->toResponse($request->header('X-Request-Id'));
        }

        return Storage::disk('local')->download(
            $correction->evidence_path,
            'correction-'.$correction->getKey().'-evidence.'.pathinfo($correction->evidence_path, PATHINFO_EXTENSION),
        );
    }

    protected function payload(HistoryCorrection $correction): array
    {
        return [
            'id' => $correction->getKey(),
            'entity_type' => $correction->entity_type,
            'entity_id' => $correction->entity_id,
            'status' => $correction->status,
            'changes' => $correction->changes,
            'before' => $correction->before,
            'reason' => $correction->reason,
            'has_evidence' => $correction->evidence_path !== null,
            'requester' => $correction->requester === null ? null : [
                'id' => $correction->requester->getKey(),
                'name' => $correction->requester->name,
            ],
            'approver' => $correction->approver === null ? null : [
                'id' => $correction->approver->getKey(),
                'name' => $correction->approver->name,
            ],
            'executor' => $correction->executor === null ? null : [
                'id' => $correction->executor->getKey(),
                'name' => $correction->executor->name,
            ],
            'submitted_at' => $correction->submitted_at?->toIso8601String(),
            'decided_at' => $correction->decided_at?->toIso8601String(),
            'executed_at' => $correction->executed_at?->toIso8601String(),
            'rejected_at' => $correction->rejected_at?->toIso8601String(),
            'cancelled_at' => $correction->cancelled_at?->toIso8601String(),
            'created_at' => $correction->created_at?->toIso8601String(),
        ];
    }
}
