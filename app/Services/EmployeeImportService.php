<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportRow;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeImportService
{
    public const REQUIRED_HEADERS = [
        'employee_no',
        'first_name',
        'last_name',
        'phone',
        'work_email',
        'hire_date',
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function validate(User $actor, UploadedFile $file): EmployeeImportBatch
    {
        [$rows, $fileContents] = $this->parse($file);

        $storedPath = 'employee-imports/'.str()->uuid().'.csv';
        Storage::disk('local')->put($storedPath, $fileContents);

        return DB::transaction(function () use ($actor, $file, $rows, $storedPath) {
            $batch = EmployeeImportBatch::query()->create([
                'uploaded_by' => $actor->getKey(),
                'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'stored_path' => $storedPath,
                'status' => EmployeeImportBatch::STATUS_VALIDATED,
                'total_rows' => count($rows),
                'valid_rows' => 0,
                'invalid_rows' => 0,
            ]);

            $seenNumbers = [];
            $seenEmails = [];

            foreach ($rows as $index => $row) {
                $errors = [];

                if ($row['employee_no'] === '') {
                    $errors['employee_no'][] = 'The employee number is required.';
                } elseif (mb_strlen($row['employee_no']) > 50) {
                    $errors['employee_no'][] = 'The employee number may not be longer than 50 characters.';
                } elseif (isset($seenNumbers[$row['employee_no']])) {
                    $errors['employee_no'][] = 'The employee number is duplicated in this file.';
                } elseif (Employee::withTrashed()->where('employee_no', $row['employee_no'])->exists()) {
                    $errors['employee_no'][] = 'The employee number already exists.';
                }

                if ($row['employee_no'] !== '') {
                    $seenNumbers[$row['employee_no']] = true;
                }

                if ($row['first_name'] === '') {
                    $errors['first_name'][] = 'The first name is required.';
                } elseif (mb_strlen($row['first_name']) > 100) {
                    $errors['first_name'][] = 'The first name may not be longer than 100 characters.';
                }

                if ($row['last_name'] !== '' && mb_strlen($row['last_name']) > 100) {
                    $errors['last_name'][] = 'The last name may not be longer than 100 characters.';
                }

                if ($row['phone'] !== '' && mb_strlen($row['phone']) > 30) {
                    $errors['phone'][] = 'The phone number may not be longer than 30 characters.';
                }

                if ($row['work_email'] !== '') {
                    if (! filter_var($row['work_email'], FILTER_VALIDATE_EMAIL) || mb_strlen($row['work_email']) > 255) {
                        $errors['work_email'][] = 'The work email must be a valid email address of at most 255 characters.';
                    } elseif (Employee::query()->where('work_email', $row['work_email'])->exists()) {
                        $errors['work_email'][] = 'The work email already exists.';
                    } elseif (isset($seenEmails[$row['work_email']])) {
                        $errors['work_email'][] = 'The work email is duplicated in this file.';
                    }

                    $seenEmails[$row['work_email']] = true;
                }

                if ($row['hire_date'] === null) {
                    $errors['hire_date'][] = 'The hire date is required and must be a valid YYYY-MM-DD date.';
                }

                EmployeeImportRow::query()->create([
                    'employee_import_batch_id' => $batch->getKey(),
                    'row_number' => $row['row_number'],
                    'employee_no' => $row['employee_no'] !== '' ? $row['employee_no'] : null,
                    'first_name' => $row['first_name'] !== '' ? $row['first_name'] : null,
                    'last_name' => $row['last_name'] !== '' ? $row['last_name'] : null,
                    'phone' => $row['phone'] !== '' ? $row['phone'] : null,
                    'work_email' => $row['work_email'] !== '' ? $row['work_email'] : null,
                    'hire_date' => $row['hire_date'],
                    'status' => $errors === [] ? EmployeeImportRow::STATUS_VALID : EmployeeImportRow::STATUS_INVALID,
                    'errors' => $errors === [] ? null : $errors,
                ]);
            }

            $invalid = $batch->rows()->where('status', EmployeeImportRow::STATUS_INVALID)->count();

            $batch->forceFill([
                'status' => $invalid > 0 ? EmployeeImportBatch::STATUS_INVALID : EmployeeImportBatch::STATUS_VALIDATED,
                'valid_rows' => $batch->total_rows - $invalid,
                'invalid_rows' => $invalid,
            ])->save();

            $this->audit->record('employee_import.validated', $batch, null, [
                'status' => $batch->status,
                'total_rows' => $batch->total_rows,
                'invalid_rows' => $invalid,
            ], $actor);

            return $batch->fresh('rows');
        });
    }

    public function commit(EmployeeImportBatch $batch, User $actor): array
    {
        try {
            return DB::transaction(function () use ($batch, $actor) {
                $batch = EmployeeImportBatch::query()->lockForUpdate()->findOrFail($batch->getKey());

                if ($batch->isCommitted()) {
                    $employees = Employee::query()
                        ->whereIn('id', $batch->rows()->whereNotNull('employee_id')->pluck('employee_id'))
                        ->orderBy('id')
                        ->get();

                    return [$batch->fresh('rows'), $employees];
                }

                if ($batch->status !== EmployeeImportBatch::STATUS_VALIDATED) {
                    throw ValidationException::withMessages([
                        'batch' => 'Only a validated batch can be committed.',
                    ]);
                }

                $rows = $batch->rows()->orderBy('row_number')->get();

                $this->revalidate($rows);

                $employees = [];

                foreach ($rows as $row) {
                    $employee = Employee::query()->create([
                        'employee_no' => $row->employee_no,
                        'first_name' => $row->first_name,
                        'last_name' => $row->last_name,
                        'phone' => $row->phone,
                        'work_email' => $row->work_email,
                        'hire_date' => $row->hire_date->toDateString(),
                    ]);

                    $row->forceFill([
                        'status' => EmployeeImportRow::STATUS_CREATED,
                        'employee_id' => $employee->getKey(),
                    ])->save();

                    $this->audit->record('employee.created', $employee, null, [
                        'employee_no' => $employee->employee_no,
                        'hire_date' => $employee->hire_date?->toDateString(),
                        'import_batch_id' => $batch->getKey(),
                    ], $actor);

                    $employees[] = $employee;
                }

                $batch->forceFill([
                    'status' => EmployeeImportBatch::STATUS_COMMITTED,
                    'committed_at' => now(),
                ])->save();

                $this->audit->record('employee_import.committed', $batch, null, [
                    'employee_ids' => collect($employees)->map->getKey()->all(),
                    'total_rows' => $batch->total_rows,
                ], $actor);

                return [$batch->fresh('rows'), collect($employees)];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'batch' => 'A conflicting employee number or work email was created after validation. The batch was not committed.',
            ]);
        }
    }

    protected function revalidate($rows): void
    {
        $numbers = $rows->pluck('employee_no')->filter()->all();
        $emails = $rows->pluck('work_email')->filter()->all();

        if (count($numbers) !== count(array_unique($numbers)) || count($emails) !== count(array_unique($emails))) {
            throw ValidationException::withMessages([
                'batch' => 'The batch contains duplicate employee numbers or work emails.',
            ]);
        }

        if (Employee::withTrashed()->whereIn('employee_no', $numbers)->exists()) {
            throw ValidationException::withMessages([
                'batch' => 'An employee number in this batch is already in use. The batch was not committed.',
            ]);
        }

        if (Employee::query()->whereIn('work_email', $emails)->exists()) {
            throw ValidationException::withMessages([
                'batch' => 'A work email in this batch is already in use. The batch was not committed.',
            ]);
        }
    }

    protected function parse(UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The CSV file cannot be read.']);
        }

        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '\\');

        if ($header === false) {
            fclose($handle);

            throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
        }

        $header = array_map(function ($value): string {
            return mb_strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)));
        }, $header);

        $missing = array_diff(self::REQUIRED_HEADERS, $header);

        if ($missing !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'Missing required CSV headers: '.implode(', ', $missing).'.',
            ]);
        }

        $allowed = array_flip($header);
        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;

            if ($values === [null] || collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $values = array_pad(array_slice($values, 0, count($header)), count($header), null);
            $mapped = [];

            foreach (self::REQUIRED_HEADERS as $field) {
                $mapped[$field] = trim((string) $values[$allowed[$field]]);
            }

            if (count($rows) >= (int) config('employees.import_max_rows')) {
                fclose($handle);

                throw ValidationException::withMessages([
                    'file' => 'The CSV file exceeds the configured maximum of '.config('employees.import_max_rows').' rows.',
                ]);
            }

            $rows[] = [
                'row_number' => $line,
                'employee_no' => mb_strtoupper($mapped['employee_no']),
                'first_name' => $mapped['first_name'],
                'last_name' => $mapped['last_name'],
                'phone' => $mapped['phone'],
                'work_email' => $mapped['work_email'] !== '' ? mb_strtolower($mapped['work_email']) : '',
                'hire_date' => $this->validDate($mapped['hire_date']),
            ];
        }

        fclose($handle);

        return [$rows, $contents];
    }

    protected function validDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
