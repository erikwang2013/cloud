<?php

namespace Tests\WebSocket;

use PHPUnit\Framework\TestCase;

final class WebSocketAuthTest extends TestCase
{
    private string $server;

    protected function setUp(): void
    {
        $this->server = (string) file_get_contents(__DIR__ . '/../../app/WebSocket/WebSocketServer.php');
    }

    public function testTokenNoLongerReadFromQueryString(): void
    {
        $this->assertStringNotContainsString('queryString', $this->server);
        $this->assertStringNotContainsString('parse_str', $this->server);
    }

    public function testAuthHappensOnFirstMessage(): void
    {
        $this->assertStringContainsString("'auth'", $this->server);
        $this->assertStringContainsString('authenticateConnection', $this->server);
        // 未认证连接不响应心跳，只处理认证
        $this->assertStringContainsString('empty($connection->userId)', $this->server);
    }

    public function testDocsNoLongerDocumentTokenInUrl(): void
    {
        $docs = (string) file_get_contents(__DIR__ . '/../../../docs/api-reference.md');
        $this->assertStringNotContainsString('ws://host:8282?token=', $docs);
        $this->assertStringNotContainsString('ws://host/ws/?token=', $docs);
        $this->assertStringContainsString('"type": "auth"', $docs);
    }
}
