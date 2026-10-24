<?php

namespace App\Filament\Resources\Schedules\Pages;

use App\Filament\Resources\Schedules\ScheduleResource;
use App\Services\AttendanceScheduleService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSchedule extends EditRecord
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record) => app(AttendanceScheduleService::class)->delete($record, auth()->user())),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(AttendanceScheduleService::class)->update($record, $data, auth()->user());
    }
}
