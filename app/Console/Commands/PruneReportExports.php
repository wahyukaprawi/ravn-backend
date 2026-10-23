<?php

namespace App\Console\Commands;

use App\Services\ReportExportService;
use Illuminate\Console\Command;

class PruneReportExports extends Command
{
    protected $signature = 'reports:prune {--limit=500 : Maximum number of exports to prune per run}';

    protected $description = 'Delete expired report export files and mark the exports as expired';

    public function handle(ReportExportService $exports): int
    {
        $count = $exports->prune((int) $this->option('limit'));

        $this->info("Pruned {$count} expired report exports.");

        return self::SUCCESS;
    }
}
