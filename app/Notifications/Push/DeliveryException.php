<?php

namespace App\Notifications\Push;

use RuntimeException;

class DeliveryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode = 'delivery_error')
    {
        parent::__construct('Notification delivery failed.');
    }
}
