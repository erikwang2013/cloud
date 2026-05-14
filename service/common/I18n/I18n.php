<?php
namespace Common\I18n;

class I18n
{
    private static string $locale = 'en-US';
    private static array $messages = [];

    public static function setLocale(string $locale): void
    {
        $supported = config('i18n.supported_locales');
        if (in_array($locale, $supported)) {
            self::$locale = $locale;
        } else {
            $map = config('i18n.locale_map');
            self::$locale = $map[$locale] ?? config('i18n.fallback_locale');
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

    private static function loadMessages(): void
    {
        $path = base_path() . "/i18n/" . self::$locale . "/messages.php";
        if (file_exists($path)) {
            self::$messages = require $path;
        }
    }

    public static function translateField(?array $jsonValue): ?string
    {
        if (empty($jsonValue)) return null;
        return $jsonValue[self::$locale]
            ?? $jsonValue[config('i18n.fallback_locale')]
            ?? array_values($jsonValue)[0];
    }
}
