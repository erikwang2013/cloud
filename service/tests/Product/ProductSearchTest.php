<?php

namespace Tests\Product;

use App\Product\Model\Product;
use App\Product\Service\ProductService;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class ProductSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 必须先挂 dispatcher 再触碰模型：HasSnowflakeId 在 boot 时把 creating 监听器
        // 注册到当前 dispatcher，无 dispatcher 则静默丢弃；模型 boot 进程内只发生一次，
        // 一旦本文件先 boot 了 Product，后续文件（ProductServiceTest）save 后 id 为
        // NULL、refresh 必挂（单独跑 tests/Product/ 目录时的 7 个错误）
        if (!Model::getEventDispatcher()) {
            Model::setEventDispatcher(
                new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container)
            );
        }
        // 仅构建模型不执行查询，null PDO 足以构造关系，避免测试触库
        $resolver = new ConnectionResolver(['default' => new Connection(null)]);
        $resolver->setDefaultConnection('default');
        Product::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        Product::unsetConnectionResolver();
        parent::tearDown();
    }

    public function testToSearchableArrayFlattensI18nArrays(): void
    {
        $product = new Product();
        $product->forceFill([
            'id'          => 1001,
            'category_id' => 7,
            'status'      => 'published',
            'name'        => ['zh-CN' => '云服务器', 'en-US' => 'Cloud Server'],
            'description' => ['zh-CN' => '高性能实例', 'en-US' => 'High performance instance'],
        ]);

        $arr = $product->toSearchableArray();

        $this->assertSame(1001, $arr['id']);
        $this->assertSame(7, $arr['category_id']);
        $this->assertSame('published', $arr['status']);
        $this->assertSame('云服务器 Cloud Server', $arr['name']);
        $this->assertSame('高性能实例 High performance instance', $arr['description']);
        $this->assertSame(0, $arr['base_price']);
    }

    public function testToSearchableArrayHandlesScalarName(): void
    {
        $product = new Product();
        $product->forceFill(['name' => 'vps', 'description' => 'hosting']);

        $arr = $product->toSearchableArray();

        $this->assertSame('vps', $arr['name']);
        $this->assertSame('hosting', $arr['description']);
    }

    public function testEscapeQueryStringEscapesReservedChars(): void
    {
        $this->assertSame('vps', ProductService::escapeQueryString('vps'));
        $this->assertSame('cloud\\*server', ProductService::escapeQueryString('cloud*server'));
        $this->assertSame('\\"quoted\\"', ProductService::escapeQueryString('"quoted"'));
        $this->assertSame('a\\&\\&b', ProductService::escapeQueryString('a&&b'));
        $this->assertSame('vps 服务器', ProductService::escapeQueryString('vps 服务器'));
    }

    public function testESIntegrationRequiresContainer(): void
    {
        // 集成路径（Product::search → ES）依赖 docker 起的 ES 容器：
        //   1. docker compose up -d elasticsearch
        //   2. php think scout:import 建索引并全量导入
        //   3. 手工验证 curl http://elasticsearch:9200/products/_search?q=keyword
        // 单测环境无 ES，跳过；ES 不可用时 ProductService 会降级 SQL 模糊匹配。
        $this->markTestSkipped('ES 集成验证需要真实 ES 容器，见上方注释步骤');
    }
}
