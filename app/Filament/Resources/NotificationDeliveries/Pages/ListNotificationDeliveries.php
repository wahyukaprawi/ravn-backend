<?php

namespace App\Filament\Resources\NotificationDeliveries\Pages;

use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNotificationDeliveries extends ListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
