<?php

namespace App\Services;

use App\Models\ReportExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportExportService
{
    public function create($user, string $reportCode, array $filters = [], string $format = 'csv'): ReportExport
    {
        return DB::transaction(function () use ($user, $reportCode, $filters, $format) {
            $export = ReportExport::query()->create([
                'user_id' => $user->getKey(),
                'report_code' => $reportCode,
                'status' => 'pending',
                'format' => $format,
                'filters' => $filters,
                'disk' => 'local',
                'expires_at' => now()->addHours(24),
            ]);

            return $export;
        });
    }

    public function markReady(ReportExport $export, string $content): ReportExport
    {
        $path = 'exports/'.Str::uuid().'.'.$export->format;
        Storage::disk($export->disk)->put($path, $content);

        $export->forceFill([
            'status' => 'ready',
            'path' => $path,
            'checksum' => hash('sha256', $content),
        ])->save();

        return $export->fresh();
    }

    public function authorizeDownload(ReportExport $export, $user): bool
    {
        if ($export->expires_at && $export->expires_at->isPast()) {
            return false;
        }

        if ((int) $export->user_id !== (int) $user->getKey()) {
            if (! $user->hasRole('hr_admin') && ! $user->hasRole('super_admin')) {
                return false;
            }
        }

        return $export->status === 'ready' && ! empty($export->path);
    }
}
