<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;
use Common\I18n\I18n;

class Product extends Model
{
    protected $casts = [
        'name'        => 'array',
        'description' => 'array',
    ];

    protected $appends = ['name_localized', 'description_localized'];

    public function getNameLocalizedAttribute(): ?string
    {
        return I18n::translateField($this->name);
    }

    public function getDescriptionLocalizedAttribute(): ?string
    {
        return I18n::translateField($this->description);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function skus()
    {
        return $this->hasMany(ProductSku::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class)->where('status', 'published');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }
}
