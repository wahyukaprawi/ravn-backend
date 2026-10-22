<?php

namespace App\Console\Commands;

use App\Events\OutboxMessageDispatched;
use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessOutbox extends Command
{
    protected $signature = 'outbox:process {--once : Process available messages once} {--batch=50 : Number of messages to claim per run} {--max-attempts=8 : Maximum attempts before marking failed}';

    protected $description = 'Process transactional outbox messages';

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $workerId = gethostname().'::'.getmypid();

        $processed = 0;

        while (true) {
            $messages = $this->claim($batch, $workerId);

            if ($messages->isEmpty()) {
                break;
            }

            foreach ($messages as $message) {
                $this->processMessage($message, $maxAttempts);
                $processed++;
            }

            if ($this->option('once')) {
                break;
            }
        }

        $this->info("Processed {$processed} outbox messages.");

        return self::SUCCESS;
    }

    protected function claim(int $batch, string $workerId)
    {
        $now = now();

        return DB::transaction(function () use ($batch, $workerId, $now) {
            $ids = OutboxMessage::query()
                ->where(function ($query) use ($now) {
                    $query->where('status', OutboxMessage::STATUS_PENDING)
                        ->orWhere(function ($q) use ($now) {
                            $q->where('status', OutboxMessage::STATUS_PROCESSING)
                                ->where(function ($q2) use ($now) {
                                    $q2->whereNull('locked_at')
                                        ->orWhere('locked_at', '<', $now->copy()->subMinutes(5));
                                });
                        });
                })
                ->where('available_at', '<=', $now)
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($batch)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return collect();
            }

            OutboxMessage::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => OutboxMessage::STATUS_PROCESSING,
                    'locked_at' => $now,
                    'locked_by' => $workerId,
                    'attempts' => DB::raw('attempts + 1'),
                ]);

            return OutboxMessage::query()->whereIn('id', $ids)->get();
        });
    }

    protected function processMessage(OutboxMessage $message, int $maxAttempts): void
    {
        try {
            $this->dispatchEvent($message);

            $message->forceFill([
                'status' => OutboxMessage::STATUS_PUBLISHED,
                'processed_at' => now(),
                'last_error' => null,
                'locked_at' => null,
                'locked_by' => null,
            ])->save();
        } catch (\Throwable $e) {
            $attempts = $message->attempts;

            if ($attempts >= $maxAttempts) {
                $message->forceFill([
                    'status' => OutboxMessage::STATUS_FAILED,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'locked_at' => null,
                    'locked_by' => null,
                    'processed_at' => now(),
                ])->save();

                return;
            }

            $delay = min(300, (int) pow(2, $attempts) * 30);

            $message->forceFill([
                'status' => OutboxMessage::STATUS_PENDING,
                'available_at' => now()->addSeconds($delay),
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'locked_at' => null,
                'locked_by' => null,
            ])->save();
        }
    }

    protected function dispatchEvent(OutboxMessage $message): void
    {
        event(new OutboxMessageDispatched($message));
    }
}
