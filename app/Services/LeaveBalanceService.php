<?php

namespace App\Services;

use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveBalanceService
{
    public function getOrCreateBalance(User $user, int $leaveTypeId, int $year): LeaveBalance
    {
        return LeaveBalance::query()->firstOrCreate(
            ['user_id' => $user->getKey(), 'leave_type_id' => $leaveTypeId, 'year' => $year],
            ['entitled' => 0, 'used' => 0, 'reserved' => 0, 'adjusted' => 0],
        );
    }

    public function available(User $user, int $leaveTypeId, int $year): float
    {
        $balance = $this->getOrCreateBalance($user, $leaveTypeId, $year);

        return (float) $balance->entitled + (float) $balance->adjusted - (float) $balance->used - (float) $balance->reserved;
    }

    public function reserve(User $user, LeaveRequest $request, float $days): void
    {
        DB::transaction(function () use ($user, $request, $days) {
            $balance = LeaveBalance::query()
                ->where('user_id', $user->getKey())
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', CarbonYear($request->start_date))
                ->lockForUpdate()
                ->firstOrCreate(
                    ['user_id' => $user->getKey(), 'leave_type_id' => $request->leave_type_id, 'year' => CarbonYear($request->start_date)],
                    ['entitled' => 0, 'used' => 0, 'reserved' => 0, 'adjusted' => 0],
                );

            if ((float) $balance->entitled + (float) $balance->adjusted - (float) $balance->used - (float) $balance->reserved < $days) {
                throw ValidationException::withMessages(['days' => 'Insufficient leave balance.']);
            }

            $balance->reserved = (float) $balance->reserved + $days;
            $balance->save();

            LeaveBalanceLedger::query()->create([
                'user_id' => $user->getKey(),
                'leave_type_id' => $request->leave_type_id,
                'leave_request_id' => $request->getKey(),
                'entry_type' => 'reserve',
                'amount' => $days,
                'reason' => 'leave_request.reserved',
                'actor_id' => $user->getKey(),
                'created_at' => now(),
            ]);
        });
    }

    public function release(User $user, LeaveRequest $request, float $days): void
    {
        DB::transaction(function () use ($user, $request, $days) {
            $balance = LeaveBalance::query()
                ->where('user_id', $user->getKey())
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', CarbonYear($request->start_date))
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                return;
            }

            $balance->reserved = max(0, (float) $balance->reserved - $days);
            $balance->save();

            LeaveBalanceLedger::query()->create([
                'user_id' => $user->getKey(),
                'leave_type_id' => $request->leave_type_id,
                'leave_request_id' => $request->getKey(),
                'entry_type' => 'release',
                'amount' => -$days,
                'reason' => 'leave_request.released',
                'actor_id' => $user->getKey(),
                'created_at' => now(),
            ]);
        });
    }

    public function consume(User $user, LeaveRequest $request, float $days): void
    {
        DB::transaction(function () use ($user, $request, $days) {
            $balance = LeaveBalance::query()
                ->where('user_id', $user->getKey())
                ->where('leave_type_id', $request->leave_type_id)
                ->where('year', CarbonYear($request->start_date))
                ->lockForUpdate()
                ->firstOrFail();

            $balance->reserved = max(0, (float) $balance->reserved - $days);
            $balance->used = (float) $balance->used + $days;
            $balance->save();

            LeaveBalanceLedger::query()->create([
                'user_id' => $user->getKey(),
                'leave_type_id' => $request->leave_type_id,
                'leave_request_id' => $request->getKey(),
                'entry_type' => 'use',
                'amount' => $days,
                'reason' => 'leave_request.approved',
                'actor_id' => $user->getKey(),
                'created_at' => now(),
            ]);
        });
    }
}

function CarbonYear(string $date): int
{
    return (int) Carbon::parse($date)->format('Y');
}
