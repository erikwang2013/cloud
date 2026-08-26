<?php
namespace App\product\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class ProductCategory extends Model
{
    use HasSnowflakeId;
    protected $table = 'product_categories';
    protected $casts = ['name' => 'array'];
    protected $fillable = ['parent_id', 'name', 'slug', 'type', 'sort', 'icon', 'status'];

    public function parent()
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
