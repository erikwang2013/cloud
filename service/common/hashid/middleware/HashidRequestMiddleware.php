<?php
namespace Common\hashid\middleware;

use Common\hashid\HashidService;

class HashidRequestMiddleware
{
    public function process($request, callable $next)
    {
        self::decodeRequest($request);
        return $next($request);
    }

    /**
     * 解码请求 get/post 中的 hashid。加解密中间件解密后会再次调用（全局中间件先于路由中间件执行）。
     */
    public static function decodeRequest($request): void
    {
        $rawInputs = $request->all();
        if ($rawInputs) {
            $request->rawInputs = $rawInputs;
        }
        // 写回 get/post 数组，all()/input()/only() 才能读到解码后的值
        $request->setGet(self::decodeInputs($request->get()));
        $request->setPost(self::decodeInputs($request->post()));
    }

    /**
     * 解码 id / *_id / *_ids 键（与 admin Crud 规则一致），数组值逐个解码。
     */
    private static function decodeInputs(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::decodeInputs($value);
                if (self::isIdKey($key)) {
                    foreach ($data[$key] as $index => $item) {
                        if (is_string($item) && !is_numeric($item)) {
                            $data[$key][$index] = HashidService::decode($item) ?? $item;
                        }
                    }
                }
            } elseif (self::isIdKey($key) && is_string($value) && !is_numeric($value)) {
                $data[$key] = HashidService::decode($value) ?? $value;
            }
        }
        return $data;
    }

    private static function isIdKey(string $key): bool
    {
        return $key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids');
    }
}
