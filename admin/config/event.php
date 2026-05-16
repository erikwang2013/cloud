<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

/**
 * Event listener registration — map event class names to listener arrays.
 *
 * Example:
 *   return [
 *       SomeEvent::class => [
 *           SomeListener::class,
 *       ],
 *   ];
 *
 * Leave empty when no event listeners are registered.
 */
return [
    // 支付成功后触发资源交付流程（创建云服务器/磁盘/IP等）
    \App\Payment\Event\OrderPaid::class => [
        \App\Provisioning\Listener\OrderPaidListener::class,
    ],
    // 工单创建后自动分配给负载最少的客服人员
    \App\Ticket\Event\TicketCreated::class => [
        \App\Ticket\Listener\AutoAssignListener::class,
    ],
];
