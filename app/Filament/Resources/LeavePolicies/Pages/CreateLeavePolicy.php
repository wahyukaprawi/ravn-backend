<?php

namespace App\Filament\Resources\LeavePolicies\Pages;

use App\Audit\AuditRecorder;
use App\Filament\Resources\LeavePolicies\LeavePolicyResource;
use App\Models\LeavePolicy;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLeavePolicy extends CreateRecord
{
    protected static string $resource = LeavePolicyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $policy = LeavePolicy::query()->create([
            'leave_type_id' => $data['leave_type_id'],
            'name' => $data['name'],
            'annual_entitlement_days' => $data['annual_entitlement_days'],
            'submission_deadline_days_before' => $data['submission_deadline_days_before'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
        ]);

        app(AuditRecorder::class)->record(
            'leave.policy.created',
            $policy,
            null,
            $policy->only(['leave_type_id', 'name', 'annual_entitlement_days', 'submission_deadline_days_before', 'effective_from', 'effective_to', 'active']),
            auth()->user(),
        );

        return $policy;
    }
}
