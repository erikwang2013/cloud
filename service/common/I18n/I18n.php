<?php
namespace Common\I18n;

class I18n
{
    private static string $locale = 'en-US';
    private static array $messages = [];

    public static function setLocale(string $locale): void
    {
        $supported = config('i18n.supported_locales') ?: ['en-US', 'zh-CN'];
        if (in_array($locale, $supported)) {
            self::$locale = $locale;
        } else {
            $map = config('i18n.locale_map') ?: [];
            self::$locale = $map[$locale] ?? (config('i18n.fallback_locale') ?: 'en-US');
        }
        self::loadMessages();
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    public static function trans(string $key, array $replace = []): string
    {
        $message = self::$messages[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $message = str_replace(":{$k}", $v, $message);
        }
        return $message;
    }

    public static function transExists(string $key): bool
    {
        return array_key_exists($key, self::$messages);
    }

    public static function getKeys(): array
    {
        return array_keys(self::$messages);
    }

    private static function loadMessages(): void
    {
        self::$messages = [];
        $dir = base_path() . '/i18n/' . self::$locale;

        if (!is_dir($dir)) return;

        $files = glob($dir . '/*.php');
        sort($files);

        foreach ($files as $file) {
            $loaded = require $file;
            if (is_array($loaded)) {
                self::$messages = array_merge(self::$messages, $loaded);
            }
        }
    }

    public static function translateField(?array $jsonValue): ?string
    {
        if (empty($jsonValue)) return null;
        return $jsonValue[self::$locale]
            ?? $jsonValue[config('i18n.fallback_locale') ?: 'en-US']
            ?? array_values($jsonValue)[0];
    }
}
