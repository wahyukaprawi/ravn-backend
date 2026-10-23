<?php

namespace App\Notifications\Push;

class RateLimitedException extends DeliveryException
{
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct('rate_limited');
    }
}
