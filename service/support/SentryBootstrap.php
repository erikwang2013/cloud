<?php
namespace support;

use Common\Security\LogSanitizer;
use Webman\Bootstrap;

class SentryBootstrap implements Bootstrap
{
    public static function start($worker)
    {
        $config = config('sentry');
        if (empty($config['dsn'])) {
            return;
        }

        \Sentry\init([
            'dsn'                  => $config['dsn'],
            'environment'          => $config['environment'],
            'release'              => $config['release'],
            'traces_sample_rate'   => $config['traces_sample_rate'],
            'profiles_sample_rate' => $config['profiles_sample_rate'],
            'error_types'          => $config['error_types'],

            // Sanitize sensitive data before sending to Sentry
            'before_send'          => function (\Sentry\Event $event) {
                $request = $event->getRequest();
                if ($request && !empty($request['data'])) {
                    $request['data'] = LogSanitizer::sanitize($request['data']);
                    $event->setRequest($request);
                }
                return $event;
            },

            'integrations' => [
                new \Sentry\Integration\RequestIntegration(),
            ],

            'attach_stacktrace' => true,
            'send_default_pii'  => false, // Never send user PII by default
        ]);
    }

    private static function sanitizeRequest(?array $request): ?array
    {
        if (!$request || empty($request['data'])) {
            return $request;
        }
        $request['data'] = LogSanitizer::sanitize($request['data']);
        return $request;
    }
}
