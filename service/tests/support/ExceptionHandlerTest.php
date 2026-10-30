<?php

namespace Tests\support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use support\exception\Handler;

final class ExceptionHandlerTest extends TestCase
{
    public function testInvalidArgumentExceptionRenders422Envelope(): void
    {
        $handler = new Handler($this->createMock(LoggerInterface::class), false);

        $response = $handler->render(new RequestMock(), new \InvalidArgumentException('Invalid ID'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->rawBody(), true);
        $this->assertSame(422, $body['code']);
        $this->assertSame('Invalid ID', $body['message']);
    }

    public function testInvalidArgumentExceptionIsStillReportedToLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        // 不能加入 dontReport：编程错误产生的 InvalidArgumentException 必须进日志
        $logger->expects($this->once())->method('error')->with($this->stringContains('Invalid ID'));

        (new Handler($logger, false))->report(new \InvalidArgumentException('Invalid ID'));
    }

    public function testModelNotFoundExceptionStillRenders404(): void
    {
        $handler = new Handler($this->createMock(LoggerInterface::class), false);

        $response = $handler->render(new RequestMock(), new ModelNotFoundException('missing'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(404, json_decode($response->rawBody(), true)['code']);
    }
}
