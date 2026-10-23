<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Presenters\EmployeePresenter;
use App\Http\Responses\ApiError;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportRow;
use App\Services\EmployeeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportService $imports,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.config('employees.import_max_kilobytes'),
                'mimes:csv,txt',
            ],
        ]);

        $batch = $this->imports->validate($request->user(), $request->file('file'));

        if ($batch->status === EmployeeImportBatch::STATUS_INVALID) {
            return ApiError::validation('The CSV contains invalid rows. No employees were created.', [
                'batch_id' => $batch->getKey(),
                'total_rows' => $batch->total_rows,
                'invalid_rows' => $batch->invalid_rows,
                'rows' => $this->rowErrors($batch),
            ])->toResponse($request->header('X-Request-Id'));
        }

        return response()->json(['data' => $this->payload($batch)], 201);
    }

    public function show(EmployeeImportBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->payload($batch->load('rows'))]);
    }

    public function commit(Request $request, EmployeeImportBatch $batch): JsonResponse
    {
        [$batch, $employees] = $this->imports->commit($batch, $request->user());

        return response()->json([
            'data' => $this->payload($batch),
            'employees' => collect($employees)->map(fn (Employee $employee) => EmployeePresenter::summary($employee))->all(),
        ]);
    }

    protected function payload(EmployeeImportBatch $batch): array
    {
        $batch->loadMissing('rows');

        return [
            'id' => $batch->getKey(),
            'original_filename' => $batch->original_filename,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'invalid_rows' => $batch->invalid_rows,
            'committed_at' => $batch->committed_at?->toIso8601String(),
            'rows' => $batch->rows->sortBy('row_number')->map(fn (EmployeeImportRow $row) => [
                'row_number' => $row->row_number,
                'employee_no' => $row->employee_no,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'phone' => $row->phone,
                'work_email' => $row->work_email,
                'hire_date' => $row->hire_date?->toDateString(),
                'status' => $row->status,
                'errors' => $row->errors,
                'employee_id' => $row->employee_id,
            ])->values()->all(),
        ];
    }

    protected function rowErrors(EmployeeImportBatch $batch): array
    {
        return $batch->rows()
            ->where('status', EmployeeImportRow::STATUS_INVALID)
            ->orderBy('row_number')
            ->get()
            ->map(fn (EmployeeImportRow $row) => [
                'row_number' => $row->row_number,
                'errors' => $row->errors,
            ])
            ->all();
    }
}
