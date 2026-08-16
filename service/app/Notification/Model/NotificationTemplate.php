<?php
namespace App\Notification\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class NotificationTemplate extends Model
{
    use HasSnowflakeId;
    protected $table = 'notification_templates';
    protected $fillable = [
        'code', 'name', 'title', 'body', 'channels',
        // 迁移 0009 的备选列名（admin 编辑按此写入），模型读取时回退兼容
        'title_template', 'body_template', 'variables',
    ];

    protected $casts = [
        'title' => 'array',
        'body'  => 'array',
    ];

    public function getLocalizedTitle(string $locale): string
    {
        return $this->resolveLocalized($this->localizedMap('title'), $locale);
    }

    public function getLocalizedBody(string $locale): string
    {
        return $this->resolveLocalized($this->localizedMap('body'), $locale);
    }

    /**
     * Locale resolution with short-code fallback, so 'zh-CN' matches the 'zh'
     * key, then falls back to the 'en' key.
     */
    private function resolveLocalized(array $map, string $locale): string
    {
        if (isset($map[$locale])) {
            return $map[$locale];
        }
        $short = explode('-', $locale)[0];
        return $map[$short] ?? $map['en'] ?? '';
    }

    /**
     * Resolve the localized (locale => text) map for a template field.
     *
     * Tolerant of two schema variants seen in this codebase:
     * - install.sql + model: columns `title` / `body` (JSON maps)
     * - migration 0009:      columns `title_template` / `body_template` (JSON maps)
     * and of legacy plain-string values (treated as English).
     */
    private function localizedMap(string $field): array
    {
        $alternatives = $field === 'title'
            ? ['title', 'title_template']
            : ['body', 'body_template'];

        foreach ($alternatives as $column) {
            $raw = $this->attributes[$column] ?? null;
            if (is_array($raw)) {
                return $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : ['en' => $raw];
            }
        }

        return [];
    }
}
