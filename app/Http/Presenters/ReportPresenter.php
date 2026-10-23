<?php

namespace App\Http\Presenters;

use App\Models\Report;
use App\Models\ReportExport;
use App\Reporting\ReportDefinition;

class ReportPresenter
{
    public static function report(?Report $report, ?ReportDefinition $definition = null): ?array
    {
        if ($report === null) {
            return null;
        }

        return [
            'id' => $report->getKey(),
            'code' => $report->code,
            'name' => $report->name,
            'scope' => $report->scope,
            'sensitive' => $report->sensitive,
            'columns' => self::columns($definition),
            'scopes' => $definition === null ? [] : $definition->scopes(),
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function columns(?ReportDefinition $definition): array
    {
        if ($definition === null) {
            return [];
        }

        $columns = [];

        foreach ($definition->columns() as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label];
        }

        return $columns;
    }

    public static function export(?ReportExport $export): ?array
    {
        if ($export === null) {
            return null;
        }

        return [
            'id' => $export->getKey(),
            'report_code' => $export->report_code,
            'status' => $export->status,
            'format' => $export->format,
            'scope' => $export->scope,
            'filters' => $export->filters,
            'row_count' => $export->row_count,
            'failure_code' => $export->failure_code,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'downloaded_at' => $export->downloaded_at?->toIso8601String(),
            'downloaded_by' => $export->downloaded_by,
            'created_at' => $export->created_at?->toIso8601String(),
            'download_path' => $export->status === ReportExport::STATUS_READY
                ? '/api/v1/reports/exports/'.$export->getKey().'/download'
                : null,
        ];
    }
}
