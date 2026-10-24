<?php

namespace App\Filament\Resources\LeaveTypes\Pages;

use App\Audit\AuditRecorder;
use App\Filament\Resources\LeaveTypes\LeaveTypeResource;
use App\Models\LeaveType;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLeaveType extends CreateRecord
{
    protected static string $resource = LeaveTypeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $type = LeaveType::query()->create([
            'code' => mb_strtolower((string) $data['code']),
            'name' => $data['name'],
            'requires_approval' => (bool) ($data['requires_approval'] ?? true),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        app(AuditRecorder::class)->record(
            'leave.type.created',
            $type,
            null,
            $type->only(['code', 'name', 'requires_approval', 'active']),
            auth()->user(),
        );

        return $type;
    }
}
