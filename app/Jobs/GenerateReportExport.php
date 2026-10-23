<?php

namespace App\Jobs;

use App\Services\ReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportExportService $exports): void
    {
        $exports->generate($this->exportId);
    }
}
