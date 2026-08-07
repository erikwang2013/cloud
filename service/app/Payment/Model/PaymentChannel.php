<?php
namespace App\Payment\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Erikwang2013\Encryptable\Encryptable;

class PaymentChannel extends Model
{
    use HasSnowflakeId;
    protected $table = 'payment_channels';
    protected $fillable = [
        'name', 'code', 'api_key_encrypted', 'currency_support',
        'fee_config', 'is_visible', 'visible_regions',
        'min_amount', 'max_amount', 'webhook_secret', 'status',
    ];

    protected $casts = [
        'currency_support' => 'array',
        'fee_config'       => 'array',
        'visible_regions'  => 'array',
        'is_visible'       => 'boolean',
        'api_key_encrypted' => Encryptable::class,
        'webhook_secret'    => Encryptable::class,
    ];
}
