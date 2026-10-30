<?php
namespace App\user\controller;

use App\user\service\OAuthService;
use Common\feature\FeatureFlags;
use Common\helper\ClientContext;
use Common\helper\Response;

class OAuthController
{
    private OAuthService $oauth;

    public function __construct()
    {
        $this->oauth = new OAuthService();
    }

    public function oauthRedirect($request, string $provider)
    {
        if (!FeatureFlags::isEnabled("{$provider}_oauth")) {
            return json(Response::error(403, 'OAuth login is disabled'));
        }
        try {
            $url = $this->oauth->authorizeUrl($provider);
            return json(Response::success(['url' => $url]));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    public function oauthCallback($request, string $provider)
    {
        if (!FeatureFlags::isEnabled("{$provider}_oauth")) {
            return json(Response::error(403, 'OAuth login is disabled'));
        }
        try {
            $tokens = $this->oauth->completeLogin(
                $provider,
                (string) $request->input('code'),
                (string) $request->input('state'),
                ClientContext::deviceFingerprint($request),
                ClientContext::clientPlatform($request),
                $request->header('Accept-Language', 'en-US')
            );
            return json(Response::success($tokens));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        } catch (\RuntimeException $e) {
            return json(Response::error(401, $e->getMessage()));
        }
    }
}
