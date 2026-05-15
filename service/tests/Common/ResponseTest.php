<?php

namespace Tests\Common;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ResponseTest extends TestCase
{
    public function testSuccessStructure(): void
    {
        $result = $this->success(['name' => 'test']);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['code']);
        $this->assertSame('ok', $result['message']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('request_id', $result);
    }

    public function testSuccessWithNullData(): void
    {
        $result = $this->success(null);
        $this->assertSame(0, $result['code']);
        $this->assertNull($result['data']);
    }

    public function testSuccessCustomMessage(): void
    {
        $result = $this->success([], 'created');
        $this->assertSame('created', $result['message']);
    }

    public function testSuccessPreservesDataFields(): void
    {
        $result = $this->success(['name' => 'test', 'email' => 'a@b.com']);
        $this->assertSame('test', $result['data']['name']);
        $this->assertSame('a@b.com', $result['data']['email']);
    }

    public function testErrorStructure(): void
    {
        $result = $this->error(422, 'Validation failed');
        $this->assertSame(422, $result['code']);
        $this->assertSame('Validation failed', $result['message']);
        $this->assertArrayHasKey('request_id', $result);
    }

    public function testErrorWithData(): void
    {
        $result = $this->error(404, 'Not found', ['id' => 42]);
        $this->assertSame(42, $result['data']['id']);
    }

    public function testPaginatedStructure(): void
    {
        $items = [['id' => 1], ['id' => 2]];
        $result = $this->paginated($items, 50, 1, 20);
        $this->assertSame(0, $result['code']);
        $this->assertSame(1, $result['meta']['page']);
        $this->assertSame(20, $result['meta']['page_size']);
        $this->assertSame(50, $result['meta']['total']);
        $this->assertCount(2, $result['data']);
    }

    public function testPaginatedWithEmptyItems(): void
    {
        $result = $this->paginated([], 0, 1, 20);
        $this->assertSame(0, $result['code']);
        $this->assertSame(0, $result['meta']['total']);
        $this->assertEmpty($result['data']);
    }

    public function testRequestIdIsPresent(): void
    {
        $result = $this->success();
        $this->assertNotEmpty($result['request_id']);
    }

    public function testRequestIdIsConsistent(): void
    {
        $a = $this->success();
        $b = $this->error(500, 'err');
        $this->assertSame($a['request_id'], $b['request_id']);
    }

    #[DataProvider('responseCodeProvider')]
    public function testHttpErrorCodes(int $code, string $message): void
    {
        $result = $this->error($code, $message);
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

    // Response helper implementations matching service pattern
    private function success($data = null, string $message = 'ok', array $meta = []): array
    {
        $body = [
            'code' => 0,
            'message' => $message,
            'data' => $data,
            'request_id' => request_id(),
        ];
        if ($meta) {
            $body['meta'] = $meta;
        }
        return $body;
    }

    private function error(int $code, string $message, $data = null): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'request_id' => request_id(),
        ];
    }

    private function paginated($items, int $total, int $page, int $pageSize): array
    {
        return $this->success($items, 'ok', [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ]);
    }
}
