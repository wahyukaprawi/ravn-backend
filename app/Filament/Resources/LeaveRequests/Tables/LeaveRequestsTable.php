<?php

namespace App\Filament\Resources\LeaveRequests\Tables;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class LeaveRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'type', 'manager']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('type.name')->label('Leave type')->sortable(),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date()->sortable(),
                TextColumn::make('days')->numeric(decimalPlaces: 1)->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        LeaveRequest::STATUS_APPROVED => 'success',
                        LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_HELD => 'warning',
                        LeaveRequest::STATUS_REJECTED, LeaveRequest::STATUS_CANCELED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('manager.name')->label('Manager')->placeholder('—')->toggleable(),
                TextColumn::make('submitted_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        LeaveRequest::STATUS_DRAFT => 'Draft',
                        LeaveRequest::STATUS_PENDING => 'Pending',
                        LeaveRequest::STATUS_HELD => 'Held',
                        LeaveRequest::STATUS_APPROVED => 'Approved',
                        LeaveRequest::STATUS_REJECTED => 'Rejected',
                        LeaveRequest::STATUS_CANCELED => 'Canceled',
                    ]),
                SelectFilter::make('leave_type_id')
                    ->relationship('type', 'name')
                    ->label('Leave type'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (LeaveRequest $record): bool => $record->status === LeaveRequest::STATUS_PENDING && auth()->user()?->can('workflow.approve'))
                    ->action(function (LeaveRequest $record): void {
                        try {
                            app(LeaveService::class)->decide($record, 'approve', auth()->user());
                        } catch (ValidationException|AuthorizationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Leave request could not be approved')
                                ->body($exception instanceof ValidationException
                                    ? collect($exception->errors())->flatten()->first()
                                    : $exception->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Leave request approved')->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record): bool => $record->status === LeaveRequest::STATUS_PENDING && auth()->user()?->can('workflow.reject'))
                    ->schema([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (LeaveRequest $record, array $data): void {
                        try {
                            app(LeaveService::class)->decide($record, 'reject', auth()->user(), [
                                'reason' => (string) $data['reason'],
                            ]);
                        } catch (ValidationException|AuthorizationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Leave request could not be rejected')
                                ->body($exception instanceof ValidationException
                                    ? collect($exception->errors())->flatten()->first()
                                    : $exception->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Leave request rejected')->send();
                    }),
            ]);
    }
}
