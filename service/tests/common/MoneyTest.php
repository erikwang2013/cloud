<?php

namespace Tests\common;

use Common\money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('bcroundProvider')]
    public function testBcround(string $value, int $scale, string $expected): void
    {
        $this->assertSame($expected, Money::bcround($value, $scale));
    }

    public static function bcroundProvider(): array
    {
        return [
            'half up at boundary'          => ['0.00005', 4, '0.0001'],
            'below half stays'             => ['0.00004', 4, '0.0000'],
            'exact scale unchanged'        => ['3.2000', 4, '3.2000'],
            'fewer decimals padded'        => ['3.2', 4, '3.2000'],
            'carry across decimals'        => ['99.99995', 4, '100.0000'],
            'negative half up'             => ['-0.00005', 4, '-0.0001'],
            'negative below half'          => ['-0.00004', 4, '0.0000'],
            'negative carry'               => ['-99.99995', 4, '-100.0000'],
            'zero decimal carry'           => ['100.5', 0, '101'],
            'zero decimal down'            => ['100.4', 0, '100'],
            'zero decimal negative'        => ['-100.5', 0, '-101'],
            'long input still 4dp'         => ['1.2345678', 4, '1.2346'],
        ];
    }

    public function testBcroundIsIdempotent(): void
    {
        $this->assertSame('0.0001', Money::bcround(Money::bcround('0.00005', 4), 4));
        $this->assertSame('100.0000', Money::bcround(Money::bcround('99.99995', 4), 4));
        $this->assertSame('0.0000', Money::bcround(Money::bcround('0.00004', 4), 4));
    }

    public function testBcroundHalfDownTiesStay(): void
    {
        $this->assertSame('0.0000', Money::bcround('0.00005', 4, PHP_ROUND_HALF_DOWN));
        $this->assertSame('0.0000', Money::bcround('-0.00005', 4, PHP_ROUND_HALF_DOWN));
    }
}
