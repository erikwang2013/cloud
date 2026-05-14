<?php
namespace Common\Encryption\Middleware;

use Common\Encryption\EncryptionService;
use Webman\Http\Response;

class EncryptionMiddleware
{
    public function process($request, callable $next)
    {
        $encrypted = $request->header('X-Encrypted');

        if ($encrypted) {
            $body = json_decode($request->rawBody(), true);
            if (is_array($body) && isset($body['payload'])) {
                try {
                    $plaintext = EncryptionService::decrypt(base64_decode($body['payload']));
                    $data = json_decode($plaintext, true);
                    if (is_array($data)) {
                        foreach ($data as $key => $value) {
                            $request->{$key} = $value;
                        }
                    }
                    $request->encrypted = true;
                } catch (\Exception $e) {
                    return json([
                        'code'    => 400,
                        'message' => 'Decryption failed',
                        'data'    => null,
                        'request_id' => request_id(),
                    ]);
                }
            }
        }

        $response = $next($request);

        if (!empty($request->encrypted) && $response instanceof Response) {
            $plainBody = (string)$response->rawBody();
            $encryptedBody = EncryptionService::encrypt($plainBody);
            return json([
                'payload'    => base64_encode($encryptedBody),
                'request_id' => request_id(),
            ]);
        }

        return $response;
    }
}
