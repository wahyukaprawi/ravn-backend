<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\WorkCalendar;
use App\Models\WorkCalendarDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveCalendarController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function index(): JsonResponse
    {
        $calendars = WorkCalendar::query()->with('days')->orderBy('name')->get();

        return response()->json([
            'data' => $calendars->map(fn (WorkCalendar $calendar) => $this->calendarPayload($calendar))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $calendar = WorkCalendar::query()->create([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'active' => $validated['active'] ?? true,
        ]);

        $this->audit->record('leave.calendar.created', $calendar, null, $calendar->only(['name', 'timezone', 'active']));

        return response()->json(['data' => $this->calendarPayload($calendar)], 201);
    }

    public function update(Request $request, WorkCalendar $calendar): JsonResponse
    {
        $before = $calendar->only(['name', 'timezone', 'active']);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $calendar->fill($validated)->save();

        $this->audit->record('leave.calendar.updated', $calendar, $before, $calendar->only(['name', 'timezone', 'active']));

        return response()->json(['data' => $this->calendarPayload($calendar)]);
    }

    public function storeDay(Request $request, WorkCalendar $calendar): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['work', 'holiday'])],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        $day = WorkCalendarDay::query()->updateOrCreate(
            ['work_calendar_id' => $calendar->getKey(), 'date' => $validated['date']],
            ['type' => $validated['type'], 'name' => $validated['name'] ?? null],
        );

        $this->audit->record('leave.calendar.day.updated', $day, null, $day->only(['date', 'type', 'name']));

        return response()->json([
            'data' => [
                'id' => $day->getKey(),
                'date' => $day->date->toDateString(),
                'type' => $day->type,
                'name' => $day->name,
            ],
        ]);
    }

    public function destroyDay(WorkCalendar $calendar, WorkCalendarDay $day): JsonResponse
    {
        if ((int) $day->work_calendar_id !== (int) $calendar->getKey()) {
            abort(404);
        }

        $day->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    protected function calendarPayload(WorkCalendar $calendar): array
    {
        $calendar->loadMissing('days');

        return [
            'id' => $calendar->getKey(),
            'name' => $calendar->name,
            'timezone' => $calendar->timezone,
            'active' => $calendar->active,
            'days' => $calendar->days->sortBy('date')->map(fn (WorkCalendarDay $day) => [
                'id' => $day->getKey(),
                'date' => $day->date->toDateString(),
                'type' => $day->type,
                'name' => $day->name,
            ])->values()->all(),
        ];
    }
}
