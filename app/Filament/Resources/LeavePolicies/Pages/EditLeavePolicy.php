<?php

namespace App\Filament\Resources\LeavePolicies\Pages;

use App\Audit\AuditRecorder;
use App\Filament\Resources\LeavePolicies\LeavePolicyResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLeavePolicy extends EditRecord
{
    protected static string $resource = LeavePolicyResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = $record->only(['name', 'annual_entitlement_days', 'submission_deadline_days_before', 'effective_from', 'effective_to', 'active']);

        $record->fill([
            'name' => $data['name'],
            'annual_entitlement_days' => $data['annual_entitlement_days'],
            'submission_deadline_days_before' => $data['submission_deadline_days_before'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'active' => (bool) ($data['active'] ?? false),
        ])->save();

        app(AuditRecorder::class)->record(
            'leave.policy.updated',
            $record,
            $before,
            $record->only(['name', 'annual_entitlement_days', 'submission_deadline_days_before', 'effective_from', 'effective_to', 'active']),
            auth()->user(),
        );

        return $record;
    }
}
