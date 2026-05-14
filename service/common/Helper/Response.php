<?php
namespace Common\Helper;

use Common\Hashid\HashidService;

class Response
{
    public static function success($data = null, string $message = 'ok', array $meta = []): array
    {
        $body = [
            'code'    => 0,
            'message' => $message,
            'data'    => $data !== null ? HashidService::encodeIds($data) : null,
            'request_id' => request_id(),
        ];
        if ($meta) {
            $body['meta'] = $meta;
        }
        return $body;
    }

    public static function error(int $code, string $message, $data = null): array
    {
        return [
            'code'       => $code,
            'message'    => $message,
            'data'       => $data,
            'request_id' => request_id(),
        ];
    }

    public static function paginated($items, int $total, int $page, int $pageSize): array
    {
        return self::success($items, 'ok', [
            'page'      => $page,
            'page_size' => $pageSize,
            'total'     => $total,
        ]);
    }
}
