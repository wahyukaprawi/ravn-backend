<?php

return [
    'import_max_rows' => (int) env('EMPLOYEE_IMPORT_MAX_ROWS', 5000),
    'import_max_kilobytes' => (int) env('EMPLOYEE_IMPORT_MAX_KB', 2048),
    'geofence_radius_min_meters' => (int) env('GEOFENCE_RADIUS_MIN_METERS', 1),
    'geofence_radius_max_meters' => (int) env('GEOFENCE_RADIUS_MAX_METERS', 50000),
];
