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
    // admin 项目无事件监听器（此前错误引用 service 项目的 \App\ 命名空间类，已清空）
];
