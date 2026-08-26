<?php
namespace Common\snowflake;

trait HasSnowflakeId
{
    public static function bootHasSnowflakeId(): void
    {
        static::creating(function ($model) {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), SnowflakeService::nextId());
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'int';
    }
}
