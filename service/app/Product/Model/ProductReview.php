<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;
use App\User\Model\User;

class ProductReview extends Model
{
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
