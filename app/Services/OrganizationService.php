<?php

namespace App\Services;

use App\Audit\AuditRecorder;
use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function createDepartment(array $data, User $actor): Department
    {
        return $this->create(new Department, $data, $actor, 'department');
    }

    public function updateDepartment(Department $department, array $data, User $actor): Department
    {
        return $this->update($department, $data, $actor, 'department');
    }

    public function deleteDepartment(Department $department, User $actor): void
    {
        $this->delete($department, $actor, 'department');
    }

    public function restoreDepartment(int $id, User $actor): Department
    {
        return $this->restore(new Department, $id, $actor, 'department');
    }

    public function createPosition(array $data, User $actor): Position
    {
        return $this->create(new Position, $data, $actor, 'position');
    }

    public function updatePosition(Position $position, array $data, User $actor): Position
    {
        return $this->update($position, $data, $actor, 'position');
    }

    public function deletePosition(Position $position, User $actor): void
    {
        $this->delete($position, $actor, 'position');
    }

    public function restorePosition(int $id, User $actor): Position
    {
        return $this->restore(new Position, $id, $actor, 'position');
    }

    protected function create(Model $model, array $data, User $actor, string $type): Model
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($model, $data, $actor, $type) {
            $model->fill($data)->save();

            $this->audit->record("{$type}.created", $model, null, $model->only(['code', 'name', 'description']), $actor);

            return $model;
        });
    }

    protected function update(Model $model, array $data, User $actor, string $type): Model
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($model, $data, $actor, $type) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());
            $before = $model->only(['code', 'name', 'description']);

            $model->fill($data)->save();

            $this->audit->record(
                "{$type}.updated",
                $model,
                $before,
                $model->only(['code', 'name', 'description']),
                $actor,
            );

            return $model->fresh();
        });
    }

    protected function delete(Model $model, User $actor, string $type): void
    {
        DB::transaction(function () use ($model, $actor, $type) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());

            $today = now()->toDateString();
            $inUse = EmployeeAssignment::query()
                ->where($type === 'department' ? 'department_id' : 'position_id', $model->getKey())
                ->whereNull('canceled_at')
                ->where(function ($query) use ($today) {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $today);
                })
                ->exists();

            if ($inUse) {
                throw ValidationException::withMessages([
                    $type => 'An active or future assignment still references this record.',
                ]);
            }

            $before = $model->only(['code', 'name', 'description']);
            $model->delete();

            $this->audit->record("{$type}.deleted", $model, $before, ['deleted_at' => now()->toIso8601String()], $actor);
        });
    }

    protected function restore(Model $model, int $id, User $actor, string $type): Model
    {
        return DB::transaction(function () use ($model, $id, $actor, $type) {
            $model = $model->newQuery()->withTrashed()->lockForUpdate()->findOrFail($id);

            if (! $model->trashed()) {
                return $model;
            }

            $conflict = $model->newQuery()
                ->whereKeyNot($model->getKey())
                ->where('code', $model->code)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages(['code' => 'Another active record already uses this code.']);
            }

            $model->restore();

            $this->audit->record("{$type}.restored", $model, ['deleted_at' => $model->deleted_at?->toIso8601String()], [
                'restored_at' => now()->toIso8601String(),
            ], $actor);

            return $model->fresh();
        });
    }

    protected function normalize(array $data): array
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = mb_strtoupper(trim((string) $data['code']));
        }

        return $data;
    }
}
