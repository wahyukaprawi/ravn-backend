<?php

namespace Tests\Feature\EndToEnd;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeEmployment;
use App\Models\WorkLocation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Employee\EmployeeTestCase;

class EmployeeOrganizationReleaseFlowTest extends EmployeeTestCase
{
    protected function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'employees.csv',
            "employee_no,first_name,last_name,phone,work_email,hire_date\n".$rows,
        );
    }

    public function test_e2e_02_employee_organization_import_and_correction_flow(): void
    {
        Storage::fake('local');

        $hr = $this->user('hr_admin');
        $managerUser = $this->user('manager');
        $employeeUser = $this->user('employee');
        $unrelatedUser = $this->user('employee');

        $departmentId = $this->actingAs($hr)->postJson('/api/v1/admin/departments', [
            'code' => 'ops',
            'name' => 'Operations',
        ])->assertCreated()->assertJsonPath('data.code', 'OPS')->json('data.id');

        $managerPositionId = $this->actingAs($hr)->postJson('/api/v1/admin/positions', [
            'code' => 'mgr',
            'name' => 'Manager',
        ])->assertCreated()->assertJsonPath('data.code', 'MGR')->json('data.id');

        $employeePositionId = $this->actingAs($hr)->postJson('/api/v1/admin/positions', [
            'code' => 'dev2',
            'name' => 'Developer II',
        ])->assertCreated()->assertJsonPath('data.code', 'DEV2')->json('data.id');

        $newPositionId = $this->actingAs($hr)->postJson('/api/v1/admin/positions', [
            'code' => 'sen',
            'name' => 'Senior Developer',
        ])->assertCreated()->assertJsonPath('data.code', 'SEN')->json('data.id');

        $locationId = $this->actingAs($hr)->postJson('/api/v1/admin/work-locations', [
            'code' => 'br2',
            'name' => 'Branch Two',
            'address' => '2 Example Street',
            'latitude' => -6.3,
            'longitude' => 106.9,
            'radius_meters' => 100,
            'timezone' => 'Asia/Jakarta',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'BR2')
            ->assertJsonPath('data.geofence_radius_meters', 100)
            ->json('data.id');

        $managerEmployeeId = $this->actingAs($hr)->postJson('/api/v1/employees', [
            'employee_no' => 'mgr-900',
            'user_id' => $managerUser->id,
            'first_name' => 'Maya',
            'last_name' => 'Manager',
            'work_email' => 'MAYA.MANAGER@EXAMPLE.TEST',
            'hire_date' => '2026-06-15',
        ])->assertCreated()
            ->assertJsonPath('data.employee_no', 'MGR-900')
            ->assertJsonPath('data.work_email', 'maya.manager@example.test')
            ->json('data.id');

        $subordinateEmployeeId = $this->actingAs($hr)->postJson('/api/v1/employees', [
            'employee_no' => 'emp-900',
            'user_id' => $employeeUser->id,
            'first_name' => 'Evan',
            'last_name' => 'Employee',
            'work_email' => 'EVAN.EMPLOYEE@EXAMPLE.TEST',
            'hire_date' => '2026-06-15',
        ])->assertCreated()
            ->assertJsonPath('data.employee_no', 'EMP-900')
            ->assertJsonPath('data.work_email', 'evan.employee@example.test')
            ->json('data.id');

        $unrelatedEmployeeId = $this->actingAs($hr)->postJson('/api/v1/employees', [
            'employee_no' => 'emp-901',
            'user_id' => $unrelatedUser->id,
            'first_name' => 'Una',
            'last_name' => 'Unrelated',
            'hire_date' => '2026-06-15',
        ])->assertCreated()->json('data.id');

        $managerEmploymentId = $this->actingAs($hr)->postJson("/api/v1/employees/{$managerEmployeeId}/employments", [
            'employment_type' => 'permanent',
            'effective_from' => '2026-06-15',
        ])->assertCreated()
            ->assertJsonPath('data.effective_from', '2026-06-15')
            ->assertJsonPath('data.status', 'active')
            ->json('data.id');

        $subordinateEmploymentId = $this->actingAs($hr)->postJson("/api/v1/employees/{$subordinateEmployeeId}/employments", [
            'employment_type' => 'permanent',
            'effective_from' => '2026-06-15',
        ])->assertCreated()->json('data.id');

        $this->actingAs($hr)->postJson("/api/v1/employees/{$managerEmployeeId}/assignments", [
            'employee_employment_id' => $managerEmploymentId,
            'department_id' => $departmentId,
            'position_id' => $managerPositionId,
            'manager_id' => null,
            'work_location_id' => $locationId,
            'effective_from' => '2026-06-15',
        ])->assertCreated()
            ->assertJsonPath('data.department.code', 'OPS')
            ->assertJsonPath('data.position.code', 'MGR')
            ->assertJsonPath('data.manager', null);

        $subordinateAssignmentId = $this->actingAs($hr)->postJson("/api/v1/employees/{$subordinateEmployeeId}/assignments", [
            'employee_employment_id' => $subordinateEmploymentId,
            'department_id' => $departmentId,
            'position_id' => $employeePositionId,
            'manager_id' => $managerUser->id,
            'work_location_id' => $locationId,
            'effective_from' => '2026-06-15',
        ])->assertCreated()
            ->assertJsonPath('data.department.code', 'OPS')
            ->assertJsonPath('data.position.code', 'DEV2')
            ->assertJsonPath('data.manager.id', $managerUser->id)
            ->assertJsonPath('data.work_location.code', 'BR2')
            ->json('data.id');

        $managerList = $this->actingAs($managerUser)->getJson('/api/v1/employees')
            ->assertOk()
            ->json('data');
        $managerVisibleIds = collect($managerList)->pluck('id')->all();

        $this->assertContains($managerEmployeeId, $managerVisibleIds);
        $this->assertContains($subordinateEmployeeId, $managerVisibleIds);
        $this->assertNotContains($unrelatedEmployeeId, $managerVisibleIds);

        $this->actingAs($managerUser)->getJson("/api/v1/employees/{$subordinateEmployeeId}")->assertOk();
        $this->actingAs($managerUser)->getJson("/api/v1/employees/{$unrelatedEmployeeId}")->assertForbidden();

        $this->actingAs($employeeUser)->getJson("/api/v1/employees/{$subordinateEmployeeId}")->assertOk();
        $this->actingAs($employeeUser)->getJson("/api/v1/employees/{$unrelatedEmployeeId}")->assertForbidden();
        $this->actingAs($employeeUser)->getJson('/api/v1/employees')->assertForbidden();
        $this->actingAs($employeeUser)->patchJson("/api/v1/employees/{$subordinateEmployeeId}", [
            'first_name' => 'Self Edit',
        ])->assertForbidden();

        $this->actingAs($hr)->getJson('/api/v1/employees')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($hr)->patchJson("/api/v1/employees/{$subordinateEmployeeId}", [
            'first_name' => 'Evan Updated',
        ])->assertOk()->assertJsonPath('data.first_name', 'Evan Updated');

        $this->actingAs($hr)->patchJson("/api/v1/admin/work-locations/{$locationId}", [
            'timezone' => 'Asia/Makassar',
        ])->assertStatus(422);
        $this->assertSame('Asia/Jakarta', WorkLocation::query()->findOrFail($locationId)->timezone);

        $this->actingAs($hr)->deleteJson("/api/v1/employees/{$subordinateEmployeeId}")->assertStatus(422);
        $this->actingAs($hr)->deleteJson("/api/v1/admin/departments/{$departmentId}")->assertStatus(422);
        $this->actingAs($hr)->deleteJson("/api/v1/admin/positions/{$employeePositionId}")->assertStatus(422);
        $this->actingAs($hr)->deleteJson("/api/v1/admin/work-locations/{$locationId}")->assertStatus(422);

        $replacementId = $this->actingAs($hr)->postJson("/api/v1/employees/{$subordinateEmployeeId}/assignments", [
            'employee_employment_id' => $subordinateEmploymentId,
            'department_id' => $departmentId,
            'position_id' => $employeePositionId,
            'manager_id' => $managerUser->id,
            'work_location_id' => $locationId,
            'effective_from' => '2026-08-01',
        ])->assertCreated()
            ->assertJsonPath('data.effective_from', '2026-08-01')
            ->json('data.id');

        $assignments = $this->actingAs($hr)->getJson("/api/v1/employees/{$subordinateEmployeeId}/assignments")
            ->assertOk()
            ->json('data');
        $predecessor = collect($assignments)->firstWhere('id', $subordinateAssignmentId);
        $this->assertSame('2026-07-31', $predecessor['effective_to']);

        $this->actingAs($hr)->patchJson("/api/v1/assignments/{$replacementId}", [
            'department_id' => $this->department->id,
        ])->assertOk()->assertJsonPath('data.department.code', 'ENG');

        $this->actingAs($hr)->deleteJson("/api/v1/assignments/{$replacementId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $assignments = $this->actingAs($hr)->getJson("/api/v1/employees/{$subordinateEmployeeId}/assignments")
            ->assertOk()
            ->json('data');
        $predecessor = collect($assignments)->firstWhere('id', $subordinateAssignmentId);
        $cancelled = collect($assignments)->firstWhere('id', $replacementId);
        $this->assertNull($predecessor['effective_to']);
        $this->assertSame('canceled', $cancelled['status']);

        $employeesBeforeImport = Employee::query()->count();

        $this->actingAs($hr)->post('/api/v1/admin/employees/imports', [
            'file' => $this->csv(
                "imp-902,Valid,Row,,valid.import@example.test,2026-01-01\n".
                ",Missing,Number,,,2026-01-01\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.total_rows', 2)
            ->assertJsonPath('error.details.invalid_rows', 1);

        $this->assertSame($employeesBeforeImport, Employee::query()->count());

        $batchId = $this->actingAs($hr)->post('/api/v1/admin/employees/imports', [
            'file' => $this->csv(
                "imp-900,Ada,Lovelace,,ada.import@example.test,2026-01-01\n".
                "imp-901,Grace,Hopper,,grace.import@example.test,2026-02-01\n",
            ),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 2)
            ->json('data.id');

        $firstCommit = $this->actingAs($hr)->postJson("/api/v1/admin/employees/imports/{$batchId}/commit")
            ->assertOk()
            ->assertJsonPath('data.status', 'committed')
            ->assertJsonCount(2, 'employees')
            ->json('employees');

        $this->assertSame($employeesBeforeImport + 2, Employee::query()->count());
        $this->assertSame(2, EmployeeEmployment::query()->count());

        $secondCommit = $this->actingAs($hr)->postJson("/api/v1/admin/employees/imports/{$batchId}/commit")
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->json('employees');

        $this->assertSame(
            collect($firstCommit)->pluck('id')->sort()->values()->all(),
            collect($secondCommit)->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame($employeesBeforeImport + 2, Employee::query()->count());

        $requester = $this->user('hr_admin');
        $approver = $this->user('hr_admin');
        $executor = $this->user('hr_admin');

        $this->assertCount(3, array_unique([$requester->id, $approver->id, $executor->id]));

        $requesterToken = $this->freshMfaToken($requester);
        $approverToken = $this->freshMfaToken($approver);
        $executorToken = $this->freshMfaToken($executor);

        $correctionId = $this->freshRequest()->withToken($requesterToken)
            ->postJson('/api/v1/admin/history-corrections', [
                'entity_type' => 'assignment',
                'entity_id' => $subordinateAssignmentId,
                'changes' => ['position_id' => $newPositionId],
                'reason' => 'Correcting the recorded position after the replacement was cancelled.',
            ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.before.position_id', $employeePositionId)
            ->json('data.id');

        $this->freshRequest()->withToken($requesterToken)
            ->postJson("/api/v1/admin/history-corrections/{$correctionId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->freshRequest()->withToken($requesterToken)
            ->postJson("/api/v1/admin/history-corrections/{$correctionId}/approve")
            ->assertForbidden();

        $this->freshRequest()->withToken($approverToken)
            ->postJson("/api/v1/admin/history-corrections/{$correctionId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approver.id', $approver->id);

        $this->freshRequest()->withToken($approverToken)
            ->postJson("/api/v1/admin/history-corrections/{$correctionId}/execute")
            ->assertForbidden();

        $this->freshRequest()->withToken($executorToken)
            ->postJson("/api/v1/admin/history-corrections/{$correctionId}/execute")
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('data.executor.id', $executor->id);

        $this->freshRequest()->withToken($requesterToken)
            ->getJson("/api/v1/admin/history-corrections/{$correctionId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('data.changes.position_id', $newPositionId);

        $history = $this->freshRequest()->withToken($this->freshMfaToken($hr))
            ->getJson("/api/v1/employees/{$subordinateEmployeeId}/history")
            ->assertOk()
            ->json('data');

        $corrected = collect($history['assignments'])->firstWhere('id', $subordinateAssignmentId);
        $this->assertSame('SEN', $corrected['position']['code']);

        foreach ([
            'employee.created' => 5,
            'employment.created' => 2,
            'assignment.created' => 3,
            'assignment.updated' => 1,
            'assignment.replaced' => 1,
            'assignment.cancelled' => 1,
            'assignment.reopened' => 1,
            'employee_import.validated' => 2,
            'employee_import.committed' => 1,
            'history_correction.created' => 1,
            'history_correction.submitted' => 1,
            'history_correction.approved' => 1,
            'history_correction.executed' => 1,
        ] as $action => $expected) {
            $this->assertSame(
                $expected,
                AuditLog::query()->where('action', $action)->count(),
                "Unexpected audit count for {$action}",
            );
        }
    }
}
