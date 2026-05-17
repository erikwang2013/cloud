<?php
namespace App\Captcha\Controller;

use Common\Captcha\CaptchaService;
use Common\Helper\Response;

class CaptchaController
{
    public function create(): \Webman\Http\Response
    {
        try {
            $captcha = CaptchaService::create();
            return json(Response::success($captcha));
        } catch (\Throwable $e) {
            return json(Response::error(500, 'Captcha generation failed'));
        }
    }
}
