<?php
namespace Common\i18n;

class I18n
{
    private static string $locale = 'en-US';
    private static array $messages = [];
    private static array $fallback = [];
    // 按 locale 缓存已加载的语言包，避免每个请求重复 glob/require/merge
    private static array $loaded = [];

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
        $message = self::$messages[$key] ?? self::$fallback[$key] ?? $key;
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
        if (isset(self::$loaded[self::$locale])) {
            self::$messages = self::$loaded[self::$locale]['messages'];
            self::$fallback = self::$loaded[self::$locale]['fallback'];
            return;
        }

        self::$messages = [];
        self::$fallback = [];
        $dir = base_path() . '/i18n/' . self::$locale;

        if (is_dir($dir)) {
            foreach (glob($dir . '/*.php') as $file) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    self::$messages = array_merge(self::$messages, $loaded);
                }
            }
        }

        $fallbackLocale = config('i18n.fallback_locale') ?: 'en-US';
        if ($fallbackLocale === self::$locale) return;

        $dir = base_path() . '/i18n/' . $fallbackLocale;
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*.php') as $file) {
            $loaded = require $file;
            if (is_array($loaded)) {
                self::$fallback = array_merge(self::$fallback, $loaded);
            }
        }

        self::$loaded[self::$locale] = ['messages' => self::$messages, 'fallback' => self::$fallback];
    }

    public static function translateField(?array $jsonValue): ?string
    {
        if (empty($jsonValue)) return null;
        return $jsonValue[self::$locale]
            ?? $jsonValue[config('i18n.fallback_locale') ?: 'en-US']
            ?? array_values($jsonValue)[0];
    }
}
