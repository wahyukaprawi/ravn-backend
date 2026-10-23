<?php

return [

    'disk' => env('REPORT_EXPORT_DISK', 'reports'),

    'export_ttl_hours' => (int) env('REPORT_EXPORT_TTL_HOURS', 24),

    'default_range_days' => (int) env('REPORT_DEFAULT_RANGE_DAYS', 30),

    'max_range_days' => (int) env('REPORT_MAX_RANGE_DAYS', 366),

    'max_rows' => (int) env('REPORT_MAX_ROWS', 10000),

    'formats' => ['csv'],

];
