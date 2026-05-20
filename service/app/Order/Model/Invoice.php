<?php
namespace App\Order\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;

class Invoice extends Model
{
    use HasSnowflakeId;
    protected $table = 'order_invoices';
    protected $fillable = ['order_id', 'user_id', 'type', 'title', 'tax_number', 'amount', 'file_url'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
