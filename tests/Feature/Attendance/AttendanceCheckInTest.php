<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;

class AttendanceCheckInTest extends AttendanceTestCase
{
    public function test_at_t01_status_and_check_in_use_the_effective_schedule_and_server_time(): void
    {
        $this->asUser($this->employee)->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.can_check_in', true)
            ->assertJsonPath('data.work_date', '2026-06-15')
            ->assertJsonPath('data.schedule.id', $this->schedule->getKey());

        $response = $this->checkIn($this->employee)->assertCreated();

        $response->assertJsonPath('data.event.event_type', 'check_in')
            ->assertJsonPath('data.session.status', 'open')
            ->assertJsonPath('data.session.work_date', '2026-06-15')
            ->assertJsonPath('data.session.late_minutes', 0);

        $this->assertSame('2026-06-15T02:00:00+00:00', $response->json('data.event.occurred_at'));
        $this->assertDatabaseCount('attendance_events', 1);

        $this->asUser($this->employee)->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.can_check_in', false)
            ->assertJsonPath('data.can_check_out', true);
    }

    public function test_at_t01_missing_schedule_blocks_attendance_without_guessing(): void
    {
        $this->schedule->delete();

        $this->asUser($this->employee)->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.can_check_in', false)
            ->assertJsonPath('data.reason', 'schedule_missing');

        $this->checkIn($this->employee)
            ->assertStatus(422)
            ->assertJsonPath('error.details.schedule.0', 'A valid effective work schedule is required for attendance.');

        $this->assertDatabaseCount('attendance_sessions', 0);
    }

    public function test_at_t02_overnight_shift_uses_the_previous_business_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 01:00:00', 'Asia/Jakarta'));

        $this->schedule->forceFill([
            'start_time' => '22:00',
            'end_time' => '06:00',
            'overnight' => true,
        ])->save();

        $response = $this->checkIn($this->employee)->assertCreated();

