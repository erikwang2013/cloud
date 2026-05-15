<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\controller\Crud;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use support\Model;
use support\Request;
use tests\Support\RequestMock;
use tests\Support\TestableCrud;

use function hashids_encode;

/**
 * Tests for hashids decoding in Crud::selectInput, updateInput, deleteInput.
 */
final class CrudHashidsTest extends TestCase
{
    private TestableCrud $crud;

    protected function setUp(): void
    {
        $this->crud = new TestableCrud;
    }

    // ── selectInput ──────────────────────────────────────────────

    public function testSelectInputDecodesHashidStringInIdField(): void
    {
        $hash = hashids_encode(42);
        $request = $this->makeRequest(['id' => $hash]);

        [$where] = $this->invokeSelectInput($request);

        $this->assertSame(42, $where['id']);
    }

    public function testSelectInputDecodesHashidStringInSuffixIdField(): void
    {
        $hash = hashids_encode(99);
        $request = $this->makeRequest(['role_id' => $hash]);

        [$where] = $this->invokeSelectInput($request);

        $this->assertSame(99, $where['role_id']);
    }

    public function testSelectInputPassesNumericStringThroughUnchanged(): void
    {
        $request = $this->makeRequest(['id' => '123']);

        [$where] = $this->invokeSelectInput($request);

        $this->assertSame('123', $where['id']); // not decoded — looks numeric
    }

    public function testSelectInputPassesRawIntegerThrough(): void
    {
        $request = $this->makeRequest(['id' => 5]);

        [$where] = $this->invokeSelectInput($request);

        $this->assertSame(5, $where['id']);
    }

    public function testSelectInputDecodesMultipleIdFields(): void
    {
        $request = $this->makeRequest([
            'id' => hashids_encode(1),
            'admin_id' => hashids_encode(2),
            'role_id' => hashids_encode(3),
        ]);

        [$where] = $this->invokeSelectInput($request);

        $this->assertSame(1, $where['id']);
        $this->assertSame(2, $where['admin_id']);
        $this->assertSame(3, $where['role_id']);
    }

    public function testSelectInputLeavesNonIdFieldsUntouched(): void
    {
        $request = $this->makeRequest([
            'username' => hashids_encode(77),
            'page' => '2',
            'limit' => '10',
        ]);

        [$where] = $this->invokeSelectInput($request);

        $this->assertSame(hashids_encode(77), $where['username']);
    }

    public function testSelectInputDecodesLargeSnowflakeStyleId(): void
    {
        $id = 313568214741291008;
        $hash = hashids_encode($id);
        $request = $this->makeRequest(['id' => $hash]);

        [$where] = $this->invokeSelectInput($request);

        $this->assertSame($id, $where['id']);
    }

    // ── updateInput ──────────────────────────────────────────────

    public function testUpdateInputDecodesHashidPrimaryKey(): void
    {
        $hash = hashids_encode(42);
        $request = $this->makePostRequest(['id' => $hash]);

        [$id] = $this->invokeUpdateInput($request);

        $this->assertSame(42, $id);
    }

    public function testUpdateInputPassesNumericStringPkThrough(): void
    {
        $request = $this->makePostRequest(['id' => '123']);

        [$id] = $this->invokeUpdateInput($request);

        $this->assertSame(123, $id); // cast to int
    }

    public function testUpdateInputPassesRawIntegerPkThrough(): void
    {
        $request = $this->makePostRequest(['id' => 7]);

        [$id] = $this->invokeUpdateInput($request);

        $this->assertSame(7, $id);
    }

    // ── deleteInput ──────────────────────────────────────────────

    public function testDeleteInputDecodesHashidIds(): void
    {
        $request = $this->makePostRequest(['id' => [
            hashids_encode(10),
            hashids_encode(20),
            hashids_encode(30),
        ]]);

        $ids = $this->invokeDeleteInput($request);

        $this->assertSame([10, 20, 30], $ids);
    }

    public function testDeleteInputDecodesMixedIds(): void
    {
        $request = $this->makePostRequest(['id' => [
            '5',
            hashids_encode(42),
            99,
            hashids_encode(313568214741291008),
        ]]);

        $ids = $this->invokeDeleteInput($request);

        $this->assertSame([5, 42, 99, 313568214741291008], $ids);
    }

    public function testDeleteInputHandlesEmptyArray(): void
    {
        $request = $this->makePostRequest(['id' => []]);

        $ids = $this->invokeDeleteInput($request);

        $this->assertSame([], $ids);
    }

    public function testDeleteInputDecodesSingleId(): void
    {
        $request = $this->makePostRequest(['id' => hashids_encode(77)]);

        $ids = $this->invokeDeleteInput($request);

        $this->assertSame([77], $ids);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeRequest(array $getParams): Request
    {
        return new RequestMock($getParams, []);
    }

    private function makePostRequest(array $postParams): Request
    {
        return new RequestMock([], $postParams);
    }

    private function invokeSelectInput(Request $request): array
    {
        $method = new ReflectionMethod(TestableCrud::class, 'selectInput');
        return $method->invoke($this->crud, $request);
    }

    private function invokeUpdateInput(Request $request): array
    {
        $method = new ReflectionMethod(TestableCrud::class, 'updateInput');
        return $method->invoke($this->crud, $request);
    }

    private function invokeDeleteInput(Request $request): array
    {
        $method = new ReflectionMethod(TestableCrud::class, 'deleteInput');
        return $method->invoke($this->crud, $request);
    }
}
