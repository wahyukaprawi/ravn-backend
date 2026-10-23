<?php

return [
    'max_accuracy_meters' => (int) env('ATTENDANCE_MAX_ACCURACY_METERS', 100),
    'geofence_accuracy_tolerance_meters' => (int) env('ATTENDANCE_GEOFENCE_ACCURACY_TOLERANCE_METERS', 20),
    'face_challenge_ttl_minutes' => (int) env('ATTENDANCE_FACE_CHALLENGE_TTL_MINUTES', 5),
    'face_max_attempts' => (int) env('ATTENDANCE_FACE_MAX_ATTEMPTS', 3),
];
