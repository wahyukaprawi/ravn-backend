<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Audit\AuditRecorder;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();

        $data['permissions'] = $role->permissions()->pluck('name')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = $record->permissions()->pluck('name')->sort()->values()->all();

        $record->syncPermissions($data['permissions'] ?? []);

        app(AuditRecorder::class)->record(
            'role.permissions.updated',
            $record,
            ['permissions' => $before],
            ['permissions' => $record->permissions()->pluck('name')->sort()->values()->all()],
            auth()->user(),
        );

        return $record;
    }
}
