<?php

namespace App\Notifications\Push;

use App\Models\DeviceToken;

interface PushGateway
{
    /**
     * @param  array{title: string, body: string, data: array<string, string>}  $payload
     */
    public function send(DeviceToken $device, array $payload): void;
}
