<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ReportPresenter;
use App\Http\Responses\ApiError;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Services\ReportExportService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportExportService $exports,
    ) {}

    public function store(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'scope' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'format' => ['nullable', 'string'],
        ]);

        $report = $this->reports->find($code);

        if ($report === null) {
            return ApiError::notFound('Report not found.')->toResponse($request->header('X-Request-Id'));
        }

        $export = $this->exports->request(
            $request->user(),
            $report,
            $validated,
            (string) ($validated['format'] ?? 'csv'),
        );

        GenerateReportExport::dispatch($export->getKey())->afterCommit();

        return response()->json(['data' => ReportPresenter::export($export->fresh())], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $scope = (string) $request->query('scope', 'mine');

        $query = ReportExport::query()->orderByDesc('id');

        if ($scope === 'mine') {
            $query->where('user_id', $actor->getKey());
        } elseif ($scope === 'all') {
            if (! $actor->hasPermissionTo('reports.manage_exports')) {
                return ApiError::forbidden('You are not allowed to view all report exports.')
                    ->toResponse($request->header('X-Request-Id'));
            }
        } else {
            return ApiError::validation('Unknown scope.', ['scope' => ['Allowed values are mine and all.']])
                ->toResponse($request->header('X-Request-Id'));
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (ReportExport $export) => ReportPresenter::export($export))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, ReportExport $export): JsonResponse
    {
        if (! $this->exports->canView($export, $request->user())) {
            return ApiError::forbidden('You are not allowed to view this export.')
                ->toResponse($request->header('X-Request-Id'));
        }

        return response()->json(['data' => ReportPresenter::export($export)]);
    }

    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        $this->exports->authorizeDownload($export, $request->user());

        $filename = 'ravn-'.$export->report_code.'-'.$export->getKey().'.'.$export->format;
        $mime = $export->format === 'csv' ? 'text/csv' : 'application/octet-stream';

        $response = Storage::disk($export->disk)->download($export->path, $filename, [
            'Content-Type' => $mime,
        ]);

        $this->exports->markDownloaded($export, $request->user());

        return $response;
    }
}
