<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportRow extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_CREATED = 'created';

    protected $fillable = [
        'employee_import_batch_id',
        'row_number',
        'employee_no',
        'first_name',
        'last_name',
        'phone',
        'work_email',
        'hire_date',
        'status',
        'errors',
        'employee_id',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(EmployeeImportBatch::class, 'employee_import_batch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
