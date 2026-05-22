<?php
namespace Common\I18n\Middleware;

use Common\I18n\I18n;

class LocaleMiddleware
{
    public function process($request, callable $next)
    {
        $locale = $request->header('Accept-Language', config('i18n.default_locale') ?: 'en-US');
        $primary = explode(',', $locale)[0];
        $primary = explode(';', $primary)[0];
        I18n::setLocale(trim($primary));

        return $next($request);
    }
}
