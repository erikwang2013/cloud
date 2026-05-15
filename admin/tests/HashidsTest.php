<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function hashids_decode;
use function hashids_encode;
use function hashids_encode_ids;

/**
 * Tests for hashids helper functions in app/functions.php.
 */
#[CoversFunction('hashids_encode')]
#[CoversFunction('hashids_decode')]
#[CoversFunction('hashids_encode_ids')]
final class HashidsTest extends TestCase
{
    // ── encode / decode ──────────────────────────────────────────

    public function testEncodeReturnsNonEmptyString(): void
    {
        $hash = hashids_encode(1);
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
    }

    public function testEncodeIsDeterministic(): void
    {
        $this->assertSame(hashids_encode(42), hashids_encode(42));
    }

    public function testDifferentIdsYieldDifferentHashes(): void
    {
        $this->assertNotSame(hashids_encode(1), hashids_encode(2));
    }

    #[DataProvider('roundtripProvider')]
    public function testEncodeDecodeRoundtrip(int $id): void
    {
        $this->assertSame($id, hashids_decode(hashids_encode($id)));
    }

    /** @return iterable<string, array{int}> */
    public static function roundtripProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'small' => [42];
        yield 'medium' => [99999];
        yield 'large' => [999999999];
        yield 'snowflake size' => [313568214741291008];
        yield 'max safe int' => [PHP_INT_MAX];
    }

    public function testDecodeInvalidStringReturnsZero(): void
    {
        $this->assertSame(0, hashids_decode('not-a-valid-hash'));
    }

    public function testDecodeEmptyStringReturnsZero(): void
    {
        $this->assertSame(0, hashids_decode(''));
    }

    // ── hashids_encode_ids ───────────────────────────────────────

    public function testEncodeIdsEncodesFieldsNamedId(): void
    {
        $data = ['id' => 123, 'name' => 'Alice'];
        $result = hashids_encode_ids($data);

        $this->assertNotSame(123, $result['id']);
        $this->assertIsString($result['id']);
        $this->assertSame(123, hashids_decode($result['id']));
        $this->assertSame('Alice', $result['name']);
    }

    public function testEncodeIdsEncodesSuffixIdFields(): void
    {
        $data = ['user_id' => 500, 'role_id' => 999];
        $result = hashids_encode_ids($data);

        $this->assertIsString($result['user_id']);
        $this->assertIsString($result['role_id']);
        $this->assertSame(500, hashids_decode($result['user_id']));
        $this->assertSame(999, hashids_decode($result['role_id']));
    }

    public function testEncodeIdsEncodesIdsPluralSuffix(): void
    {
        $data = ['role_ids' => 777];
        $result = hashids_encode_ids($data);

        $this->assertIsString($result['role_ids']);
        $this->assertSame(777, hashids_decode($result['role_ids']));
    }

    public function testEncodeIdsSkipsNonIdFields(): void
    {
        $data = ['title' => 'Hello', 'count' => 42, 'active' => true];
        $result = hashids_encode_ids($data);

        $this->assertSame('Hello', $result['title']);
        $this->assertSame(42, $result['count']);
        $this->assertTrue($result['active']);
    }

    public function testEncodeIdsSkipsZeroId(): void
    {
        $data = ['id' => 0];
        $result = hashids_encode_ids($data);

        $this->assertSame(0, $result['id']);
    }

    public function testEncodeIdsSkipsNegativeId(): void
    {
        $data = ['id' => -5];
        $result = hashids_encode_ids($data);

        $this->assertSame(-5, $result['id']);
    }

    public function testEncodeIdsHandlesNumericStringId(): void
    {
        $data = ['user_id' => '99999'];
        $result = hashids_encode_ids($data);

        $this->assertIsString($result['user_id']);
        $this->assertSame(99999, hashids_decode($result['user_id']));
    }

    public function testEncodeIdsHandlesFloatNonWholeNumericString(): void
    {
        $data = ['id' => '3.14'];
        $result = hashids_encode_ids($data);

        $this->assertSame('3.14', $result['id']);
    }

    public function testEncodeIdsRecursesIntoNestedArrays(): void
    {
        $data = [
            'items' => [
                ['id' => 10, 'name' => 'a'],
                ['id' => 20, 'name' => 'b'],
            ],
        ];
        $result = hashids_encode_ids($data);

        $this->assertIsString($result['items'][0]['id']);
        $this->assertIsString($result['items'][1]['id']);
        $this->assertSame(10, hashids_decode($result['items'][0]['id']));
        $this->assertSame(20, hashids_decode($result['items'][1]['id']));
    }

    public function testEncodeIdsPreservesNonIdIntegers(): void
    {
        $data = ['status' => 1, 'weight' => 100, 'type' => 2];
        $result = hashids_encode_ids($data);

        // status, weight, type are NOT id fields — they stay integers.
        $this->assertSame(1, $result['status']);
        $this->assertSame(100, $result['weight']);
        $this->assertSame(2, $result['type']);
    }
}
