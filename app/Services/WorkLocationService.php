<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkLocationService
{
    private const AUDITED_FIELDS = [
        'code',
        'name',
        'address',
        'remote_address',
        'latitude',
        'longitude',
        'radius_meters',
        'timezone',
        'active',
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function create(array $data, User $actor): WorkLocation
    {
        $data = $this->normalize($data);
        $data['active'] = true;

        return DB::transaction(function () use ($data, $actor) {
            $location = WorkLocation::query()->create($data);

            $this->audit->record('work_location.created', $location, null, $location->only(self::AUDITED_FIELDS), $actor);

            return $location;
        });
    }

    public function update(WorkLocation $location, array $data, User $actor): WorkLocation
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($location, $data, $actor) {
            $location = WorkLocation::query()->lockForUpdate()->findOrFail($location->getKey());
            $before = $location->only(self::AUDITED_FIELDS);

            if (array_key_exists('timezone', $data)
                && $data['timezone'] !== $location->timezone
                && $location->assignments()->exists()) {
                throw ValidationException::withMessages([
                    'timezone' => 'The timezone cannot be changed after an assignment has used this location.',
                ]);
            }

            if (array_key_exists('active', $data) && ! $data['active']) {
                $this->guardActiveOrFutureAssignments($location);
            }

            $location->fill($data)->save();

            $this->audit->record(
                'work_location.updated',
                $location,
                $before,
                $location->only(self::AUDITED_FIELDS),
                $actor,
            );

            return $location->fresh();
        });
    }

    public function delete(WorkLocation $location, User $actor): void
    {
        DB::transaction(function () use ($location, $actor) {
            $location = WorkLocation::query()->lockForUpdate()->findOrFail($location->getKey());
            $this->guardActiveOrFutureAssignments($location);

            $before = $location->only(self::AUDITED_FIELDS);

            $location->forceFill(['active' => false])->save();
            $location->delete();

            $this->audit->record('work_location.deleted', $location, $before, [
                'active' => false,
                'deleted_at' => now()->toIso8601String(),
            ], $actor);
        });
    }

    public function restore(int $id, User $actor): WorkLocation
    {
        return DB::transaction(function () use ($id, $actor) {
            $location = WorkLocation::withTrashed()->lockForUpdate()->findOrFail($id);

            if (! $location->trashed()) {
                return $location;
            }

            $before = $location->only(self::AUDITED_FIELDS);

            $location->restore();
            $location->forceFill(['active' => true])->save();

            $this->audit->record('work_location.restored', $location, $before, $location->only(self::AUDITED_FIELDS), $actor);

            return $location->fresh();
        });
    }

    protected function guardActiveOrFutureAssignments(WorkLocation $location): void
    {
        $today = now()->toDateString();

        $inUse = EmployeeAssignment::query()
            ->where('work_location_id', $location->getKey())
            ->whereNull('canceled_at')
            ->where(function ($query) use ($today) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $today);
            })
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'work_location' => 'An active or future assignment still references this location.',
            ]);
        }
    }

    protected function normalize(array $data): array
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = mb_strtoupper(trim((string) $data['code']));
        }

        return $data;
    }
}
