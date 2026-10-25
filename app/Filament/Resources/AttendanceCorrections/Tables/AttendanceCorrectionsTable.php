<?php

namespace App\Filament\Resources\AttendanceCorrections\Tables;

use App\Filament\Actions\SensitiveAction;
use App\Models\AttendanceCorrection;
use App\Services\AttendanceCorrectionService;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['session.user', 'requester', 'approver']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('session.user.name')->label('Employee')->searchable(),
                TextColumn::make('session.work_date')->label('Work date')->date()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AttendanceCorrection::STATUS_APPROVED, AttendanceCorrection::STATUS_EXECUTED => 'success',
                        AttendanceCorrection::STATUS_REJECTED, AttendanceCorrection::STATUS_CANCELLED => 'danger',
                        AttendanceCorrection::STATUS_SUBMITTED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('requester.name')->label('Requested by')->toggleable(),
                TextColumn::make('approver.name')->label('Approver')->placeholder('—')->toggleable(),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        AttendanceCorrection::STATUS_DRAFT => 'Draft',
                        AttendanceCorrection::STATUS_SUBMITTED => 'Submitted',
                        AttendanceCorrection::STATUS_APPROVED => 'Approved',
                        AttendanceCorrection::STATUS_EXECUTED => 'Executed',
                        AttendanceCorrection::STATUS_REJECTED => 'Rejected',
                        AttendanceCorrection::STATUS_CANCELLED => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                SensitiveAction::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (AttendanceCorrection $record): bool => $record->status === AttendanceCorrection::STATUS_SUBMITTED && auth()->user()?->can('attendance.corrections.approve'))
                    ->sensitiveAction(function (AttendanceCorrection $record): void {
                        try {
                            app(AttendanceCorrectionService::class)->approve($record, auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Correction could not be approved')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Correction approved')->send();
                    }),
                SensitiveAction::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (AttendanceCorrection $record): bool => $record->status === AttendanceCorrection::STATUS_SUBMITTED && auth()->user()?->can('attendance.corrections.approve'))
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->sensitiveAction(function (AttendanceCorrection $record, array $data): void {
                        try {
                            app(AttendanceCorrectionService::class)->reject($record, auth()->user(), (string) $data['reason']);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Correction could not be rejected')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Correction rejected')->send();
                    }),
            ]);
    }
}
