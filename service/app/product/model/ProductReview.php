<?php
namespace App\product\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\user\model\User;

class ProductReview extends Model
{
    use HasSnowflakeId;
    protected $table = 'product_reviews';
    protected $fillable = ['user_id', 'product_id', 'order_id', 'rating', 'content', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
