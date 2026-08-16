<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests\Support;

use support\Request;

/**
 * A minimal Request stub that returns known GET/POST data without a server connection.
 */
class RequestMock extends Request
{
    private array $getParams;
    private array $postParams;

    public function __construct(array $getParams = [], array $postParams = [])
    {
        parent::__construct('');
        $this->getParams = $getParams;
        $this->postParams = $postParams;
    }

    /** @return mixed */
    public function get(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->getParams;
        }
        return $this->getParams[$name] ?? $default;
    }

    /** @return mixed */
    public function post(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->postParams;
        }
        return $this->postParams[$name] ?? $default;
    }
}
