<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReportPresenter;
use App\Http\Responses\ApiError;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(): JsonResponse
    {
        $reports = $this->reports->catalogue();

        return response()->json([
            'data' => $reports
                ->map(fn ($report) => ReportPresenter::report($report, $this->reports->definition($report)))
                ->all(),
        ]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'scope' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $report = $this->reports->find($code);

        if ($report === null) {
            return ApiError::notFound('Report not found.')->toResponse($request->header('X-Request-Id'));
        }

        $result = $this->reports->run($request->user(), $report, $validated);

        $this->audit->record('report.viewed', $report, null, [
            'report_code' => $report->code,
            'scope' => $result['scope'],
            'from' => $result['filters']['from'],
            'to' => $result['filters']['to'],
            'row_count' => count($result['rows']),
        ], $request->user());

        return response()->json([
            'data' => [
                'report' => ReportPresenter::report($report, $result['definition']),
                'filters' => $result['filters'],
                'columns' => ReportPresenter::columns($result['definition']),
                'rows' => $result['rows'],
                'totals' => $result['totals'],
                'row_count' => count($result['rows']),
            ],
        ]);
    }
}
