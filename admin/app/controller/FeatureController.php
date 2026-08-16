<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\controller;

use support\Request;
use support\Response;

/**
 * 功能开关（Feature Flags）
 *
 * Flags 定义在 service/config/features.php，运行时开关存 Redis（TTL 1h，
 * 与 service 端 Common\Feature\FeatureFlags 的读取优先级一致）。
 */
class FeatureController extends Base
{
    private const REDIS_PREFIX = 'feature:';
    private const TTL = 3600;

    public function index(): Response
    {
        return raw_view('feature/index');
    }

    public function select(Request $request): Response
    {
        $items = [];
        foreach ($this->flags() as $name => $default) {
            $items[] = [
                'name'    => $name,
                'default' => (bool) $default,
                'enabled' => $this->redisGet($name, (bool) $default),
                'source'  => $this->source($name),
            ];
        }
        return json(['code' => 0, 'msg' => 'ok', 'count' => count($items), 'data' => $items]);
    }

    public function toggle(Request $request): Response
    {
        $name   = (string) $request->post('name', '');
        $action = (string) $request->post('action', 'toggle');

        $flags = $this->flags();
        if (!array_key_exists($name, $flags)) {
            return $this->fail('未知的功能开关');
        }

        try {
            $redis = $this->redis();
            $key   = self::REDIS_PREFIX . $name;

            switch ($action) {
                case 'enable':
                    $redis->setex($key, self::TTL, '1');
                    break;
                case 'disable':
                    $redis->setex($key, self::TTL, '0');
                    break;
                case 'reset':
                    $redis->del($key);
                    break;
                default:
                    $enabled = $redis->get($key);
                    $enabled = $enabled === null ? (bool) $flags[$name] : $enabled === '1';
                    $redis->setex($key, self::TTL, $enabled ? '0' : '1');
            }
            $redis->close();
        } catch (\Exception $e) {
            return $this->fail('Redis 不可用，操作失败');
        }

        return $this->success('操作成功', [
            'name'    => $name,
            'enabled' => $this->redisGet($name, (bool) $flags[$name]),
            'source'  => $this->source($name),
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function flags(): array
    {
        $file = base_path() . '/../service/config/features.php';
        return is_file($file) ? (require $file) : [];
    }

    private function redis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(
            getenv('REDIS_HOST') ?: '127.0.0.1',
            (int) (getenv('REDIS_PORT') ?: 6379)
        );
        if (getenv('REDIS_PASSWORD')) {
            $redis->auth(getenv('REDIS_PASSWORD'));
        }
        return $redis;
    }

    private function redisGet(string $name, bool $default): bool
    {
        try {
            $value = $this->redis()->get(self::REDIS_PREFIX . $name);
            return $value === null ? $default : $value === '1';
        } catch (\Exception $e) {
            return $default;
        }
    }

    private function source(string $name): string
    {
        try {
            if ($this->redis()->get(self::REDIS_PREFIX . $name) !== null) {
                return 'redis';
            }
        } catch (\Exception $e) {
        }
        if (getenv('FEATURE_' . strtoupper($name)) !== false) {
            return 'env';
        }
        return 'config';
    }
}
