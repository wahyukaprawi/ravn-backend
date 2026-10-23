<?php

namespace App\Notifications;

use App\Events\OutboxMessageDispatched;

class CreateNotificationsFromOutbox
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(OutboxMessageDispatched $event): void
    {
        $this->dispatcher->dispatch($event->message);
    }
}
