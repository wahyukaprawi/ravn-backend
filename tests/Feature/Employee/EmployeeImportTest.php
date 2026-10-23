<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEmployment;
use App\Models\EmployeeImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class EmployeeImportTest extends EmployeeTestCase
{
    protected function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'employees.csv',
            "employee_no,first_name,last_name,phone,work_email,hire_date\n".$rows,
        );
    }

    protected function upload(UploadedFile $file)
    {
        return $this->post('/api/v1/admin/employees/imports', ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_emp_t08_any_invalid_row_rejects_the_whole_batch_without_creating_employees(): void
    {
        $hr = $this->user('hr_admin');

        $response = $this->actingAs($hr)->upload($this->csv(
            "emp-400,Ada,Lovelace,,ada@example.test,2026-01-01\n".
            ",Missing,Number,,,2026-01-01\n",
        ));

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.total_rows', 2)
            ->assertJsonPath('error.details.invalid_rows', 1);

        $batchId = $response->json('error.details.batch_id');

        $this->assertNotNull($batchId);
        $this->assertSame(EmployeeImportBatch::STATUS_INVALID, EmployeeImportBatch::query()->find($batchId)->status);
        $this->assertSame(0, Employee::query()->count());
        $this->assertDatabaseHas('employee_import_rows', [
            'employee_import_batch_id' => $batchId,
            'status' => 'invalid',
        ]);
    }

    public function test_emp_t08_t10_valid_import_commits_once_and_is_idempotent(): void
    {
        $hr = $this->user('hr_admin');

        $upload = $this->actingAs($hr)->upload($this->csv(
            "emp-401,Ada,Lovelace,0812,ada@example.test,2026-01-01\n".
            "emp-402,Grace,Hopper,,grace@example.test,2026-02-01\n",
        ))->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 2);

        $batchId = $upload->json('data.id');

        $first = $this->actingAs($hr)->postJson('/api/v1/admin/employees/imports/'.$batchId.'/commit')
            ->assertOk()
            ->assertJsonPath('data.status', 'committed')
            ->assertJsonCount(2, 'employees')
            ->json('employees');

        $this->assertSame(2, Employee::query()->count());
        $this->assertSame(0, EmployeeEmployment::query()->count());
        $this->assertSame(0, EmployeeAssignment::query()->count());
        $this->assertSame(1, User::query()->count());

        $second = $this->actingAs($hr)->postJson('/api/v1/admin/employees/imports/'.$batchId.'/commit')
            ->assertOk()
            ->json('employees');

        $this->assertSame(
            collect($first)->pluck('id')->sort()->values()->all(),
            collect($second)->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame(2, Employee::query()->count());
        $this->assertSame(EmployeeImportBatch::STATUS_COMMITTED, EmployeeImportBatch::query()->find($batchId)->status);
    }

    public function test_emp_t09_a_conflict_between_validation_and_commit_leaves_no_partial_import(): void
    {
        $hr = $this->user('hr_admin');

        $batchId = $this->actingAs($hr)->upload($this->csv(
            "emp-403,First,Row,,first@example.test,2026-01-01\n".
            "emp-404,Second,Row,,second@example.test,2026-01-01\n",
        ))->assertCreated()->json('data.id');

        Employee::query()->create([
            'employee_no' => 'EMP-404',
            'first_name' => 'Conflict',
            'hire_date' => '2025-01-01',
        ]);

        $this->actingAs($hr)->postJson('/api/v1/admin/employees/imports/'.$batchId.'/commit')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertFalse(Employee::withTrashed()->where('employee_no', 'EMP-403')->exists());
        $this->assertSame(1, Employee::query()->count());
        $this->assertSame(0, EmployeeImportBatch::query()->find($batchId)->rows()->whereNotNull('employee_id')->count());
    }
}
