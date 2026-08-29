<?php
namespace App\cdn\provider;

class CdnAdapterException extends \RuntimeException
{
    public const REASON_CREDENTIAL = 'credential_missing';
    public const REASON_ICP = 'icp_required';

    public function __construct(string $message, public readonly string $reason = '')
    {
        parent::__construct($message);
    }

    /** 阿里云/腾讯云拒绝未备案域名的错误特征（Code 含 ICP 或消息含 icp/备案） */
    public static function icpReason(string $code, string $message): string
    {
        if (stripos($code, 'ICP') !== false || stripos($message, 'icp') !== false || strpos($message, '备案') !== false) {
            return self::REASON_ICP;
        }
        return '';
    }
}
