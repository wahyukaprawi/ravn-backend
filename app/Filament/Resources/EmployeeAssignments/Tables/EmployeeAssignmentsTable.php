<?php

namespace App\Filament\Resources\EmployeeAssignments\Tables;

use App\Models\EmployeeAssignment;
use App\Services\AssignmentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class EmployeeAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'department', 'position', 'workLocation']))
            ->columns([
                TextColumn::make('employee.employee_no')->label('Employee no.')->searchable()->sortable(),
                TextColumn::make('employee.full_name')->label('Employee')->searchable(['first_name', 'last_name']),
                TextColumn::make('department.name')->label('Department')->sortable(),
                TextColumn::make('position.name')->label('Position')->sortable(),
                TextColumn::make('workLocation.name')->label('Location'),
                TextColumn::make('effective_from')->date()->sortable(),
                TextColumn::make('effective_to')->date()->placeholder('Open-ended')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (EmployeeAssignment $record): string => $record->canceled_at !== null ? 'cancelled' : $record->status())
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'future' => 'info',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('department_id')
                    ->relationship('department', 'name')
                    ->label('Department'),
                SelectFilter::make('position_id')
                    ->relationship('position', 'name')
                    ->label('Position'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('cancel')
                    ->label('Cancel assignment')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (EmployeeAssignment $record): bool => $record->canceled_at === null && $record->effective_from->isFuture())
                    ->action(function (EmployeeAssignment $record): void {
                        try {
                            app(AssignmentService::class)->cancel($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Assignment could not be cancelled')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Assignment cancelled')->send();
                    }),
            ]);
    }
}
