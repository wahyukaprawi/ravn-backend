<?php

namespace Database\Seeders;

use App\Models\Report;
use Illuminate\Database\Seeder;

class ReportSeeder extends Seeder
{
    public const REPORTS = [
        [
            'code' => 'attendance.summary',
            'name' => 'Attendance summary by employee',
            'scope' => 'attendance',
            'permission' => 'attendance.reports.view',
            'sensitive' => false,
        ],
        [
            'code' => 'attendance.daily',
            'name' => 'Attendance daily register',
            'scope' => 'attendance',
            'permission' => 'attendance.reports.view',
            'sensitive' => false,
        ],
        [
            'code' => 'audit.activity',
            'name' => 'Audit activity',
            'scope' => 'audit',
            'permission' => 'audit.view',
            'sensitive' => true,
        ],
    ];

    public function run(): void
    {
        foreach (self::REPORTS as $report) {
            Report::query()->updateOrCreate(
                ['code' => $report['code']],
                [
                    'name' => $report['name'],
                    'scope' => $report['scope'],
                    'permission' => $report['permission'],
                    'sensitive' => $report['sensitive'],
                ],
            );
        }
    }
}
