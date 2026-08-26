<?php
namespace Common\captcha;

use Erikwang2013\Poster\PosterConfig;

class CaptchaService
{
    private static bool $configLoaded = false;

    private static function loadConfig(): void
    {
        if (!self::$configLoaded) {
            PosterConfig::load(config_path() . '/poster.php');
            self::$configLoaded = true;
        }
    }

    public static function create(string $difficulty = ''): array
    {
        self::loadConfig();

        if (empty($difficulty)) {
            $difficulty = PosterConfig::get('captcha.default_difficulty', 'medium');
        }

        $result = captcha_create('click', ['difficulty' => $difficulty]);

        return [
            'key'          => $result['key'],
            'image'        => $result['image'],
            'target_count' => count($result['extra']['texts'] ?? []),
            'expires_in'   => PosterConfig::get('captcha.ttl', 300),
        ];
    }

    public static function verify(string $key, array $points): bool
    {
        self::loadConfig();
        return captcha_verify($key, 'click', $points);
    }
}
