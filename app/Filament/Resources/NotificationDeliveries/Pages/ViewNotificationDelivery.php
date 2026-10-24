<?php

namespace App\Filament\Resources\NotificationDeliveries\Pages;

use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNotificationDelivery extends ViewRecord
{
    protected static string $resource = NotificationDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
