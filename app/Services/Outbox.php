<?php

namespace App\Services;

use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;

class Outbox
{
    public function dispatch(string $eventType, array $payload, ?string $aggregateType = null, ?int $aggregateId = null, ?\DateTimeInterface $availableAt = null): OutboxMessage
    {
        return DB::transaction(function () use ($eventType, $payload, $aggregateType, $aggregateId, $availableAt) {
            return OutboxMessage::query()->create([
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => $payload,
                'status' => OutboxMessage::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => $availableAt ?? now(),
                'created_at' => now(),
            ]);
        });
    }

    public function dispatchAfterCommit(string $eventType, array $payload, ?string $aggregateType = null, ?int $aggregateId = null): void
    {
        $connection = DB::connection();

        $connection->transaction(function () use ($eventType, $payload, $aggregateType, $aggregateId) {
            $this->dispatch($eventType, $payload, $aggregateType, $aggregateId);
        });
    }
}
