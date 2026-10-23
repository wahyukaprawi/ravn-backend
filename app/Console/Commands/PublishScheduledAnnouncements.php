<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Console\Command;

class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'announcements:publish-scheduled {--limit=50 : Maximum announcements to publish per run}';

    protected $description = 'Publish announcements whose scheduled time has arrived';

    public function handle(AnnouncementService $announcements): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $due = Announcement::query()
            ->where('status', Announcement::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $published = 0;

        foreach ($due as $announcement) {
            try {
                $result = $announcements->publish($announcement);

                if ($result->status === Announcement::STATUS_PUBLISHED) {
                    $published++;
                }
            } catch (\Throwable) {
                $this->error("Announcement {$announcement->getKey()} could not be published.");
            }
        }

        $this->info("Published {$published} scheduled announcements.");

        return self::SUCCESS;
    }
}
