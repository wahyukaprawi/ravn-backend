<?php

namespace App\Filament\Resources\LeaveBalances;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\LeaveBalances\Pages\ListLeaveBalances;
use App\Filament\Resources\LeaveBalances\Tables\LeaveBalancesTable;
use App\Models\LeaveBalance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LeaveBalanceResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = LeaveBalance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Leave balances';

    protected static ?string $viewAnyPermission = 'leave_balances.adjust';

    protected static ?string $viewPermission = 'leave_balances.adjust';

    public static function table(Table $table): Table
    {
        return LeaveBalancesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveBalances::route('/'),
        ];
    }
}
