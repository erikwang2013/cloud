<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests\Support;

/**
 * RequestMock with a settable HTTP method, so GET-view branches of
 * Crud controllers (insert/update show a form unless POST) can be tested.
 */
final class HttpRequestMock extends RequestMock
{
    private string $httpMethod;

    public function __construct(array $getParams = [], array $postParams = [], string $method = 'GET')
    {
        parent::__construct($getParams, $postParams);
        $this->httpMethod = $method;
    }

    public function method(): string
    {
        return $this->httpMethod;
    }
}
