<?php

namespace App\Http\Presenters;

use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\AnnouncementRead;

class AnnouncementPresenter
{
    public static function summary(Announcement $announcement, ?AnnouncementRead $read = null): array
    {
        return [
            'id' => $announcement->getKey(),
            'title' => $announcement->title,
            'status' => $announcement->status,
            'published_at' => $announcement->published_at?->toIso8601String(),
            'read_at' => $read?->read_at?->toIso8601String(),
            'created_at' => $announcement->created_at?->toIso8601String(),
        ];
    }

    public static function detail(Announcement $announcement, ?AnnouncementRead $read = null): array
    {
        return array_merge(self::summary($announcement, $read), [
            'body' => $announcement->body,
        ]);
    }

    public static function admin(Announcement $announcement): array
    {
        return [
            'id' => $announcement->getKey(),
            'title' => $announcement->title,
            'body' => $announcement->body,
            'status' => $announcement->status,
            'author_id' => $announcement->author_id,
            'author_name' => $announcement->author?->name,
            'scheduled_at' => $announcement->scheduled_at?->toIso8601String(),
            'published_at' => $announcement->published_at?->toIso8601String(),
            'archived_at' => $announcement->archived_at?->toIso8601String(),
            'audiences' => $announcement->audiences
                ->map(fn (AnnouncementAudience $audience) => [
                    'type' => $audience->audience_type,
                    'id' => $audience->audience_type === AnnouncementAudience::TYPE_ALL_EMPLOYEES
                        ? null
                        : $audience->audience_id,
                ])
                ->values()
                ->all(),
            'read_count' => (int) ($announcement->read_count ?? $announcement->reads()->count()),
            'created_at' => $announcement->created_at?->toIso8601String(),
            'updated_at' => $announcement->updated_at?->toIso8601String(),
        ];
    }

    public static function analytics(array $analytics): array
    {
        return [
            'recipient_count' => $analytics['recipient_count'],
            'read_count' => $analytics['read_count'],
            'read_rate' => $analytics['read_rate'],
        ];
    }
}
