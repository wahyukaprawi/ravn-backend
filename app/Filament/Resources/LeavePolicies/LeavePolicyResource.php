<?php

namespace App\Filament\Resources\LeavePolicies;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\LeavePolicies\Pages\CreateLeavePolicy;
use App\Filament\Resources\LeavePolicies\Pages\EditLeavePolicy;
use App\Filament\Resources\LeavePolicies\Pages\ListLeavePolicies;
use App\Filament\Resources\LeavePolicies\Schemas\LeavePolicyForm;
use App\Filament\Resources\LeavePolicies\Tables\LeavePoliciesTable;
use App\Models\LeavePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LeavePolicyResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = LeavePolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Leave policies';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewAnyPermission = 'leave_policies.view';

    protected static ?string $viewPermission = 'leave_policies.view';

    protected static ?string $createPermission = 'leave_policies.manage';

    protected static ?string $updatePermission = 'leave_policies.manage';

    public static function form(Schema $schema): Schema
    {
        return LeavePolicyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeavePoliciesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeavePolicies::route('/'),
            'create' => CreateLeavePolicy::route('/create'),
            'edit' => EditLeavePolicy::route('/{record}/edit'),
        ];
    }
}
