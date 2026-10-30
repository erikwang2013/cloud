<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use support\Migration;

return new class extends Migration {
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Schema-tolerant: install.sql creates notification_templates with
        // (name, title JSON, body JSON, channels VARCHAR); migration 0009 created
        // an alternative shape (title_template/body_template JSON, channels JSON).
        $legacy = Capsule::schema()->hasColumn('notification_templates', 'title');
        $alt    = Capsule::schema()->hasColumn('notification_templates', 'title_template');

        foreach ($this->templates() as [$code, $name, $title, $body, $channels]) {
            $payload = ['name' => $name, 'created_at' => $now, 'updated_at' => $now];

            if ($legacy) {
                $payload['title']    = json_encode($title, JSON_UNESCAPED_UNICODE);
                $payload['body']     = json_encode($body, JSON_UNESCAPED_UNICODE);
                $payload['channels'] = $channels;
            } elseif ($alt) {
                $payload['title_template'] = json_encode($title, JSON_UNESCAPED_UNICODE);
                $payload['body_template']  = json_encode($body, JSON_UNESCAPED_UNICODE);
                $payload['channels']       = json_encode(explode(',', $channels));
            } else {
                throw new \RuntimeException('notification_templates: neither title nor title_template column found');
            }

            // install.sql 的 id 列无 AUTO_INCREMENT：legacy schema 首插必须显式提供 Snowflake 主键
            if ($legacy && !Capsule::table('notification_templates')->where('code', $code)->exists()) {
                $payload['id'] = \Common\snowflake\SnowflakeService::nextId();
            }

            Capsule::table('notification_templates')->updateOrInsert(['code' => $code], $payload);
        }
    }

    public function down(): void
    {
        Capsule::table('notification_templates')->whereIn('code', [
            'rating_received',
        ])->delete();
    }

    /**
     * [code, name, title(en/zh), body(en/zh), channels]
     */
    private function templates(): array
    {
        return [
            ['rating_received', 'New Supplier Rating', [
                'en' => 'New Supplier Rating',
                'zh' => '收到新评分',
            ], [
                'en' => 'You received a {{rating}}-star rating on your supplier profile.',
                'zh' => '您的供应商资料收到 {{rating}} 星评分。',
            ], 'in_app,email'],
        ];
    }
};
