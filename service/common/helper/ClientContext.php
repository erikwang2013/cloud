<?php
namespace Common\helper;

/**
 * 请求侧客户端上下文：设备指纹需在密码登录、OAuth 登录、refresh 轮换间保持一致
 * （refresh token 按指纹绑定），故集中一处，避免各控制器各算一套。
 */
class ClientContext
{
    public static function deviceFingerprint($request): string
    {
        $ua = $request->header('User-Agent', '');
        $ip = $request->getRealIp();
        $ipCidr = str_contains($ip, ':')
            ? substr($ip, 0, strpos($ip, ':')) . ':'
            : substr($ip, 0, (int) strrpos($ip, '.'));
        return hash('sha256', $ua . $ipCidr);
    }

    public static function clientPlatform($request): string
    {
        return $request->properties['client_platform'] ?? 'unknown';
    }
}
