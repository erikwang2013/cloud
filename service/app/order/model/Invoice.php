<?php
namespace App\order\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

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
