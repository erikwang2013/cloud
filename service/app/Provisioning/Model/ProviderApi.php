<?php
namespace App\Provisioning\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Erikwang2013\Encryptable\Encryptable;

class ProviderApi extends Model
{
    use HasSnowflakeId;
    protected $table = 'provider_apis';
    protected $fillable = ['name', 'code', 'api_key_encrypted', 'api_secret_encrypted', 'webhook_secret', 'status'];

    protected $casts = [
        'api_key_encrypted'    => Encryptable::class,
        'api_secret_encrypted' => Encryptable::class,
    ];
}
