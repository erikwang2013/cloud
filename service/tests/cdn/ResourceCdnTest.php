<?php

namespace Tests\cdn;

use App\cdn\model\ResourceCdn;
use PHPUnit\Framework\TestCase;

final class ResourceCdnTest extends TestCase
{
    public function testFillableFields(): void
    {
        $fillable = (new ResourceCdn())->getFillable();
        foreach (['resource_id', 'cdn_domain', 'origin_type', 'origin_value', 'plan', 'ssl', 'cache_rules', 'status', 'purged_at'] as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function testCasts(): void
    {
        $cdn = new ResourceCdn();
        $cdn->ssl = true;
        $cdn->cache_rules = ['/*' => 86400];

        $this->assertTrue($cdn->ssl);
        $this->assertIsBool($cdn->ssl);
        $this->assertIsArray($cdn->cache_rules);
        $this->assertSame(86400, $cdn->cache_rules['/*']);
    }

    public function testResourceRelation(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new ResourceCdn())->resource()
        );
    }
}
