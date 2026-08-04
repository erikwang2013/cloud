<?php
namespace Common\Security;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class InternalTokenMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $token = getenv('INTERNAL_MONITOR_TOKEN');

        if ($token && $request->header('X-Internal-Token') === $token) {
            return $handler($request);
        }

        if ($this->isLoopback($request)) {
            return $handler($request);
        }

        return new Response(403, [], json_encode([
            'error' => 'Forbidden',
            'message' => 'Internal endpoint requires valid token or loopback IP',
        ]));
    }

    private function isLoopback(Request $request): bool
    {
        $ip = $request->getRealIp();
        return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
    }
}
