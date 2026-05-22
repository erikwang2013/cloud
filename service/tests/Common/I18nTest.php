<?php

namespace Tests\Common;

use Common\I18n\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        I18n::setLocale('en-US');
    }

    public function testSetLocaleZhCn(): void
    {
        I18n::setLocale('zh-CN');
        $this->assertSame('zh-CN', I18n::getLocale());
    }

    public function testSetLocaleEnUs(): void
    {
        I18n::setLocale('en-US');
        $this->assertSame('en-US', I18n::getLocale());
    }

    public function testTransReturnsChineseForZhCn(): void
    {
        I18n::setLocale('zh-CN');
        $this->assertSame('登录成功', I18n::trans('auth.login_success'));
    }

    public function testTransReturnsEnglishForEnUs(): void
    {
        I18n::setLocale('en-US');
        $this->assertSame('Login successful', I18n::trans('auth.login_success'));
    }

    public function testTransReturnsKeyIfNotFound(): void
    {
        I18n::setLocale('en-US');
        $this->assertSame('nonexistent.key', I18n::trans('nonexistent.key'));
    }

    public function testTransWithReplacements(): void
    {
        I18n::setLocale('zh-CN');
        $result = I18n::trans('validation.required', ['field' => '邮箱']);
        $this->assertSame('邮箱 不能为空', $result);

        I18n::setLocale('en-US');
        $result = I18n::trans('validation.required', ['field' => 'Email']);
        $this->assertSame('Email is required', $result);
    }

    public function testTranslateFieldWithCurrentLocale(): void
    {
        I18n::setLocale('zh-CN');
        $value = ['zh-CN' => '云服务器', 'en-US' => 'Cloud Server'];
        $this->assertSame('云服务器', I18n::translateField($value));

        I18n::setLocale('en-US');
        $this->assertSame('Cloud Server', I18n::translateField($value));
    }

    public function testTranslateFieldFallsBack(): void
    {
        I18n::setLocale('ja-JP');
        $value = ['en-US' => 'Cloud Server'];
        $this->assertSame('Cloud Server', I18n::translateField($value));
    }

    public function testTranslateFieldReturnsNullForEmpty(): void
    {
        $this->assertNull(I18n::translateField(null));
        $this->assertNull(I18n::translateField([]));
    }

    public function testTranslateFieldReturnsFirstValueAsFallback(): void
    {
        I18n::setLocale('ja-JP');
        $value = ['zh-CN' => '云服务器'];
        $this->assertSame('云服务器', I18n::translateField($value));
    }
}