        $response->assertJsonPath('data.session.work_date', '2026-06-15')
            ->assertJsonPath('data.session.scheduled_start_at', '2026-06-15T15:00:00+00:00')
            ->assertJsonPath('data.session.scheduled_end_at', '2026-06-15T23:00:00+00:00');
    }

    public function test_at_t03_attendance_outside_the_scheduled_workday_is_flagged_but_recorded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Jakarta'));

        $response = $this->checkIn($this->employee)->assertCreated();

        $response->assertJsonPath('data.event.verification_status', 'flagged');

        $session = AttendanceSession::query()->firstOrFail();

        $this->assertContains('outside_schedule', $session->flags);
        $this->assertContains('outside_schedule', $response->json('data.session.flags'));
    }

    public function test_at_t05_geofence_and_accuracy_are_validated_without_expanding_the_radius(): void
    {
        $this->checkIn($this->employee, ['latitude' => -6.3])
            ->assertStatus(422)
            ->assertJsonPath('error.details.geofence.0', 'You are outside the assigned work location geofence.');

        $this->checkIn($this->employee, ['accuracy_meters' => 500])
            ->assertStatus(422)
            ->assertJsonPath('error.details.accuracy_meters.0', 'GPS accuracy is too low. Move to an open area and try again.');

        $this->assertDatabaseCount('attendance_sessions', 0);
    }

    public function test_at_t14_mock_location_is_rejected_and_audited(): void
    {
        $this->checkIn($this->employee, ['mock_location_detected' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.details.mock_location_detected.0', 'Mock location signals are not accepted. Use the official correction process if needed.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'attendance.location.flagged',
        ]);

        $this->assertDatabaseCount('attendance_sessions', 0);
    }

    public function test_at_t18_face_challenge_is_required_and_single_use(): void
    {
        $this->checkIn($this->employee, ['face_challenge_id' => 'missing'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.face_challenge_id.0', 'The face verification challenge is invalid.');

        $challenge = $this->challenge($this->employee);
        $payload = $this->punch($this->employee, ['face_challenge_id' => $challenge]);

        $this->asUser($this->employee)
            ->postJson('/api/v1/attendance/check-in', $payload, ['Idempotency-Key' => 'face-one'])
            ->assertCreated();

        $this->asUser($this->employee)
            ->postJson('/api/v1/attendance/check-in', $payload, ['Idempotency-Key' => 'face-two'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.face_challenge_id.0', 'This face verification challenge is no longer valid.');

        $this->assertDatabaseCount('attendance_events', 1);
    }

    public function test_at_t18_face_failure_reaches_the_retry_limit_and_never_records_attendance(): void
    {
        $challenge = $this->challenge($this->employee);
        $payload = $this->punch($this->employee, [
            'face_challenge_id' => $challenge,
            'face_verified' => false,
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->asUser($this->employee)
                ->postJson('/api/v1/attendance/check-in', $payload, ['Idempotency-Key' => 'face-fail-'.$attempt])
                ->assertStatus(422)
                ->assertJsonPath('error.details.face_verified.0', 'Face verification failed. Request a new challenge and try again.');
        }

        $this->assertDatabaseHas('face_verification_challenges', [
            'challenge_id' => $challenge,
            'status' => 'failed',
            'attempts' => 3,
        ]);

        $this->asUser($this->employee)
            ->postJson('/api/v1/attendance/check-in', array_merge($payload, ['face_verified' => true]), ['Idempotency-Key' => 'face-fail-final'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.face_challenge_id.0', 'This face verification challenge is no longer valid.');

        $this->assertDatabaseCount('attendance_events', 0);
    }

    public function test_at_t09_check_in_replays_the_same_idempotency_key_without_a_duplicate(): void
    {
        $payload = $this->punch($this->employee);
        $token = $this->tokenFor($this->employee);

        $first = $this->freshRequest()->withToken($token)
            ->postJson('/api/v1/attendance/check-in', $payload, ['Idempotency-Key' => 'same-key'])
            ->assertCreated();

        $second = $this->freshRequest()->withToken($token)
            ->postJson('/api/v1/attendance/check-in', $payload, ['Idempotency-Key' => 'same-key'])
            ->assertCreated();

        $this->assertSame($first->json('data.event.id'), $second->json('data.event.id'));
        $this->assertSame($first->json('data.session.id'), $second->json('data.session.id'));
        $this->assertDatabaseCount('attendance_events', 1);
    }

    public function test_at_t10_duplicate_transitions_are_rejected(): void
    {
        $this->checkOut($this->employee)->assertStatus(422);

        $this->checkIn($this->employee, [], 'first-in')->assertCreated();

        $this->checkIn($this->employee, [], 'second-in')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->checkOut($this->employee, [], 'first-out')->assertCreated();

        $this->checkOut($this->employee, [], 'second-out')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('attendance_events', 2);
    }

    public function test_at_t08_late_and_early_departure_minutes_are_computed_from_the_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:30:00', 'Asia/Jakarta'));

        $this->checkIn($this->employee)->assertCreated()
            ->assertJsonPath('data.session.late_minutes', 30);

        Carbon::setTestNow(Carbon::parse('2026-06-15 16:30:00', 'Asia/Jakarta'));

        $this->checkOut($this->employee)->assertCreated()
            ->assertJsonPath('data.session.early_departure_minutes', 30);

        $session = AttendanceSession::query()->firstOrFail();

        $this->assertContains('late', $session->flags);
        $this->assertContains('early_departure', $session->flags);
    }

    public function test_at_t23_history_scopes_are_enforced_and_raw_coordinates_are_not_returned(): void
    {
        $this->checkIn($this->employee)->assertCreated();

        $team = $this->asUser($this->manager)
            ->getJson('/api/v1/attendance/history?scope=team')
            ->assertOk();

        $this->assertCount(1, $team->json('data'));
        $this->assertStringNotContainsString('latitude', json_encode($team->json('data')));
        $this->assertStringNotContainsString('longitude', json_encode($team->json('data')));

        $this->asUser($this->employee)
            ->getJson('/api/v1/attendance/history?scope=team')
            ->assertForbidden();

        $this->asUser($this->hr)
            ->getJson('/api/v1/attendance/history?scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asUser($this->employee)
            ->getJson('/api/v1/attendance/history?scope=all')
            ->assertForbidden();
    }

    public function test_at_t01_administrators_can_manage_non_overlapping_schedules(): void
    {
        $payload = [
            'user_id' => $this->manager->getKey(),
            'work_location_id' => $this->location->getKey(),
            'name' => 'Manager shift',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00',
            'end_time' => '16:00',
            'overnight' => false,
            'late_grace_minutes' => 5,
            'early_departure_grace_minutes' => 5,
            'active' => true,
        ];

        $created = $this->asUser($this->hr)
            ->postJson('/api/v1/admin/attendance/schedules', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/attendance/schedules', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.details.effective_from.0', 'This schedule overlaps another effective schedule for the user.');

        $this->asUser($this->hr)
            ->postJson('/api/v1/admin/attendance/schedules', array_merge($payload, ['days' => [0]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->asUser($this->hr)
            ->patchJson('/api/v1/admin/attendance/schedules/'.$created, ['name' => 'Updated shift'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated shift');

        $this->asUser($this->employee)
            ->postJson('/api/v1/admin/attendance/schedules', $payload)
            ->assertForbidden();

        $this->asUser($this->hr)
            ->deleteJson('/api/v1/admin/attendance/schedules/'.$created)
            ->assertOk();

        $this->assertSoftDeleted('schedules', ['id' => $created]);
    }

    public function test_at_t20_attendance_mutations_write_audit_and_outbox_rows(): void
    {
        $this->checkIn($this->employee)->assertCreated();
        $this->checkOut($this->employee)->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.check_in']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.check_out']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'attendance.check_in']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'attendance.check_out']);
        $this->assertSame(2, AttendanceEvent::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'like', '%biometric%')->count());
    }
}
