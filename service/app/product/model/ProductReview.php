<?php
namespace App\product\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;
use App\user\model\User;
use App\user\model\UserProfile;

class ProductReview extends Model
{
    use HasSnowflakeId;
    protected $table = 'product_reviews';
    protected $fillable = ['user_id', 'product_id', 'order_id', 'rating', 'content', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profile()
    {
        return $this->belongsTo(UserProfile::class, 'user_id', 'user_id');
    }

    /**
     * 公开评价只允许输出评价者的 id/nickname/avatar；
     * 直接序列化 User/UserProfile 会把 email/phone/fcm_token 等 PII 带出去。
     */
    public function publicUser(): array
    {
        $profile = $this->profile;
        return [
            'id'       => $this->user_id,
            'nickname' => $profile?->nickname,
            'avatar'   => $profile?->avatar,
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
