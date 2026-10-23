<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'report_id',
        'report_code',
        'status',
        'format',
        'scope',
        'filters',
        'disk',
        'path',
        'checksum',
        'row_count',
        'expires_at',
        'downloaded_at',
        'downloaded_by',
        'last_error',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function downloadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
