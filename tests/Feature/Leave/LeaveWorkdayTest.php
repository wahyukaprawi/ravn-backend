<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveRequest;

class LeaveWorkdayTest extends LeaveTestCase
{
    public function test_lv_t01_counts_only_eligible_workdays_inclusively(): void
    {
        $calendar = $this->calendar();
        $this->holiday($calendar, '2027-03-03');

        $type = $this->leaveType();
        $this->policy($type);

        $manager = $this->userWithRole('manager');
        $employee = $this->userWithRole('employee', $manager);

        $this->grantBalance($employee, $type, 2027, 12);

        $response = $this->withToken($this->login($employee))->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-07',
            'reason' => 'Family matters',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.days', 4);
        $response->assertJsonPath('data.status', LeaveRequest::STATUS_PENDING);

        $this->assertSame('4.00', LeaveRequest::query()->firstOrFail()->days);
    }

    public function test_lv_t01_excludes_weekends_even_without_holidays(): void
    {
        $this->calendar();

        $type = $this->leaveType();
        $this->policy($type);

        $employee = $this->userWithRole('employee', $this->userWithRole('manager'));
        $this->grantBalance($employee, $type, 2027, 12);

        $response = $this->withToken($this->login($employee))->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-07',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.days', 5);
    }

    public function test_lv_t02_rejects_a_request_with_zero_eligible_workdays(): void
    {
        $this->calendar();

        $type = $this->leaveType();
        $this->policy($type);

        $employee = $this->userWithRole('employee', $this->userWithRole('manager'));
        $this->grantBalance($employee, $type, 2027, 12);

        $response = $this->withToken($this->login($employee))->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-06',
            'end_date' => '2027-03-07',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_lv_t02_rejects_an_inverted_date_range(): void
    {
        $this->calendar();

        $type = $this->leaveType();
        $this->policy($type);

        $employee = $this->userWithRole('employee', $this->userWithRole('manager'));
        $this->grantBalance($employee, $type, 2027, 12);

        $this->withToken($this->login($employee))->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $type->getKey(),
            'start_date' => '2027-03-05',
            'end_date' => '2027-03-01',
        ])->assertStatus(422);
    }
}
