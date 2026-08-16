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
use ReflectionClass;
use ReflectionMethod;
use support\Response;
use tests\Support\HttpRequestMock;

/**
 * Direct coverage of every admin controller: model wiring, CRUD surface,
 * and the GET (view) success paths for Crud-based controllers.
 */
final class AdminControllersTest extends TestCase
{
    private const CONTROLLER_DIR = __DIR__ . '/../app/controller';
    private const SKIP = ['Base', 'Crud'];

    public static function crudControllerProvider(): array
    {
        $cases = [];
        foreach (glob(self::CONTROLLER_DIR . '/*.php') as $file) {
            $name = basename($file, '.php');
            if (in_array($name, self::SKIP, true)) {
                continue;
            }
            $class = "app\\controller\\$name";
            $ref = new ReflectionClass($class);
            if ($ref->isSubclassOf(Crud::class)) {
                $cases[$name] = [$class, $name];
            }
        }
        return $cases;
    }

    public static function standaloneControllerProvider(): array
    {
        $cases = [];
        foreach (glob(self::CONTROLLER_DIR . '/*.php') as $file) {
            $name = basename($file, '.php');
            if (in_array($name, self::SKIP, true)) {
                continue;
            }
            $class = "app\\controller\\$name";
            $ref = new ReflectionClass($class);
            if (!$ref->isSubclassOf(Crud::class)) {
                $cases[$name] = [$class, $name];
            }
        }
        return $cases;
    }

    #[DataProvider('crudControllerProvider')]
    public function testCrudControllerWiresExpectedModel(string $class, string $name): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue($ref->isSubclassOf(Crud::class), "$class must extend Crud");

        $instance = $ref->newInstance();
        $modelProp = (new ReflectionClass(Crud::class))->getProperty('model');
        $modelProp->setAccessible(true);
        $model = $modelProp->getValue($instance);

        $expected = "app\\model\\" . substr($name, 0, -10); // strip "Controller" suffix
        $this->assertInstanceOf(\support\Model::class, $model, "$class must wire a model");
        if (class_exists($expected)) {
            $this->assertInstanceOf($expected, $model, "$class must wire model $expected");
        }
    }

    #[DataProvider('crudControllerProvider')]
    public function testCrudControllerExposesCrudSurface(string $class): void
    {
        foreach (['select', 'insert', 'update', 'delete'] as $method) {
            $this->assertTrue(method_exists($class, $method), "$class must expose $method()");
        }
    }

    #[DataProvider('crudControllerProvider')]
    public function testIndexRendersHtmlView(string $class): void
    {
        if (!method_exists($class, 'index')) {
            $this->markTestSkipped("$class has no index()");
        }
        $response = (new $class())->index();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->rawBody();
        $this->assertNotSame('', $body);
        $this->assertStringContainsString('<', $body, 'index() must render HTML, not an empty response');
    }

    #[DataProvider('crudControllerProvider')]
    public function testInsertAndUpdateGetBranchesRenderViews(string $class): void
    {
        $instance = new $class();
        $viewBranches = 0;
        foreach (['insert', 'update'] as $method) {
            $refMethod = new ReflectionMethod($instance, $method);
            if ($refMethod->getDeclaringClass()->getName() === Crud::class) {
                // Inherited Crud::insert/update are POST-only DB paths — covered by CrudHashidsTest.
                continue;
            }
            $body = $this->methodBody($refMethod);
            if (!str_contains($body, 'raw_view')) {
                // Custom action with no GET view branch (e.g. AccountController profile update).
                continue;
            }
            $viewBranches++;
            $response = $instance->$method(new HttpRequestMock([], [], 'GET'));
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(200, $response->getStatusCode(), "$class::$method GET must render a 200 view");
            $this->assertStringContainsString('<', (string) $response->rawBody(), "$class::$method GET must render HTML");
        }
        if ($viewBranches === 0) {
            $this->markTestSkipped("$class has no GET view branches in insert/update");
        }
    }

    private function methodBody(ReflectionMethod $method): string
    {
        $file = file($method->getFileName());
        return implode('', array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    #[DataProvider('standaloneControllerProvider')]
    public function testStandaloneControllerAnnotationsAreValid(string $class): void
    {
        $ref = new ReflectionClass($class);
        foreach (['noNeedLogin', 'noNeedAuth'] as $property) {
            $value = $ref->hasProperty($property)
                ? $ref->getProperty($property)->getValue($ref->newInstance())
                : [];
            $this->assertIsArray($value, "$class::$property must be an array");
            foreach ($value as $action) {
                $this->assertIsString($action, "$class::$property entries must be action names");
            }
        }
    }
}
