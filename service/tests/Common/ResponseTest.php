<?php

namespace Tests\Common;

use Common\Hashid\HashidService;
use Common\Helper\Response;
use Erikwang2013\Hashids\HashidsFactory;
use Erikwang2013\Hashids\HashidsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $manager = new HashidsManager($config, new HashidsFactory());
        $ref = new \ReflectionClass(HashidService::class);
        $prop = $ref->getProperty('manager');
        $prop->setValue(null, $manager);
    }

    public function testSuccessStructure(): void
    {
        $result = Response::success(['name' => 'test']);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['code']);
        $this->assertSame('ok', $result['message']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('request_id', $result);
    }

    public function testSuccessWithNullData(): void
    {
        $result = Response::success(null);
        $this->assertSame(0, $result['code']);
        $this->assertNull($result['data']);
    }

    public function testSuccessCustomMessage(): void
    {
        $result = Response::success([], 'created');
        $this->assertSame('created', $result['message']);
    }

    public function testSuccessEncodesIdFields(): void
    {
        $result = Response::success(['id' => 42, 'name' => 'test']);
        $this->assertIsString($result['data']['id']);
        $this->assertNotSame(42, $result['data']['id']);
        $this->assertSame('test', $result['data']['name']);
    }

    public function testErrorStructure(): void
    {
        $result = Response::error(422, 'Validation failed');
        $this->assertSame(422, $result['code']);
        $this->assertSame('Validation failed', $result['message']);
        $this->assertArrayHasKey('request_id', $result);
    }

    public function testErrorWithData(): void
    {
        $result = Response::error(404, 'Not found', ['id' => 42]);
        $this->assertSame(42, $result['data']['id']);
    }

    public function testPaginatedStructure(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $result = Response::paginated($items, 50, 1, 20);
        $this->assertSame(0, $result['code']);
        $this->assertSame(1, $result['meta']['page']);
        $this->assertSame(20, $result['meta']['page_size']);
        $this->assertSame(50, $result['meta']['total']);
        $this->assertCount(2, $result['data']);
    }

    public function testPaginatedWithEmptyItems(): void
    {
        $result = Response::paginated([], 0, 1, 20);
        $this->assertSame(0, $result['code']);
        $this->assertSame(0, $result['meta']['total']);
        $this->assertEmpty($result['data']);
    }

    public function testRequestIdIsPresent(): void
    {
        $result = Response::success();
        $this->assertNotEmpty($result['request_id']);
    }

    public function testRequestIdIsConsistent(): void
    {
        $a = Response::success();
        $b = Response::error(500, 'err');
        $this->assertSame($a['request_id'], $b['request_id']);
    }

    #[DataProvider('responseCodeProvider')]
    public function testHttpErrorCodes(int $code, string $message): void
    {
        $result = Response::error($code, $message);
        $this->assertSame($code, $result['code']);
        $this->assertSame($message, $result['message']);
    }

    public static function responseCodeProvider(): array
    {
        return [
            'bad request' => [400, 'Bad request'],
            'unauthorized' => [401, 'Unauthorized'],
            'forbidden' => [403, 'Forbidden'],
            'not found' => [404, 'Not found'],
            'conflict' => [409, 'Conflict'],
            'server error' => [500, 'Internal server error'],
        ];
    }
}
