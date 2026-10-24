<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\AnnouncementAudience;
use App\Services\AnnouncementService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data['audiences'] = collect($data['audiences'] ?? [])
            ->map(fn (array $audience): array => [
                'type' => $audience['audience_type'],
                'id' => $audience['audience_type'] === AnnouncementAudience::TYPE_ALL_EMPLOYEES ? 0 : $audience['audience_id'],
            ])
            ->values()
            ->all();

        return app(AnnouncementService::class)->update($record, auth()->user(), $data);
    }
}
