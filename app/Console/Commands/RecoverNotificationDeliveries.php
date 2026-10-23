<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotification;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

class RecoverNotificationDeliveries extends Command
{
    protected $signature = 'notifications:recover-deliveries {--minutes= : Age threshold in minutes}';

    protected $description = 'Re-queue notification deliveries stuck in processing';

    public function handle(): int
    {
        $minutes = max(1, (int) ($this->option('minutes') ?: config('notifications.recovery_minutes', 15)));
        $cutoff = now()->subMinutes($minutes);

        $deliveries = NotificationDelivery::query()
            ->where('status', NotificationDelivery::STATUS_PROCESSING)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        foreach ($deliveries as $delivery) {
            $delivery->forceFill([
                'status' => NotificationDelivery::STATUS_PENDING,
                'available_at' => now(),
                'last_error_code' => 'worker_recovered',
                'last_error' => 'The worker stopped before finishing the delivery.',
            ])->save();

            DeliverNotification::dispatch($delivery->getKey());
        }

        $this->info("Recovered {$deliveries->count()} notification deliveries.");

        return self::SUCCESS;
    }
}
