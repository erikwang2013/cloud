<?php
namespace App\Product\Model;

use Illuminate\Database\Eloquent\Model;
use Common\Snowflake\HasSnowflakeId;
use Common\I18n\I18n;
use Erikwang2013\WebmanScout\Searchable;

class Product extends Model
{
    use HasSnowflakeId;
    use Searchable;
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

    public function toSearchableArray(): array
    {
        // 将 i18n JSON 字段展开为搜索友好的文本拼接
        $name = $this->name ?? [];
        $desc = $this->description ?? [];

        return [
            'id'          => $this->id,
            'category_id' => $this->category_id,
            'name'        => is_array($name) ? implode(' ', $name) : (string) $name,
            'description' => is_array($desc) ? implode(' ', $desc) : (string) $desc,
            'status'      => $this->status,
            'base_price'  => $this->min_price ?? 0,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
