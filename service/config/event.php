<?php

return [
    \App\Payment\Event\OrderPaid::class => [
        \App\Provisioning\Listener\OrderPaidListener::class,
    ],
    \App\Ticket\Event\TicketCreated::class => [
        \App\Ticket\Listener\AutoAssignListener::class,
    ],
];
