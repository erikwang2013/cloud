<?php
namespace App\Payment\Model;

use Illuminate\Database\Eloquent\Model;

class PaymentChannel extends Model
{
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
    ];
}
