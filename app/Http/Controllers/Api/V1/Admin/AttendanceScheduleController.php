<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AttendancePresenter;
use App\Models\Schedule;
use App\Services\AttendanceScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceScheduleController extends Controller
{
    public function __construct(
        private readonly AttendanceScheduleService $schedules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Schedule::query()->with('location');

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = $query->orderByDesc('effective_from')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Schedule $schedule) => AttendancePresenter::schedule($schedule))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $schedule = $this->schedules->create($validated, $request->user());

        return response()->json(['data' => AttendancePresenter::schedule($schedule)], 201);
    }

    public function show(Schedule $schedule): JsonResponse
    {
        return response()->json(['data' => AttendancePresenter::schedule($schedule->load('location'))]);
    }

    public function update(Request $request, Schedule $schedule): JsonResponse
    {
        $validated = $request->validate($this->rules(true));

        $schedule = $this->schedules->update($schedule, $validated, $request->user());

        return response()->json(['data' => AttendancePresenter::schedule($schedule)]);
    }

    public function destroy(Request $request, Schedule $schedule): JsonResponse
    {
        $this->schedules->delete($schedule, $request->user());

        return response()->json(['status' => 'ok']);
    }

    protected function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'user_id' => [$required, 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'work_location_id' => [$required, 'integer', Rule::exists('work_locations', 'id')->where('active', true)->whereNull('deleted_at')],
            'name' => ['nullable', 'string', 'max:150'],
            'effective_from' => [$required, 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'days' => [$required, 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i', 'different:start_time'],
            'overnight' => ['nullable', 'boolean'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_departure_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
