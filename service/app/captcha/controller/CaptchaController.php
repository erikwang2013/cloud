<?php
namespace App\captcha\controller;

use Common\captcha\CaptchaService;
use Common\helper\Response;

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
