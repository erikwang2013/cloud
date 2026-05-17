<?php
namespace App\User\Controller;

use App\User\Service\AuthService;
use Common\Captcha\CaptchaService;
use Common\Helper\Response;
use Common\Helper\Validator;
use Common\I18n\I18n;
use Common\Security\AuditLogger;

class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function register($request)
    {
        $data = $request->all();
        if (empty($data['password']) || (empty($data['email']) && empty($data['phone']))) {
            return json(Response::error(422, 'Email or phone required, and password required'));
        }

        if (!$this->verifyCaptcha($request)) {
            return json(Response::error(422, 'Captcha verification failed'));
        }

        if (!empty($data['email']) && !Validator::email($data['email'])) {
            return json(Response::error(422, 'Invalid email format'));
        }
        if (!Validator::minLength($data['password'], 8)) {
            return json(Response::error(422, 'Password must be at least 8 characters'));
        }

        try {
            $tokens = $this->auth->register($data);
            AuditLogger::record('user_registered', ['user_id' => null], $request);
            return json(Response::success($tokens, I18n::trans('auth.register_success')));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    public function login($request)
    {
        $login    = $request->input('login');
        $password = $request->input('password');
        $deviceFp = $this->deviceFingerprint($request);

        if (empty($login) || empty($password)) {
            return json(Response::error(422, 'Login and password required'));
        }

        if (!$this->verifyCaptcha($request)) {
            return json(Response::error(422, 'Captcha verification failed'));
        }

        try {
            $tokens = $this->auth->login($login, $password, $deviceFp);
            $payload = (new \Common\Auth\JwtAuth())->verify($tokens['access_token']);
            AuditLogger::record('user_login', ['user_id' => $payload['sub'] ?? null], $request);
            return json(Response::success($tokens, I18n::trans('auth.login_success')));
        } catch (\InvalidArgumentException $e) {
            $this->auth->recordFailedLogin($login);
            AuditLogger::record('login_failed', ['input' => ['login_hash' => sha1($login)]], $request);
            return json(Response::error(401, $e->getMessage()));
        }
    }

    public function refresh($request)
    {
        $refreshToken = $request->input('refresh_token');
        $deviceFp     = $this->deviceFingerprint($request);

        try {
            $tokens = $this->auth->refreshToken($refreshToken, $deviceFp);
            return json(Response::success($tokens));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(401, $e->getMessage()));
        }
    }

    private function verifyCaptcha($request): bool
    {
        $key    = $request->input('captcha_key', '');
        $points = $request->input('captcha_points', []);

        if (empty($key) || !is_array($points) || empty($points)) {
            return false;
        }

        return CaptchaService::verify($key, $points);
    }

    private function deviceFingerprint($request): string
    {
        $ua     = $request->header('User-Agent', '');
        $ip     = $request->getRealIp();
        $ipCidr = substr($ip, 0, (int) strrpos($ip, '.'));
        return hash('sha256', $ua . $ipCidr);
    }
}
