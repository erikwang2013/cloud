<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\model\Dict;
use app\model\Option;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use support\exception\BusinessException;

final class DictTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'mysql');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema('mysql')->create('wa_options', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function testNameConversionRoundTrip(): void
    {
        $this->assertSame('dict_region', Dict::dictNameToOptionName('region'));
        $this->assertSame('region', Dict::optionNameToDictName('dict_region'));
    }

    public function testFilterValueFormatsAndValidates(): void
    {
        $this->assertSame(
            [['value' => '1', 'name' => '香港'], ['value' => '2', 'name' => '美国']],
            Dict::filterValue([['value' => '1', 'name' => '香港'], ['value' => '2', 'name' => '美国']])
        );

        $this->expectException(BusinessException::class);
        Dict::filterValue([['value' => '1']]);
    }

    public function testSaveRequiresLetterInName(): void
    {
        $this->expectException(BusinessException::class);
        Dict::save('123', [['value' => '1', 'name' => 'x']]);
    }

    public function testSaveGetDeleteRoundTrip(): void
    {
        Dict::save('region', [['value' => '1', 'name' => '香港']]);

        $stored = Option::where('name', 'dict_region')->value('value');
        $this->assertSame('[{"value":"1","name":"香港"}]', $stored);

        $this->assertSame([['value' => '1', 'name' => '香港']], Dict::get('region'));

        Dict::save('region', [['value' => '2', 'name' => '美国']]);
        $this->assertSame([['value' => '2', 'name' => '美国']], Dict::get('region'), '同名保存必须覆盖');

        Dict::delete(['region']);
        $this->assertNull(Dict::get('region'));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull(Dict::get('nonexistent'));
    }
}
