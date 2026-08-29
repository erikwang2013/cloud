<?php
namespace App\provisioning\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use Erikwang2013\Encryptable\Encryptable;

class ProviderApi extends Model
{
    use HasSnowflakeId;
    protected $table = 'provider_apis';
    protected $fillable = ['name', 'code', 'api_key_encrypted', 'api_secret_encrypted', 'webhook_secret', 'status', 'config'];

    protected $casts = [
        'api_key_encrypted'    => Encryptable::class,
        'api_secret_encrypted' => Encryptable::class,
        'config'               => 'array',
    ];

    // 序列化一律不输出凭据字段
    protected $hidden = ['api_key_encrypted', 'api_secret_encrypted', 'webhook_secret'];
}
