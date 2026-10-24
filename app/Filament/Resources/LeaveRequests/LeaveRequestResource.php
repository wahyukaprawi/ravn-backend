<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\LeaveRequests\Pages\ViewLeaveRequest;
use App\Filament\Resources\LeaveRequests\Schemas\LeaveRequestInfolist;
use App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Models\LeaveRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LeaveRequestResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Leave requests';

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $viewAnyPermission = 'leave_requests.view_any';

    protected static ?string $viewPermission = 'leave_requests.view_any';

    public static function infolist(Schema $schema): Schema
    {
        return LeaveRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveRequests::route('/'),
            'view' => ViewLeaveRequest::route('/{record}'),
        ];
    }
}
