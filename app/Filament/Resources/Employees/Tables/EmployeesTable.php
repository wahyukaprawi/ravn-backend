<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Models\Employee;
use App\Services\EmployeeService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_no')->label('Employee no.')->badge()->searchable()->sortable(),
                TextColumn::make('full_name')->label('Name')->searchable(['first_name', 'last_name'])->sortable('first_name'),
                TextColumn::make('work_email')->searchable()->toggleable(),
                TextColumn::make('currentAssignment.department.name')->label('Department')->placeholder('—'),
                TextColumn::make('currentAssignment.position.name')->label('Position')->placeholder('—'),
                TextColumn::make('currentAssignment.workLocation.name')->label('Location')->placeholder('—'),
                TextColumn::make('user.status')->label('Account')->badge()->placeholder('Not linked'),
                TextColumn::make('deleted_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->using(fn (Employee $record) => app(EmployeeService::class)->delete($record, auth()->user())),
                RestoreAction::make()
                    ->using(fn (Employee $record) => app(EmployeeService::class)->restore($record->getKey(), auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function ($records): void {
                            $service = app(EmployeeService::class);

                            foreach ($records as $record) {
                                $service->delete($record, auth()->user());
                            }
                        }),
                ]),
            ]);
    }
}
