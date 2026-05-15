<?php

namespace Tests\Common;

use Hashids\Hashids;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HashidServiceTest extends TestCase
{
    private Hashids $hashids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hashids = new Hashids('test-salt', 12);
    }

    public function testEncodeReturnsString(): void
    {
        $hash = $this->hashids->encode(1);
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
    }

    public function testEncodeIsDeterministic(): void
    {
        $a = $this->hashids->encode(42);
        $b = $this->hashids->encode(42);
        $this->assertSame($a, $b);
    }

    public function testDifferentIdsYieldDifferentHashes(): void
    {
        $a = $this->hashids->encode(1);
        $b = $this->hashids->encode(2);
        $this->assertNotSame($a, $b);
    }

    #[DataProvider('roundtripProvider')]
    public function testEncodeDecodeRoundtrip(int $id): void
    {
        $hash = $this->hashids->encode($id);
        $decoded = $this->hashids->decode($hash);
        $this->assertNotEmpty($decoded);
        $this->assertSame($id, $decoded[0]);
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

    public function testDecodeInvalidStringReturnsEmpty(): void
    {
        $result = $this->hashids->decode('invalid_hash');
        $this->assertEmpty($result);
    }

    public function testDecodeEmptyStringReturnsEmpty(): void
    {
        $result = $this->hashids->decode('');
        $this->assertEmpty($result);
    }

    public function testEncodeZeroReturnsNonEmpty(): void
    {
        $hash = $this->hashids->encode(0);
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
    }

    public function testHashLengthIsConsistent(): void
    {
        $a = $this->hashids->encode(1);
        $b = $this->hashids->encode(9999999999);
        $this->assertGreaterThanOrEqual(12, strlen($a));
        $this->assertGreaterThanOrEqual(12, strlen($b));
    }

    public function testSameSaltProducesDifferentHashes(): void
    {
        $otherHashids = new Hashids('different-salt', 12);
        $a = $this->hashids->encode(123);
        $b = $otherHashids->encode(123);
        $this->assertNotSame($a, $b);
    }

    public function testRecursiveIdWalk(): void
    {
        $data = [
            'id' => 1,
            'name' => 'test',
            'items' => [
                ['id' => 100, 'name' => 'item1'],
            ],
        ];

        $encoded = $this->walkIds($data, $this->hashids);
        $this->assertIsString($encoded['id']);
        $this->assertIsString($encoded['items'][0]['id']);
        $this->assertSame('test', $encoded['name']);
    }

    public function testWalkSkipsNonIntIds(): void
    {
        $data = ['id' => 'already_a_string', 'name' => 'test'];
        $encoded = $this->walkIds($data, $this->hashids);
        $this->assertSame('already_a_string', $encoded['id']);
    }

    public function testWalkHandlesNonIdFields(): void
    {
        $data = ['username' => 'john', 'email' => 'john@example.com'];
        $encoded = $this->walkIds($data, $this->hashids);
        $this->assertSame('john', $encoded['username']);
        $this->assertSame('john@example.com', $encoded['email']);
    }

    private function walkIds(array $data, Hashids $hashids): array
    {
        foreach ($data as $key => $value) {
            if ($key === 'id' && is_int($value)) {
                $data[$key] = $hashids->encode($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->walkIds($value, $hashids);
            }
        }
        return $data;
    }
}
