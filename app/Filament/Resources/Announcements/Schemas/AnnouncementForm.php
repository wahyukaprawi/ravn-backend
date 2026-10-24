<?php

namespace App\Filament\Resources\Announcements\Schemas;

use App\Models\AnnouncementAudience;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkLocation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Announcement')
                    ->schema([
                        TextInput::make('title')->required()->maxLength(200),
                        Textarea::make('body')->required()->rows(8)->columnSpanFull(),
                        DateTimePicker::make('scheduled_at')
                            ->label('Schedule for')
                            ->native(false)
                            ->helperText('Leave blank to save as a draft.'),
                    ]),
                Section::make('Audience')
                    ->schema([
                        Repeater::make('audiences')
                            ->schema([
                                Select::make('audience_type')
                                    ->label('Audience')
                                    ->options([
                                        AnnouncementAudience::TYPE_ALL_EMPLOYEES => 'All employees',
                                        AnnouncementAudience::TYPE_DEPARTMENT => 'Department',
                                        AnnouncementAudience::TYPE_WORK_LOCATION => 'Work location',
                                        AnnouncementAudience::TYPE_SPECIFIC_EMPLOYEE => 'Specific employee',
                                    ])
                                    ->required()
                                    ->live(),
                                Select::make('audience_id')
                                    ->label('Target')
                                    ->options(fn (Get $get): array => match ($get('audience_type')) {
                                        AnnouncementAudience::TYPE_DEPARTMENT => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
                                        AnnouncementAudience::TYPE_WORK_LOCATION => WorkLocation::query()->where('active', true)->orderBy('name')->pluck('name', 'id')->all(),
                                        AnnouncementAudience::TYPE_SPECIFIC_EMPLOYEE => Employee::query()->orderBy('last_name')->get()->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => $employee->employee_no.' — '.$employee->full_name])->all(),
                                        default => [],
                                    })
                                    ->searchable()
                                    ->visible(fn (Get $get): bool => $get('audience_type') !== AnnouncementAudience::TYPE_ALL_EMPLOYEES)
                                    ->required(fn (Get $get): bool => $get('audience_type') !== AnnouncementAudience::TYPE_ALL_EMPLOYEES),
                            ])
                            ->columns(2)
                            ->defaultItems(1)
                            ->minItems(1)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
