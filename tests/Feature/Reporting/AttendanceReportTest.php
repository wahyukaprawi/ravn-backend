<?php

namespace Tests\Feature\Reporting;

use App\Models\AttendanceEvent;
use App\Models\ReportExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class AttendanceReportTest extends ReportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');
        config(['queue.default' => 'sync']);

        $this->scheduleFor($this->employee);
    }

    public function test_at_t23_reports_recalculate_from_recorded_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:20:00', 'Asia/Jakarta'));
        $this->checkIn($this->employee)->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-06-15 16:30:00', 'Asia/Jakarta'));
        $this->checkOut($this->employee)->assertCreated();

        $summary = $this->runReport($this->hr, 'attendance.summary', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $row = collect($summary->json('data.rows'))->firstWhere('user_id', $this->employee->getKey());

        $this->assertSame(1, $row['work_days']);
        $this->assertSame(1, $row['late_days']);
        $this->assertSame(20, $row['late_minutes']);
        $this->assertSame(1, $row['early_departure_days']);
        $this->assertSame(30, $row['early_departure_minutes']);
        $this->assertSame($this->employee->getKey(), $row['user_id']);

        $daily = $this->runReport($this->hr, 'attendance.daily', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $dailyRow = collect($daily->json('data.rows'))->firstWhere('user_id', $this->employee->getKey());
        $this->assertSame('2026-06-15 09:20', $dailyRow['check_in_at']);
        $this->assertSame('2026-06-15 16:30', $dailyRow['check_out_at']);
        $this->assertSame('2026-06-15 09:00', $dailyRow['scheduled_start']);
        $this->assertSame(20, $dailyRow['late_minutes']);
        $this->assertSame(30, $dailyRow['early_departure_minutes']);

        $this->assertSame(1, $daily->json('data.totals.late_days'));
        $this->assertSame(20, $daily->json('data.totals.late_minutes'));
    }

    public function test_at_t23_out_of_schedule_days_are_counted_as_exceptions(): void
    {
        $this->addSession($this->employee, ['flags' => ['outside_schedule']]);
        $this->addSession($this->teammate);

        $summary = $this->runReport($this->hr, 'attendance.summary', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $row = collect($summary->json('data.rows'))->firstWhere('user_id', $this->employee->getKey());
        $this->assertSame(1, $row['exception_days']);

        $teammate = collect($summary->json('data.rows'))->firstWhere('user_id', $this->teammate->getKey());
        $this->assertSame(0, $teammate['exception_days']);
    }

    public function test_at_t24_reports_and_exports_exclude_coordinates_and_face_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:05:00', 'Asia/Jakarta'));
        $this->checkIn($this->employee)->assertCreated();

        $daily = $this->runReport($this->hr, 'attendance.daily', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertOk();

        $row = collect($daily->json('data.rows'))->firstWhere('user_id', $this->employee->getKey());
        $this->assertArrayNotHasKey('latitude', $row);
        $this->assertArrayNotHasKey('longitude', $row);
        $this->assertArrayNotHasKey('face_verified', $row);
        $this->assertArrayNotHasKey('accuracy_meters', $row);

        $this->asUser($this->hr)->postJson('/api/v1/reports/attendance.daily/exports', [
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'scope' => 'all',
        ])->assertStatus(202);

        $export = ReportExport::query()->firstOrFail();
        $content = Storage::disk('reports')->get($export->path);
        $event = AttendanceEvent::query()->firstOrFail();

        $this->assertStringNotContainsString('latitude', $content);
        $this->assertStringNotContainsString('longitude', $content);
        $this->assertStringNotContainsString('face', $content);
        $this->assertStringNotContainsString((string) $event->latitude, $content);
        $this->assertStringNotContainsString((string) $event->longitude, $content);
    }
}
