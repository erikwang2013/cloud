<?php

namespace Tests\Support;

use support\Request;

class RequestMock extends Request
{
    private array $getParams;
    private array $postParams;

    public function __construct(array $getParams = [], array $postParams = [])
    {
        $this->getParams = $getParams;
        $this->postParams = $postParams;
        parent::__construct('');
    }

    public function get(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->getParams;
        }
        return $this->getParams[$name] ?? $default;
    }

    public function post(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->postParams;
        }
        return $this->postParams[$name] ?? $default;
    }
}
