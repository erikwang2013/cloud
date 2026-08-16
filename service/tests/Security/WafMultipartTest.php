<?php

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WafTestSupport.php';

/**
 * multipart/form-data 下 raw body 扫描行为：跳过（误报/内存），解析字段照常扫描。
 */
final class WafMultipartTest extends TestCase
{
    private TestableWafMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new TestableWafMiddleware();
    }

    private function decodeResponse($response): array
    {
        if (is_string($response)) return json_decode($response, true) ?? [];
        if (method_exists($response, 'rawBody')) return json_decode($response->rawBody(), true) ?? [];
        return [];
    }

    public function testMultipartSkipsRawBodyScan(): void
    {
        // 二进制/文本文件内容命中 WAF 模式，但 multipart 跳过 raw 扫描 → 不误报
        $this->middleware->setRawBody("--boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a.txt\"\r\n\r\nbackup 127.0.0.1 union select\r\n--boundary--");
        $req = WafTestSupport::createRequest('/api/upload', ['filename' => 'a.txt'], 'Mozilla/5.0', '', 'multipart/form-data; boundary=boundary');
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testMultipartStillScansParsedFields(): void
    {
        // multipart 解析后的字段照常扫描 → 恶意字段值仍被拦截
        $req = WafTestSupport::createRequest('/api/upload', ['note' => '<script>alert(1)</script>'], 'Mozilla/5.0', '', 'multipart/form-data; boundary=boundary');
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testJsonStillScansRawBody(): void
    {
        // json 场景保留 raw 扫描：raw 命中而解析字段为空的攻击仍被拦截
        $this->middleware->setRawBody('{"q":"1\' UNION SELECT * FROM users"}');
        $req = WafTestSupport::createRequest('/api/products', [], 'Mozilla/5.0', '', 'application/json');
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testMultipartRawBodyNotScannedButFormUrlencodedIs(): void
    {
        // urlencoded 仍扫描 raw（百分号编码载荷需 raw 命中），multipart 不读 raw
        $this->middleware->setRawBody('q=%27%20UNION%20SELECT%20*%20FROM%20users');
        $req = WafTestSupport::createRequest('/api/products', [], 'Mozilla/5.0', '', 'application/x-www-form-urlencoded');
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }
}
