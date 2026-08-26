<?php
namespace App\model;

use Illuminate\Database\Eloquent\Model;
use Common\snowflake\HasSnowflakeId;

class HelpArticle extends Model
{
    use HasSnowflakeId;
    protected $table = 'help_articles';
    protected $fillable = ['category', 'title', 'slug', 'content', 'locale', 'sort', 'status'];

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }
}
