<?php

namespace Tests\command;

use App\command\ReconcileCommand;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        $command = new ReconcileCommand();
        $application = new Application();
        $application->add($command);
        return new CommandTester($application->find('payment:reconcile'));
    }

    public function testInvalidDateReturnsFailureWithMessage(): void
    {
        $tester = $this->tester();
        $tester->execute(['--date' => '2026-13-01']);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid date', $tester->getDisplay());
    }

    public function testValidDateWithoutChannelsReturnsSuccess(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->schema()->create('payment_channels', function ($t) {
            $t->increments('id');
            $t->string('code');
            $t->string('status');
        });

        $tester = $this->tester();
        $tester->execute(['--date' => '2026-08-01']);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
