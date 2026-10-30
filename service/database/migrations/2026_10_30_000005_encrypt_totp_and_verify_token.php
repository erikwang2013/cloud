<?php

use Erikwang2013\Encryptable\Encryption;
use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

/**
 * users.totp_secret / users.email_verify_token 改为 Encryptable 加密列（模型 cast）：
 * 存量明文需回写为确定性密文，否则旧邮箱验证链接按密文查询永不命中、旧 TOTP 密钥继续明文落库。
 * decrypt 非 strict 模式对已加密值与明文都幂等（明文原样返回），可重复执行。
 */
return new class extends Migration {
    private const COLUMNS = ['totp_secret', 'email_verify_token'];

    public function up(): void
    {
        $this->transform(fn (string $value): ?string => Encryption::php()->encrypt($value));
    }

    public function down(): void
    {
        $this->transform(fn (string $value): mixed => Encryption::php()->decrypt($value));
    }

    private function transform(callable $fn): void
    {
        foreach (self::COLUMNS as $column) {
            Capsule::table('users')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->chunkById(500, function ($rows) use ($column, $fn) {
                    foreach ($rows as $row) {
                        Capsule::table('users')->where('id', $row->id)->update([
                            $column => $fn($row->{$column}),
                        ]);
                    }
                });
        }
    }
};
