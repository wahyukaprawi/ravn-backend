<?php

namespace App\Filament\Resources\LeaveBalances\Tables;

use App\Audit\AuditRecorder;
use App\Filament\Actions\SensitiveAction;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\LeaveBalanceService;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class LeaveBalancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'type']))
            ->columns([
                TextColumn::make('user.name')->label('Employee')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('type.name')->label('Leave type')->sortable(),
                TextColumn::make('year')->sortable(),
                TextColumn::make('entitled')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('used')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('reserved')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('adjusted')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (LeaveBalance $record): string => number_format($record->available(), 2))
                    ->badge()
                    ->color(fn (LeaveBalance $record): string => $record->available() > 0 ? 'success' : 'danger'),
            ])
            ->filters([
                SelectFilter::make('leave_type_id')
                    ->label('Leave type')
                    ->options(fn (): array => LeaveType::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('year')
                    ->options(fn (): array => LeaveBalance::query()
                        ->distinct()
                        ->orderByDesc('year')
                        ->pluck('year', 'year')
                        ->all()),
            ])
            ->recordActions([
                SensitiveAction::make('adjust')
                    ->label('Adjust')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->minValue(-365)
                            ->maxValue(365)
                            ->notIn([0]),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->sensitiveAction(function (LeaveBalance $record, array $data): void {
                        try {
                            $before = $record->fresh();
                            $balance = app(LeaveBalanceService::class)->adjust(
                                auth()->user(),
                                $record->user,
                                (int) $record->leave_type_id,
                                (int) $record->year,
                                (float) $data['amount'],
                                (string) $data['reason'],
                            );

                            app(AuditRecorder::class)->record('leave.balance.adjusted', $balance, [
                                'adjusted' => (float) $before->adjusted,
                            ], [
                                'adjusted' => (float) $balance->adjusted,
                                'amount' => (float) $data['amount'],
                                'year' => (int) $balance->year,
                                'leave_type_id' => (int) $balance->leave_type_id,
                                'user_id' => (int) $balance->user_id,
                                'reason' => (string) $data['reason'],
                            ]);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Balance could not be adjusted')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()->title('Leave balance adjusted')->send();
                    }),
            ]);
    }
}
