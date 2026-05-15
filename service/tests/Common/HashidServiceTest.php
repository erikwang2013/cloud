<?php

namespace Tests\Common;

use Common\Hashid\HashidService;
use Erikwang2013\Hashids\HashidsFactory;
use Erikwang2013\Hashids\HashidsManager;
use Hashids\Hashids;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HashidServiceTest extends TestCase
{
    private HashidsManager $manager;

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

        $this->manager = new HashidsManager($config, new HashidsFactory());
        $this->injectManager($this->manager);
    }

    private function injectManager(HashidsManager $manager): void
    {
        $ref = new \ReflectionClass(HashidService::class);
        $prop = $ref->getProperty('manager');
        $prop->setValue(null, $manager);
    }

    public function testEncodeReturnsString(): void
    {
        $hash = HashidService::encode(1);
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
    }

    public function testEncodeIsDeterministic(): void
    {
        $a = HashidService::encode(42);
        $b = HashidService::encode(42);
        $this->assertSame($a, $b);
    }

    public function testDifferentIdsYieldDifferentHashes(): void
    {
        $a = HashidService::encode(1);
        $b = HashidService::encode(2);
        $this->assertNotSame($a, $b);
    }

    #[DataProvider('roundtripProvider')]
    public function testEncodeDecodeRoundtrip(int $id): void
    {
        $hash = HashidService::encode($id);
        $decoded = HashidService::decode($hash);
        $this->assertNotNull($decoded);
        $this->assertSame($id, $decoded);
    }

    public static function roundtripProvider(): array
    {
        return [
            'zero' => [0],
            'one' => [1],
            'small' => [42],
            'medium' => [123456],
            'large' => [PHP_INT_MAX],
        ];
    }

    public function testDecodeInvalidStringReturnsNull(): void
    {
        $result = HashidService::decode('invalid_hash');
        $this->assertNull($result);
    }

    public function testDecodeEmptyStringReturnsNull(): void
    {
        $result = HashidService::decode('');
        $this->assertNull($result);
    }

    public function testEncodeZeroReturnsNonEmpty(): void
    {
        $hash = HashidService::encode(0);
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
    }

    public function testHashLengthIsConsistent(): void
    {
        $a = HashidService::encode(1);
        $b = HashidService::encode(9999999999);
        $this->assertGreaterThanOrEqual(12, strlen($a));
        $this->assertGreaterThanOrEqual(12, strlen($b));
    }

    public function testDifferentSaltProducesDifferentHashes(): void
    {
        $config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'salt' => 'different-salt',
                    'length' => 12,
                    'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
                ],
            ],
        ];
        $otherManager = new HashidsManager($config, new HashidsFactory());
        $otherHashids = $otherManager->connection();

        $hashServiceHash = HashidService::encode(123);
        $otherHash = $otherHashids->encode(123);
        $this->assertNotSame($hashServiceHash, $otherHash);
    }

    public function testEncodeIdsRecursivelyTransformsIdFields(): void
    {
        $data = [
            'id' => 1,
            'name' => 'test',
            'items' => [
                ['id' => 100, 'name' => 'item1'],
            ],
        ];

        $encoded = HashidService::encodeIds($data);
        $this->assertIsString($encoded['id']);
        $this->assertIsString($encoded['items'][0]['id']);
        $this->assertSame('test', $encoded['name']);
    }

    public function testEncodeIdsSkipsNonIntIds(): void
    {
        $data = ['id' => 'already_a_string', 'name' => 'test'];
        $encoded = HashidService::encodeIds($data);
        $this->assertSame('already_a_string', $encoded['id']);
    }

    public function testEncodeIdsHandlesNonIdFields(): void
    {
        $data = ['username' => 'john', 'email' => 'john@example.com'];
        $encoded = HashidService::encodeIds($data);
        $this->assertSame('john', $encoded['username']);
        $this->assertSame('john@example.com', $encoded['email']);
    }

    public function testEncodeIdsWithNullReturnsEmptyArray(): void
    {
        $result = HashidService::encodeIds(null);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
