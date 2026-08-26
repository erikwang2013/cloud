<?php

namespace Tests\product;

use App\product\model\Product;
use App\product\model\ProductRegion;
use App\product\model\ProductSku;
use App\product\service\ProductService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        if (!Model::getEventDispatcher()) {
            Model::setEventDispatcher(
                new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
            );
        }

        $schema = $capsule->schema();
        $schema->create('product_categories', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('name');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        $schema->create('products', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('category_id')->default(0);
            $t->unsignedBigInteger('supplier_id')->default(0);
            $t->string('status')->default('draft');
            $t->string('slug');
            $t->text('name');
            $t->text('description')->nullable();
            $t->decimal('min_price', 14, 4)->nullable();
            $t->timestamps();
        });
        $schema->create('product_skus', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('product_id');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        $schema->create('product_regions', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('sku_id');
            $t->unsignedBigInteger('region_id');
            $t->string('currency', 3)->default('USD');
            $t->decimal('price', 14, 4);
            $t->timestamps();
        });
        $schema->create('regions', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('continent');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        // detail() 会 eager load images / reviews.user.profile，空表即可
        $schema->create('product_images', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('product_id');
            $t->integer('sort')->default(0);
            $t->timestamps();
        });
        $schema->create('product_reviews', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('user_id')->default(0);
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        $schema->create('users', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('status')->default('active');
            $t->string('email')->nullable();
            $t->softDeletes();
        });
        $schema->create('user_profiles', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });

        // Response::success 会走 HashidService::encodeIds，注入 manager 避免读 config
        $config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'salt' => getenv('HASHIDS_SALT') ?: 'test-salt',
                    'length' => (int)(getenv('HASHIDS_LENGTH') ?: 12),
                    'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
                ],
            ],
        ];
        $manager = new \Erikwang2013\Hashids\HashidsManager($config, new \Erikwang2013\Hashids\HashidsFactory());
        $ref = new \ReflectionClass(\Common\hashid\HashidService::class);
        $prop = $ref->getProperty('manager');
        $prop->setValue(null, $manager);
    }

    private function seedProduct(array $overrides = []): Product
    {
        $p = new Product();
        $p->forceFill(array_merge([
            'category_id' => 0,
            'supplier_id' => 1,
            'status'      => 'published',
            'slug'        => 'vps-' . uniqid(),
            'name'        => ['en-US' => 'VPS Gold'],
            'description' => ['en-US' => 'Hosting'],
            'min_price'   => 5,
        ], $overrides));
        $p->save();
        return $p->refresh();
    }

    private function seedSku(Product $product): ProductSku
    {
        $s = new ProductSku();
        $s->forceFill(['product_id' => $product->id]);
        $s->save();
        return $s->refresh();
    }

    public function testListReturnsPublishedOnlyAndPaginates(): void
    {
        $this->seedProduct();
        $this->seedProduct();
        $this->seedProduct(['status' => 'draft']);

        $result = (new ProductService())->list([], 1, 1);

        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['page_size']);
        $this->assertCount(1, $result['data']);
    }

    public function testListFiltersByCategoryAndSupplier(): void
    {
        $category = new \App\product\model\ProductCategory();
        $category->forceFill(['name' => 'VPS']);
        $category->save();
        $cat = $category->refresh();
        $this->seedProduct(['category_id' => $cat->id, 'supplier_id' => 1]);
        $this->seedProduct(['category_id' => $cat->id, 'supplier_id' => 2]);
        $this->seedProduct(['supplier_id' => 1]);

        $result = (new ProductService())->list(['category_id' => $cat->id, 'supplier_id' => 1]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame((string) $cat->id, (string) $result['data'][0]['category_id']);
    }

    public function testListKeywordWithoutESReturnsEmptyWithoutError(): void
    {
        // 单测容器无 scout engine：Product::search 返回空数组（不抛异常），
        // SQL 降级仅在引擎存在但 ES 故障时触发（见 ProductService::searchKeywordIds）
        $this->seedProduct(['slug' => 'vps-gold']);

        $result = (new ProductService())->list(['keyword' => 'vps']);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result['meta']);
    }

    public function testSearchWithoutScoutReturnsPaginatedStructure(): void
    {
        // 回归：ProductController::search 曾裸调 Product::search()->paginate()，
        // ES client 缺失时抛 ScoutException 500；现经 searchKeywordIds 兜底
        $this->seedProduct(['slug' => 'vps-gold']);

        $result = (new ProductService())->search('vps', 1, 10);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result['meta']);
        $this->assertSame(1, $result['meta']['page']);
        $this->assertSame(10, $result['meta']['page_size']);
    }

    public function testListFiltersByRegion(): void
    {
        $p1 = $this->seedProduct();
        $p2 = $this->seedProduct();
        $sku1 = $this->seedSku($p1);
        $sku2 = $this->seedSku($p2);
        $rp1 = new ProductRegion();
        $rp1->forceFill(['sku_id' => $sku1->id, 'region_id' => 1, 'price' => 5]);
        $rp1->save();
        $rp2 = new ProductRegion();
        $rp2->forceFill(['sku_id' => $sku2->id, 'region_id' => 2, 'price' => 6]);
        $rp2->save();

        $result = (new ProductService())->list(['region_id' => 1]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame(\Common\hashid\HashidService::encode($p1->id), $result['data'][0]['id']);
    }

    public function testDetailReturnsPublishedProduct(): void
    {
        $p = $this->seedProduct();

        $detail = (new ProductService())->detail($p->id);

        $this->assertInstanceOf(Product::class, $detail);
        $this->assertSame('VPS Gold', $detail->name_localized);
    }

    public function testDetailMissingProductFails(): void
    {
        $this->expectException(ModelNotFoundException::class);
        (new ProductService())->detail(999999);
    }

    public function testDetailDraftProductNotVisible(): void
    {
        $p = $this->seedProduct(['status' => 'draft']);

        $this->expectException(ModelNotFoundException::class);
        (new ProductService())->detail($p->id);
    }

    public function testEscapeQueryStringBoundaries(): void
    {
        $this->assertSame('', ProductService::escapeQueryString(''));
        $this->assertSame('plain text 123', ProductService::escapeQueryString('plain text 123'));
        $this->assertSame('a\\+b', ProductService::escapeQueryString('a+b'));
        $this->assertSame('a\\-b', ProductService::escapeQueryString('a-b'));
        $this->assertSame('a\\=b', ProductService::escapeQueryString('a=b'));
        $this->assertSame('a\\/b', ProductService::escapeQueryString('a/b'));
        $this->assertSame('a\\\\b', ProductService::escapeQueryString('a\\b'));
        $this->assertSame('a\\?b', ProductService::escapeQueryString('a?b'));
        $this->assertSame('a\\[b\\]', ProductService::escapeQueryString('a[b]'));
    }

    public function testInvalidateCacheDoesNotThrow(): void
    {
        // Redis 不可用时 forgetPattern 静默降级
        ProductService::invalidateCache();
        $this->addToAssertionCount(1);
    }
}
