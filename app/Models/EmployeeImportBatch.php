<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeImportBatch extends Model
{
    public const STATUS_VALIDATED = 'validated';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uploaded_by',
        'original_filename',
        'stored_path',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EmployeeImportRow::class);
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }
}
