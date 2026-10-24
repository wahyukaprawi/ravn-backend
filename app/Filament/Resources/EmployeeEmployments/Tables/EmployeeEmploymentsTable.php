<?php

namespace App\Filament\Resources\EmployeeEmployments\Tables;

use App\Models\EmployeeEmployment;
use App\Services\EmploymentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class EmployeeEmploymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee'))
            ->columns([
                TextColumn::make('employee.employee_no')->label('Employee no.')->searchable()->sortable(),
                TextColumn::make('employee.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
                TextColumn::make('employment_type')->badge()->sortable(),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Open-ended')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (EmployeeEmployment $record): string => $record->isEffective() ? 'active' : ($record->isFuture() ? 'future' : 'past'))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'future' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('employment_type')
                    ->options(array_combine(EmployeeEmployment::TYPES, EmployeeEmployment::TYPES)),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('terminate')
                    ->label('Terminate')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        DatePicker::make('effective_to')
                            ->label('Effective end date')
                            ->required()
                            ->native(false),
                    ])
                    ->visible(fn (EmployeeEmployment $record): bool => $record->effective_to === null && auth()->user()?->can('employments.terminate'))
                    ->action(function (EmployeeEmployment $record, array $data): void {
                        try {
                            app(EmploymentService::class)->terminate($record, $data['effective_to'], auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Employment could not be terminated')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Employment terminated')->send();
                    }),
            ]);
    }
}
