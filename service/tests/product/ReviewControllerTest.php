<?php

namespace Tests\product;

use App\product\controller\ReviewController;
use Common\hashid\HashidService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Tests\support\RequestMock;

/**
 * 评价汇总：一条 group by rating 查询推导 avg/total/分布，且只统计 approved；
 * 路由参数 hashid 必须解码后才能作为 product_id 查询。
 */
final class ReviewControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('users', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->softDeletes();
        });
        $schema->create('user_profiles', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('user_id');
            $t->string('nickname')->nullable();
            $t->string('avatar')->nullable();
            $t->timestamps();
        });
        $schema->create('product_reviews', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('user_id')->default(0);
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->integer('rating');
            $t->text('content')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        // Response::success -> HashidService::encodeIds，注入 manager 避免读 config
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
        (new \ReflectionProperty(HashidService::class, 'manager'))->setValue(null, $manager);
    }

    public function testSummaryAggregatesApprovedRatingsInOneGroupedQuery(): void
    {
        $rows = [];
        $id = 1;
        // approved: 5,5,4,2 → avg 4.0；pending/rejected 不计
        foreach ([[5, 'approved'], [5, 'approved'], [4, 'approved'], [2, 'approved'], [3, 'pending'], [1, 'rejected']] as [$rating, $status]) {
            $rows[] = [
                'id' => $id++,
                'product_id' => 42,
                'rating' => $rating,
                'status' => $status,
                'created_at' => '2026-08-01 10:00:00',
                'updated_at' => '2026-08-01 10:00:00',
            ];
        }
        Capsule::table('product_reviews')->insert($rows);

        $response = (new ReviewController())->index(new RequestMock(), HashidService::encode(42));
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame(0, $body['code']);
        $this->assertSame(4, $body['meta']['total']);

        $summary = $body['meta']['summary'];
        $this->assertEquals(4.0, $summary['avg_rating']);
        $this->assertSame(4, $summary['total_reviews']);
        $this->assertSame(2, $summary['rating_dist'][5]);
        $this->assertSame(1, $summary['rating_dist'][4]);
        $this->assertSame(0, $summary['rating_dist'][3]);
        $this->assertSame(1, $summary['rating_dist'][2]);
        $this->assertSame(0, $summary['rating_dist'][1]);
    }

    public function testReviewUserPayloadExposesWhitelistedFieldsOnly(): void
    {
        // AUD-04：禁止序列化 User/UserProfile，公开评价只输出 id/nickname/avatar
        Capsule::table('product_reviews')->insert([
            'id' => 9001,
            'product_id' => 42,
            'user_id' => 777,
            'rating' => 5,
            'content' => 'great',
            'status' => 'approved',
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
        Capsule::table('user_profiles')->insert([
            'id' => 8001,
            'user_id' => 777,
            'nickname' => 'Alice',
            'avatar' => 'https://cdn.example.com/a.png',
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);

        $response = (new ReviewController())->index(new RequestMock(), HashidService::encode(42));
        $body = json_decode((string) $response->rawBody(), true);

        $user = $body['data'][0]['user'];
        $this->assertSame(['id', 'nickname', 'avatar'], array_keys($user), 'user 只允许白名单字段');
        $this->assertSame('Alice', $user['nickname']);
        $this->assertSame('https://cdn.example.com/a.png', $user['avatar']);
        $this->assertArrayNotHasKey('profile', $body['data'][0]);

        $raw = (string) $response->rawBody();
        foreach (['email', 'phone', 'fcm_token', 'last_login_at', 'notification_prefs'] as $field) {
            $this->assertStringNotContainsString($field, $raw, "公开评价响应不得包含 $field");
        }
    }

    public function testSummaryIsEmptyForProductWithoutApprovedReviews(): void
    {
        $response = (new ReviewController())->index(new RequestMock(), HashidService::encode(999));
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame(0, $body['meta']['summary']['avg_rating']);
        $this->assertSame(0, $body['meta']['summary']['total_reviews']);
        $this->assertSame([5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], $body['meta']['summary']['rating_dist']);
    }
}
