<?php
namespace App\Admin\Controller;

use Common\Helper\Response;
use Common\Webhook\WebhookDispatcher;

class WebhookController
{
    public function index()
    {
        return json(Response::success(WebhookDispatcher::list()));
    }

    public function store($request)
    {
        $url = $request->input('url');
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return json(Response::error(422, 'Valid URL required'));
        }
        WebhookDispatcher::register($url);
        return json(Response::success(null, 'Webhook registered'));
    }

    public function destroy($request)
    {
        $url = $request->input('url');
        WebhookDispatcher::unregister($url);
        return json(Response::success(null, 'Webhook removed'));
    }

    public function test($request)
    {
        $url = $request->input('url');
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return json(Response::error(422, 'Valid URL required'));
        }
        try {
            WebhookDispatcher::dispatchTo($url, 'test.ping', ['message' => 'Webhook test from CloudPlatform']);
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
        return json(Response::success(null, 'Test webhook sent'));
    }
}
