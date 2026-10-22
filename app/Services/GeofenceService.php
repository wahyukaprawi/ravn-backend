<?php

namespace App\Services;

use App\Models\WorkLocation;

class GeofenceService
{
    public function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function validate(WorkLocation $location, float $lat, float $lon, ?float $accuracy = null): array
    {
        $distance = $this->distanceMeters((float) $location->latitude, (float) $location->longitude, $lat, $lon);
        $allowed = (float) $location->radius_meters + ($accuracy ?? 0);

        if ($distance > $allowed) {
            return ['ok' => false, 'distance_meters' => round($distance, 2), 'reason' => 'outside_geofence'];
        }

        return ['ok' => true, 'distance_meters' => round($distance, 2)];
    }
}
