<?php

namespace Tests\admin;

use App\admin\controller\CdnController;
use PHPUnit\Framework\TestCase;

final class CdnUpdatePlanTest extends TestCase
{
    public function testInvalidPlanReturns400(): void
    {
        $request = new class {
            public int $userId = 1;

            public function input(string $name, $default = null)
            {
                return $name === 'plan' ? 'ultimate' : $default;
            }
        };

        // 非法 plan 在 findOrFail 之前返回，零 DB 依赖
        $response = (new CdnController())->updatePlan($request, 1);
        $body     = json_decode($response->rawBody(), true);

        $this->assertSame(400, $body['code']);
    }
}
