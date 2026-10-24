<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\AnnouncementAudience;
use App\Services\AnnouncementService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(AnnouncementService::class)->create(auth()->user(), $this->normalize($data));
    }

    protected function normalize(array $data): array
    {
        $data['audiences'] = collect($data['audiences'] ?? [])
            ->map(fn (array $audience): array => [
                'type' => $audience['audience_type'],
                'id' => $audience['audience_type'] === AnnouncementAudience::TYPE_ALL_EMPLOYEES ? 0 : $audience['audience_id'],
            ])
            ->values()
            ->all();

        return $data;
    }
}
