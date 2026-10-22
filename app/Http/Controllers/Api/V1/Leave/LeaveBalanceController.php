<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $year = (int) $request->query('year', now()->year);

        $types = LeaveType::query()->where('active', true)->orderBy('name')->get();

        $balances = LeaveBalance::query()
            ->where('user_id', $actor->getKey())
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        $data = $types->map(function (LeaveType $type) use ($balances, $year) {
            $balance = $balances->get($type->getKey());

            return [
                'leave_type' => [
                    'id' => $type->getKey(),
                    'code' => $type->code,
                    'name' => $type->name,
                ],
                'year' => $year,
                'entitled' => $balance === null ? 0.0 : (float) $balance->entitled,
                'used' => $balance === null ? 0.0 : (float) $balance->used,
                'reserved' => $balance === null ? 0.0 : (float) $balance->reserved,
                'adjusted' => $balance === null ? 0.0 : (float) $balance->adjusted,
                'available' => $balance === null ? 0.0 : $balance->available(),
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => ['year' => $year],
        ]);
    }
}
