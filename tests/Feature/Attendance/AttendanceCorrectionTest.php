<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class AttendanceCorrectionTest extends AttendanceTestCase
{
    public function test_at_t21_correction_lifecycle_preserves_the_original_event_and_uses_three_people(): void
    {
        $sessionId = $this->sessionFor($this->employee);

        $created = $this->createCorrection($this->employee, $sessionId, [
            'check_in_at' => '2026-06-15T08:45:00+07:00',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.before.events.0.occurred_at', '2026-06-15T02:00:00+00:00');

        $correctionId = (int) $created->json('data.id');

        $this->submitCorrection($this->employee, $correctionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->asUser($this->employee, true)
            ->postJson('/api/v1/attendance/corrections/'.$correctionId.'/approve')
            ->assertForbidden();

        $this->approveCorrection($this->manager, $correctionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approver.id', $this->manager->getKey());

        $this->asUser($this->manager, true)
            ->postJson('/api/v1/attendance/corrections/'.$correctionId.'/execute')
            ->assertForbidden();

        $this->executeCorrection($this->hr, $correctionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('data.executor.id', $this->hr->getKey())
            ->assertJsonPath('data.session.events.0.occurred_at', '2026-06-15T01:45:00+00:00')
            ->assertJsonPath('data.session.events.0.verification_status', 'correction');

        $correction = AttendanceCorrection::query()->findOrFail($correctionId);

        $this->assertSame('2026-06-15T02:00:00+00:00', $correction->before['events'][0]['occurred_at']);

        $event = AttendanceEvent::query()->where('event_type', AttendanceEvent::TYPE_CHECK_IN)->firstOrFail();
        $session = AttendanceSession::query()->findOrFail($sessionId);

        $this->assertSame('2026-06-15T01:45:00+00:00', $event->occurred_at->toIso8601String());
        $this->assertSame('2026-06-15T01:45:00+00:00', $session->opened_at->toIso8601String());
        $this->assertSame($this->hr->getKey(), $event->corrected_by);
        $this->assertSame($correctionId, $event->attendance_correction_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.correction.executed']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'attendance.correction.executed']);
    }

    public function test_at_t21_execute_replays_with_the_same_idempotency_key(): void
    {
        $sessionId = $this->sessionFor($this->employee);

        $correctionId = (int) $this->createCorrection($this->employee, $sessionId, [
            'check_in_at' => '2026-06-15T08:45:00+07:00',
        ])->assertCreated()->json('data.id');

        $this->submitCorrection($this->employee, $correctionId)->assertOk();
        $this->approveCorrection($this->manager, $correctionId)->assertOk();
        $this->executeCorrection($this->hr, $correctionId)->assertOk();

        $this->executeCorrection($this->hr, $correctionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');

        $this->assertDatabaseCount('attendance_events', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance.correction.executed')->count());
    }

    public function test_at_t22_the_approver_cannot_execute_the_same_sensitive_correction(): void
    {
        $hrTwo = $this->user('hr_admin');
        $sessionId = $this->sessionFor($this->employee);

        $correctionId = (int) $this->createCorrection($this->employee, $sessionId, [
            'check_in_at' => '2026-06-15T08:45:00+07:00',
        ])->assertCreated()->json('data.id');

        $this->submitCorrection($this->employee, $correctionId)->assertOk();
        $this->approveCorrection($this->hr, $correctionId)->assertOk();

        $this->executeCorrection($this->hr, $correctionId)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->executeCorrection($hrTwo, $correctionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');
    }

    public function test_at_t21_correction_rejection_keeps_the_original_attendance(): void
    {
        $sessionId = $this->sessionFor($this->employee);

        $correctionId = (int) $this->createCorrection($this->employee, $sessionId, [
            'check_in_at' => '2026-06-15T08:45:00+07:00',
        ])->assertCreated()->json('data.id');

        $this->submitCorrection($this->employee, $correctionId)->assertOk();

        $this->asUser($this->manager, true)
            ->postJson('/api/v1/attendance/corrections/'.$correctionId.'/reject', [
                'reason' => 'The evidence does not match the recorded time.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $event = AttendanceEvent::query()->where('event_type', AttendanceEvent::TYPE_CHECK_IN)->firstOrFail();

        $this->assertSame('2026-06-15T02:00:00+00:00', $event->occurred_at->toIso8601String());
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'attendance.correction.rejected']);
    }

    public function test_at_t21_correction_can_add_a_missing_checkout_with_evidence(): void
    {
        $sessionId = $this->sessionFor($this->employee);

        $correctionId = (int) $this->createCorrection($this->employee, $sessionId, [
            'check_out_at' => '2026-06-15T17:00:00+07:00',
        ])->assertCreated()->json('data.id');

        $this->submitCorrection($this->employee, $correctionId)->assertOk();
        $this->approveCorrection($this->manager, $correctionId)->assertOk();

        $this->executeCorrection($this->hr, $correctionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'executed');

        $session = AttendanceSession::query()->with('events')->firstOrFail();

        $this->assertSame(AttendanceSession::STATUS_CLOSED, $session->status);
        $this->assertCount(2, $session->events);
        $this->assertSame('2026-06-15T10:00:00+00:00', $session->checkOutEvent()->occurred_at->toIso8601String());
        $this->assertSame('2026-06-15T10:00:00+00:00', $session->closed_at->toIso8601String());
    }

    public function test_at_t21_failed_execution_keeps_the_correction_approved(): void
    {
        $sessionId = $this->sessionFor($this->employee);

        $correctionId = (int) $this->createCorrection($this->employee, $sessionId, [
            'check_out_at' => '2026-06-15T16:00:00+07:00',
        ])->assertCreated()->json('data.id');

        $this->submitCorrection($this->employee, $correctionId)->assertOk();
        $this->approveCorrection($this->manager, $correctionId)->assertOk();

        AttendanceEvent::query()
            ->where('event_type', AttendanceEvent::TYPE_CHECK_IN)
            ->update(['occurred_at' => Carbon::parse('2026-06-15 17:00:00', 'Asia/Jakarta')->utc()]);

        $this->executeCorrection($this->hr, $correctionId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame('approved', AttendanceCorrection::query()->findOrFail($correctionId)->status);
    }

    public function test_at_t22_correction_evidence_is_private_and_available_to_the_requester(): void
    {
        Storage::fake('local');

        $sessionId = $this->sessionFor($this->employee);

        $response = $this->createCorrection(
            $this->employee,
            $sessionId,
            ['check_in_at' => '2026-06-15T08:45:00+07:00'],
            UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
        )->assertCreated()
            ->assertJsonPath('data.has_evidence', true);

        $correction = AttendanceCorrection::query()->findOrFail((int) $response->json('data.id'));

        $this->assertNotNull($correction->evidence_path);
        Storage::disk('local')->assertExists($correction->evidence_path);

        $this->asUser($this->employee)
            ->get('/api/v1/attendance/corrections/'.$correction->getKey().'/evidence')
            ->assertOk();

        $other = $this->user('employee');

        $this->asUser($other)
            ->getJson('/api/v1/attendance/corrections/'.$correction->getKey())
            ->assertForbidden();
    }

    public function test_at_t21_cancelling_a_draft_prevents_execution(): void
    {
        $sessionId = $this->sessionFor($this->employee);

        $correctionId = (int) $this->createCorrection($this->employee, $sessionId, [
            'check_in_at' => '2026-06-15T08:45:00+07:00',
        ])->assertCreated()->json('data.id');

        $this->asUser($this->employee)
            ->postJson('/api/v1/attendance/corrections/'.$correctionId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->submitCorrection($this->employee, $correctionId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_at_t21_employees_cannot_correct_another_employees_session(): void
    {
        $sessionId = $this->sessionFor($this->employee);
        $other = $this->user('employee');

        $this->createCorrection($other, $sessionId, [
            'check_in_at' => '2026-06-15T08:45:00+07:00',
        ])->assertForbidden();
    }
}
