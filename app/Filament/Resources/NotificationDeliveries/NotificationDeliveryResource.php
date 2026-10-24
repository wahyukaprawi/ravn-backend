<?php

namespace App\Filament\Resources\NotificationDeliveries;

use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Resources\NotificationDeliveries\Pages\ViewNotificationDelivery;
use App\Filament\Resources\NotificationDeliveries\Schemas\NotificationDeliveryInfolist;
use App\Filament\Resources\NotificationDeliveries\Tables\NotificationDeliveriesTable;
use App\Models\NotificationDelivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class NotificationDeliveryResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = NotificationDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Notification deliveries';

    protected static ?string $viewAnyPermission = 'notifications.view_failures';

    protected static ?string $viewPermission = 'notifications.view_failures';

    public static function infolist(Schema $schema): Schema
    {
        return NotificationDeliveryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationDeliveriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationDeliveries::route('/'),
            'view' => ViewNotificationDelivery::route('/{record}'),
        ];
    }
}
