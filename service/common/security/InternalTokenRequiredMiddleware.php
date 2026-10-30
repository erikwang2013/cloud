<?php
namespace Common\security;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 强制令牌鉴权，不提供 loopback 旁路。
 * nginx 全量反代下公网请求的 remote IP 均为 127.0.0.1，loopback 放行等同无鉴权。
 */
class InternalTokenRequiredMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $token = (string) getenv('INTERNAL_MONITOR_TOKEN');
        $provided = (string) $request->header('X-Internal-Token', '');

        if ($token !== '' && $provided !== '' && hash_equals($token, $provided)) {
            return $handler($request);
        }

        return new Response(403, [], json_encode([
            'error' => 'Forbidden',
            'message' => 'Internal endpoint requires valid token',
        ]));
    }
}
