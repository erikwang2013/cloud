<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\middleware\AccessControl;
use PHPUnit\Framework\TestCase;
use plugin\admin\api\Auth;
use Webman\Context;
use Webman\Http\Request;
use Workerman\Protocols\Http\Session;

/**
 * Tests the permission-denial enforcement point: plugin Auth::canAccess and
 * the AccessControl middleware that turns a denial into 401/403 responses.
 *
 * Sessions are injected into the request context with an empty or role-less
 * admin, so no DB or real HTTP server is needed.
 */
final class AccessControlMiddlewareTest extends TestCase
{
    private function makeRequest(string $buffer = 'GET /x HTTP/1.1' . "\r\n" . 'Host: localhost' . "\r\n\r\n"): Request
    {
        return new Request($buffer);
    }

    private function withContext(Request $request): void
    {
        Context::reset(new \ArrayObject([Request::class => $request]));
    }

    private function injectSession(Request $request, ?array $admin): void
    {
        $session = new Session('test-sid-' . uniqid());
        if ($admin !== null) {
            $session->set('admin', $admin);
        }
        $request->context['session'] = $session;
    }

    public function testCanAccessAllowsNonControllerCalls(): void
    {
        $this->assertTrue(Auth::canAccess('', 'some_function'));
    }

    public function testCanAccessAllowsNoNeedLoginActionsWithoutSession(): void
    {
        $request = $this->makeRequest();
        $this->injectSession($request, null);
        $this->withContext($request);

        $this->assertTrue(Auth::canAccess(\app\controller\IndexController::class, 'index'));
    }

    public function testCanAccessRejectsWithoutLoginWith401(): void
    {
        $request = $this->makeRequest();
        $this->injectSession($request, null);
        $this->withContext($request);

        $code = 0;
        $msg = '';
        $this->assertFalse(Auth::canAccess(\app\controller\OrderController::class, 'select', $code, $msg));
        $this->assertSame(401, $code);
        $this->assertSame('请登录', $msg);
    }

    public function testCanAccessRejectsRolelessAdminWithPermissionCode(): void
    {
        $request = $this->makeRequest();
        $this->injectSession($request, ['id' => 1, 'roles' => [], 'nickname' => 'qa', 'session_last_update_time' => time()]);
        $this->withContext($request);

        $code = 0;
        $msg = '';
        $this->assertFalse(Auth::canAccess(\app\controller\OrderController::class, 'select', $code, $msg));
        $this->assertSame(2, $code);
        $this->assertSame('无权限', $msg);
    }

    public function testMiddlewareReturnsJson403ForJsonRequest(): void
    {
        $buffer = "GET /x HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n";
        $request = $this->makeRequest($buffer);
        $this->injectSession($request, ['id' => 1, 'roles' => [], 'nickname' => 'qa', 'session_last_update_time' => time()]);
        $this->withContext($request);
        $request->controller = \app\controller\OrderController::class;
        $request->action = 'select';

        $response = (new AccessControl())->process($request, fn () => new \support\Response(200, [], "unreachable"));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->rawBody(), true);
        $this->assertSame(2, $body['code']);
        $this->assertSame('无权限', $body['msg']);
    }

    public function testMiddlewareRedirectsToLoginForPageRequestWithoutSession(): void
    {
        $request = $this->makeRequest();
        $this->injectSession($request, null);
        $this->withContext($request);
        $request->controller = \app\controller\OrderController::class;
        $request->action = 'select';

        $response = (new AccessControl())->process($request, fn () => new \support\Response(200, [], "unreachable"));

        $this->assertStringContainsString("top.location.href = '/app/admin'", (string) $response->rawBody());
    }

    public function testMiddlewarePassesThroughForNoNeedLoginActions(): void
    {
        $request = $this->makeRequest();
        $this->injectSession($request, null);
        $this->withContext($request);
        $request->controller = \app\controller\IndexController::class;
        $request->action = 'index';

        $response = (new AccessControl())->process($request, fn () => new \support\Response(200, [], "ok"));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->rawBody());
    }
}
