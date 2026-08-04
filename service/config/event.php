<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

/**
 * Event listener registration — map event class names to listener arrays.
 *
 * Events drive the async side-effects of core business operations:
 *   OrderPaid      → triggers VM provisioning, disk creation, IP allocation
 *   TicketCreated  → triggers auto-assignment to least-loaded support staff
 *
 * Format:
 *   return [
 *       EventClass::class => [
 *           ListenerClass::class,
 *           // Additional listeners fire in listed order
 *       ],
 *   ];
 *
 * Listeners must implement a handle(EventClass $event): void method.
 */

return [
    // 支付成功后触发资源交付流程 + WebSocket 实时推送
    \App\Payment\Event\OrderPaid::class => [
        \App\Provisioning\Listener\OrderPaidListener::class,
        \App\WebSocket\Listener\OrderPaidListener::class,
        \App\Affiliate\Listener\OrderPaidListener::class,
    ],
    // 工单创建后自动分配 + WebSocket 实时推送
    \App\Ticket\Event\TicketCreated::class => [
        \App\Ticket\Listener\AutoAssignListener::class,
        \App\WebSocket\Listener\TicketUpdatedListener::class,
    ],
];
