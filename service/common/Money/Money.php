<?php

namespace Common\Money;

/**
 * 金额舍入唯一助手（设计 D4）：纯字符串 bcmath 路径，禁止在计算链中用 (float)/round()。
 * PHP round() 走浮点，对 5+ 位小数金额有二进制精度风险；此处按第 scale+1 位判断 HALF-UP/HALF-DOWN。
 * 负数按绝对值舍入后回加符号（与 PHP_ROUND_HALF_UP 一致：-0.00005 -> -0.0001）。
 */
class Money
{
    public static function bcround(string $value, int $scale = 4, int $mode = PHP_ROUND_HALF_UP): string
    {
        $neg = bccomp($value, '0', $scale + 2) < 0;
        $abs = $neg ? bcmul($value, '-1', $scale + 2) : $value;

        $truncated = bcadd($abs, '0', $scale + 1);
        $digit     = substr($truncated, -1);
        $roundUp   = $mode === PHP_ROUND_HALF_UP ? $digit >= '5' : $digit > '5';

        $unit     = $scale === 0 ? '1' : '0.' . str_repeat('0', $scale - 1) . '1';
        $rounded  = $roundUp ? bcadd($truncated, $unit, $scale) : bcadd($truncated, '0', $scale);

        return $neg ? bcmul($rounded, '-1', $scale) : $rounded;
    }
}
