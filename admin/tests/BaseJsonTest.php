<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\controller\Base;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function hashids_decode;

/**
 * Tests for Base::json() ensuring hashids encoding is applied to IDs in responses.
 */
final class BaseJsonTest extends TestCase
{
    private Base $controller;

    protected function setUp(): void
    {
        $this->controller = new class extends Base {
            public function json(int $code, string $msg = 'ok', array $data = []): \support\Response
            {
                return parent::json($code, $msg, $data);
            }

            public function success(string $msg = '成功', array $data = []): \support\Response
            {
                return parent::success($msg, $data);
            }

            public function fail(string $msg = '失败', array $data = []): \support\Response
            {
                return parent::fail($msg, $data);
            }
        };
    }

    public function testSuccessEncodesIdField(): void
    {
        $response = $this->controller->success('ok', ['id' => 123]);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame(0, $body['code']);
        $this->assertNotSame(123, $body['data']['id']);
        $this->assertIsString($body['data']['id']);
        $this->assertSame(123, hashids_decode($body['data']['id']));
    }

    public function testFailEncodesIdField(): void
    {
        $response = $this->controller->fail('error', ['id' => 999]);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame(1, $body['code']);
        $this->assertNotSame(999, $body['data']['id']);
        $this->assertSame(999, hashids_decode($body['data']['id']));
    }

    public function testJsonEncodesUserIdSuffix(): void
    {
        $response = $this->controller->json(0, 'ok', ['user_id' => 500]);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertIsString($body['data']['user_id']);
        $this->assertSame(500, hashids_decode($body['data']['user_id']));
    }

    public function testJsonEncodesNestedIds(): void
    {
        $response = $this->controller->json(0, 'ok', [
            'item' => ['id' => 77, 'name' => 'test'],
        ]);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertIsString($body['data']['item']['id']);
        $this->assertSame(77, hashids_decode($body['data']['item']['id']));
        $this->assertSame('test', $body['data']['item']['name']);
    }

    public function testJsonLeavesNonIdFieldsUntouched(): void
    {
        $response = $this->controller->json(0, 'ok', [
            'title' => 'Hello',
            'count' => 42,
            'active' => true,
        ]);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame('Hello', $body['data']['title']);
        $this->assertSame(42, $body['data']['count']);
        $this->assertTrue($body['data']['active']);
    }

    public function testJsonZeroIdStaysZero(): void
    {
        $response = $this->controller->json(0, 'ok', ['id' => 0]);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame(0, $body['data']['id']);
    }

    public function testJsonEmptyDataReturnsEmptyArray(): void
    {
        $response = $this->controller->json(0, 'ok', []);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertSame([], $body['data']);
    }

    public function testJsonResponseStructure(): void
    {
        $response = $this->controller->json(5, 'custom message', ['key' => 'val']);

        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));
        $body = json_decode((string) $response->rawBody(), true);
        $this->assertSame(5, $body['code']);
        $this->assertSame('custom message', $body['msg']);
        $this->assertSame('val', $body['data']['key']);
    }

    public function testJsonEncodesLargeSnowflakeStyleId(): void
    {
        $id = 313568214741291008;
        $response = $this->controller->json(0, 'ok', ['id' => $id]);
        $body = json_decode((string) $response->rawBody(), true);

        $this->assertIsString($body['data']['id']);
        $this->assertSame($id, hashids_decode($body['data']['id']));
    }

    #[DataProvider('idEncodingProvider')]
    public function testJsonEncodesAllIdPatterns(array $input, array $expectedKeys): void
    {
        $response = $this->controller->json(0, 'ok', $input);
        $body = json_decode((string) $response->rawBody(), true);

        foreach ($expectedKeys as $key) {
            $this->assertIsString(
                $body['data'][$key],
                "Expected '$key' to be encoded as string"
            );
        }
    }

    /** @return iterable<string, array{array, string[]}> */
    public static function idEncodingProvider(): iterable
    {
        yield 'id only' => [['id' => 1], ['id']];
        yield 'suffix _id' => [['admin_id' => 2], ['admin_id']];
        yield 'suffix _ids' => [['tag_ids' => 3], ['tag_ids']];
        yield 'mixed' => [['id' => 1, 'role_id' => 2, 'name' => 'x'], ['id', 'role_id']];
    }
}
