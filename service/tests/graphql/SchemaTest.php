<?php

namespace Tests\graphql;

use App\graphql\Schema;
use GraphQL\Type\Definition\ObjectType;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testFullSchemaExposesQueryFields(): void
    {
        $schema = Schema::full();

        $query = $schema->getQueryType();
        $this->assertInstanceOf(ObjectType::class, $query);
        $this->assertSame('Query', $query->name);

        foreach (['products', 'myResources', 'myOrders'] as $field) {
            $this->assertTrue($query->hasField($field), "missing field: {$field}");
        }
    }

    public function testProductsFieldHasCategoryAndSearchArgs(): void
    {
        $field = Schema::full()->getQueryType()->getField('products');

        $this->assertNotNull($field);
        $argNames = array_map(fn ($arg) => $arg->name, $field->args);
        $this->assertContains('category_id', $argNames);
        $this->assertContains('search', $argNames);
        $this->assertIsCallable($field->resolveFn);
    }

    public function testMyResourcesAndOrdersResolversAreCallable(): void
    {
        $query = Schema::full()->getQueryType();

        $this->assertIsCallable($query->getField('myResources')->resolveFn);
        $this->assertIsCallable($query->getField('myOrders')->resolveFn);
    }

    public function testPublicSchemaExposesOnlyProducts(): void
    {
        $query = Schema::publicSchema()->getQueryType();

        $this->assertTrue($query->hasField('products'));
        $this->assertFalse($query->hasField('myResources'));
        $this->assertFalse($query->hasField('myOrders'));
    }
}
