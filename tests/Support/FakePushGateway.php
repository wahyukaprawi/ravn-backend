<?php

namespace Tests\Support;

use App\Models\DeviceToken;
use App\Notifications\Push\PushGateway;
use Throwable;

class FakePushGateway implements PushGateway
{
    /**
     * @var list<array{device_id: int, payload: array<string, mixed>}>
     */
    public array $sent = [];

    public int $calls = 0;

    public ?Throwable $failure = null;

    public function send(DeviceToken $device, array $payload): void
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->sent[] = [
            'device_id' => $device->getKey(),
            'payload' => $payload,
        ];
    }
}
