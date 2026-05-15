<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\model;

use DateTimeInterface;
use Snowflake\Snowflake;
use support\Model;

class Base extends Model
{
    /**
     * Database connection name.
     */
    protected $connection = 'mysql';

    /**
     * Snowflake generates 64-bit integer primary keys — disable auto-increment.
     */
    public $incrementing = false;

    /**
     * Snowflake IDs are 64-bit integers.
     */
    protected $keyType = 'int';

    /**
     * Auto-generate Snowflake primary key on model creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Model $model) {
            if (empty($model->getKey())) {
                $snowflake = \support\Container::instance()->get(Snowflake::class);
                $model->setAttribute($model->getKeyName(), $snowflake->id());
            }
        });
    }

    /**
     * Format the date for serialization.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
