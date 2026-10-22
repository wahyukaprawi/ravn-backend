<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveConcurrencyTest extends LeaveTestCase
{
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        $this->seed(RbacSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    protected function tearDown(): void
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }

    public function test_lv_t07_concurrent_submissions_serialize_on_the_employee_row(): void
    {
        $this->calendar();
        $type = $this->leaveType();
        $this->policy($type);

        $manager = $this->userWithRole('manager');
        $employee = $this->userWithRole('employee', $manager);
        $this->grantBalance($employee, $type, 2027, 10);

        config(['database.connections.lock_holder' => config('database.connections.mysql')]);

        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->table('users')->where('id', $employee->getKey())->lockForUpdate()->first();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(LeaveService::class)->submit($employee, [
                'leave_type_id' => $type->getKey(),
                'start_date' => '2027-03-01',
                'end_date' => '2027-03-02',
            ]);

            $this->fail('The submission should have waited on the employee row lock.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
        } finally {
            $holder->rollBack();
        }

        $this->assertSame(0, LeaveRequest::query()->count());

        $first = app(LeaveService::class)->submit($employee, [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-02',
        ]);

        $this->assertSame(LeaveRequest::STATUS_PENDING, $first->status);

        $this->expectException(ValidationException::class);

        app(LeaveService::class)->submit($employee, [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-02',
            'end_date' => '2027-03-03',
        ]);
    }
}
