<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserAdministrationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        [$user, $token] = app(UserAdministrationService::class)->invite(auth()->user(), $data);

        Notification::make()
            ->success()
            ->title('Invitation created')
            ->body('Share this single-use activation token with the user: '.$token)
            ->persistent()
            ->send();

        return $user;
    }
}
