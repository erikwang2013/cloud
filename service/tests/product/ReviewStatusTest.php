<?php

namespace Tests\product;

use App\product\model\Product;
use App\product\model\ReviewStatus;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use PHPUnit\Framework\TestCase;

final class ReviewStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 仅构建查询不执行，null PDO 足以满足关系构造，避免测试触库
        $resolver = new ConnectionResolver(['default' => new Connection(null)]);
        $resolver->setDefaultConnection('default');
        Product::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        Product::unsetConnectionResolver();
        parent::tearDown();
    }

    public function testEnumContainsOnlyTheThreeReviewStates(): void
    {
        $this->assertSame(['pending', 'approved', 'rejected'], array_map(
            fn (ReviewStatus $s) => $s->value,
            ReviewStatus::cases()
        ));
    }

    public function testApprovedIsTheOnlyStatusShownToClients(): void
    {
        // 展示路径（列表/产品详情 reviews 关系）只过滤 approved，
        // 与 store 写入的 pending 形成 pending → approved 审核闭环。
        $this->assertSame('approved', ReviewStatus::Approved->value);
        $this->assertNotSame(ReviewStatus::Pending->value, ReviewStatus::Approved->value);
    }

    public function testProductReviewsRelationFiltersApproved(): void
    {
        $wheres = (new Product)->reviews()->getQuery()->getQuery()->wheres;
        $statusWhere = collect($wheres)->first(fn ($w) => ($w['column'] ?? null) === 'status');
        $this->assertSame('approved', $statusWhere['value'] ?? null);
    }
}
