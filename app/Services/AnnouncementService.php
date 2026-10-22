<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AnnouncementService
{
    public function publish(Announcement $announcement): Announcement
    {
        return DB::transaction(function () use ($announcement) {
            $announcement->forceFill([
                'status' => 'published',
                'published_at' => now(),
            ])->save();

            OutboxMessage::query()->create([
                'event_type' => 'announcement.published',
                'aggregate_type' => 'Announcement',
                'aggregate_id' => $announcement->getKey(),
                'payload' => ['announcement_id' => $announcement->getKey(), 'title' => $announcement->title],
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
            ]);

            return $announcement->fresh();
        });
    }

    public function archive(Announcement $announcement): Announcement
    {
        $announcement->forceFill([
            'status' => 'archived',
            'archived_at' => now(),
        ])->save();

        return $announcement->fresh();
    }

    public function markRead(Announcement $announcement, User $user): void
    {
        DB::transaction(function () use ($announcement, $user) {
            $announcement->reads()->firstOrCreate([
                'announcement_id' => $announcement->getKey(),
                'user_id' => $user->getKey(),
            ], ['read_at' => now()]);
        });
    }

    public function canView(Announcement $announcement, User $user): bool
    {
        if ($announcement->status !== 'published') {
            return $user->hasRole('hr_admin') || $user->hasRole('super_admin');
        }

        $audiences = $announcement->audiences()->get();

        if ($audiences->isEmpty()) {
            return true;
        }

        foreach ($audiences as $audience) {
            if ($audience->audience_type === 'role' && $user->hasRole((string) $audience->audience_id)) {
                return true;
            }

            if ($audience->audience_type === 'user' && (int) $audience->audience_id === (int) $user->getKey()) {
                return true;
            }
        }

        return $user->hasRole('hr_admin') || $user->hasRole('super_admin');
    }
}
