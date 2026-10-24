<?php

namespace App\Filament\Resources\LeaveTypes\Pages;

use App\Audit\AuditRecorder;
use App\Filament\Resources\LeaveTypes\LeaveTypeResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLeaveType extends EditRecord
{
    protected static string $resource = LeaveTypeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = $record->only(['name', 'requires_approval', 'active']);

        $record->fill([
            'name' => $data['name'],
            'requires_approval' => (bool) ($data['requires_approval'] ?? false),
            'active' => (bool) ($data['active'] ?? false),
        ])->save();

        app(AuditRecorder::class)->record(
            'leave.type.updated',
            $record,
            $before,
            $record->only(['name', 'requires_approval', 'active']),
            auth()->user(),
        );

        return $record;
    }
}
