<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\common\Layui;
use PHPUnit\Framework\TestCase;

/**
 * Layui 表单构建器冒烟测试：HTML 输出包含预期控件骨架，且 label/值做
 * HTML 转义（防 XSS 注入表单属性）。
 */
final class LayuiTest extends TestCase
{
    public function testInputRendersNameAndValue(): void
    {
        $layui = new Layui();
        $layui->input(['field' => 'nickname', 'label' => '昵称', 'props' => ['value' => 'tom']]);

        $html = $layui->html();
        $this->assertStringContainsString('<input type="text" name="nickname" value="tom"', $html);
        $this->assertStringContainsString('layui-form-label', $html);
        $this->assertStringContainsString('昵称', $html);
    }

    public function testInputNumberForcesNumberType(): void
    {
        $layui = new Layui();
        $layui->inputNumber(['field' => 'qty', 'label' => '数量']);

        $this->assertStringContainsString('type="number" name="qty"', $layui->html());
    }

    public function testLabelIsHtmlEscaped(): void
    {
        $layui = new Layui();
        $layui->input(['field' => 'x', 'label' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $layui->html());
        $this->assertStringContainsString('&lt;script&gt;', $layui->html());
    }

    public function testSwitchRendersCheckedState(): void
    {
        $layui = new Layui();
        $layui->switch(['field' => 'enabled', 'label' => '启用', 'props' => ['checked' => true]]);

        $html = $layui->html();
        $this->assertStringContainsString('lay-skin="switch"', $html);
        $this->assertStringContainsString('name="enabled"', $html);
    }

    public function testHtmlIndentReindentsOutput(): void
    {
        $layui = new Layui();
        $layui->input(['field' => 'a']);

        $indented = $layui->html(1);
        $this->assertStringContainsString("\n    ", $indented);
    }
}
