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
        return $this->getOrCreateBalance($user, $leaveTypeId, $year)->available();
    }

    public function reserve(User $user, LeaveRequest $request, float $days): LeaveBalance
    {
        return DB::transaction(function () use ($user, $request, $days) {
            $balance = $this->lockBalance($user, (int) $request->leave_type_id, $this->yearOf($request->start_date));

            if ($balance->available() < $days) {
                throw ValidationException::withMessages([
                    'days' => 'Insufficient leave balance for this request.',
                ]);
            }

            $balance->reserved = (float) $balance->reserved + $days;
            $balance->save();

            $this->ledger($user, $request, 'reserve', $days, 'leave.request.reserved');

            return $balance;
        });
    }

    public function release(User $user, LeaveRequest $request, float $days): void
    {
        DB::transaction(function () use ($user, $request, $days) {
            $balance = $this->lockBalance($user, (int) $request->leave_type_id, $this->yearOf($request->start_date), false);

            if ($balance === null) {
                return;
            }

            $balance->reserved = max(0, (float) $balance->reserved - $days);
            $balance->save();

            $this->ledger($user, $request, 'release', -$days, 'leave.request.released');
        });
    }

    public function consume(User $user, LeaveRequest $request, float $days): void
    {
        DB::transaction(function () use ($user, $request, $days) {
            $balance = $this->lockBalance($user, (int) $request->leave_type_id, $this->yearOf($request->start_date));

            $balance->reserved = max(0, (float) $balance->reserved - $days);
            $balance->used = (float) $balance->used + $days;
            $balance->save();

            $this->ledger($user, $request, 'use', $days, 'leave.request.approved');
        });
    }

    public function refund(User $user, LeaveRequest $request, float $days): void
    {
        DB::transaction(function () use ($user, $request, $days) {
            $balance = $this->lockBalance($user, (int) $request->leave_type_id, $this->yearOf($request->start_date), false);

            if ($balance === null) {
                return;
            }

            $balance->used = max(0, (float) $balance->used - $days);
            $balance->save();

            $this->ledger($user, $request, 'refund', -$days, 'leave.request.cancellation_approved');
        });
    }

    public function adjust(User $actor, User $employee, int $leaveTypeId, int $year, float $amount, string $reason): LeaveBalance
    {
        return DB::transaction(function () use ($actor, $employee, $leaveTypeId, $year, $amount, $reason) {
            $balance = $this->lockBalance($employee, $leaveTypeId, $year);

            $balance->adjusted = (float) $balance->adjusted + $amount;
            $balance->save();

            LeaveBalanceLedger::query()->create([
                'user_id' => $employee->getKey(),
                'leave_type_id' => $leaveTypeId,
                'leave_request_id' => null,
                'entry_type' => 'adjustment',
                'amount' => $amount,
                'reason' => $reason,
                'actor_id' => $actor->getKey(),
                'created_at' => now(),
            ]);

            return $balance->fresh();
        });
    }

    protected function lockBalance(User $user, int $leaveTypeId, int $year, bool $create = true): ?LeaveBalance
    {
        $balance = LeaveBalance::query()
            ->where('user_id', $user->getKey())
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($balance !== null || ! $create) {
            return $balance;
        }

        $this->getOrCreateBalance($user, $leaveTypeId, $year);

        return LeaveBalance::query()
            ->where('user_id', $user->getKey())
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function ledger(User $user, LeaveRequest $request, string $entryType, float $amount, string $reason): void
    {
        LeaveBalanceLedger::query()->create([
            'user_id' => $user->getKey(),
            'leave_type_id' => $request->leave_type_id,
            'leave_request_id' => $request->getKey(),
            'entry_type' => $entryType,
            'amount' => $amount,
            'reason' => $reason,
            'actor_id' => $request->submitted_by ?? $user->getKey(),
            'created_at' => now(),
        ]);
    }

    protected function yearOf($date): int
    {
        return (int) Carbon::parse($date)->format('Y');
    }
}
