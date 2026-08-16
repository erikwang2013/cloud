<?php

namespace Tests\WebSocket;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 文档-代码收敛测试：docs/api-reference.md 声明的 5 个推送事件
 * 必须在 service/app 中实际接线（事件名出现在 send/broadcast 调用中），
 * 反之代码中推送的事件也必须在文档中登记。
 */
final class WebSocketEventsWiringTest extends TestCase
{
    /** @var array<string, string> 事件名 => 承载它的源文件（不存在则会在断言中报告） */
    private const WIRED_FILES = [
        'order.paid'            => 'app/WebSocket/Listener/OrderPaidListener.php',
        'ticket.updated'        => 'app/WebSocket/Listener/TicketUpdatedListener.php',
        'notification.new'      => 'app/Notification/Service/NotificationDispatcher.php',
        'resource.provisioned'  => 'app/Provisioning/Queue/ProvisionWorker.php',
        'resource.expiring'     => 'app/Cron/ExpirationCheck.php',
    ];

    private const DOCS_FILE = __DIR__ . '/../../../docs/api-reference.md';

    #[DataProvider('declaredEventsProvider')]
    public function testDeclaredEventIsWired(string $event): void
    {
        $found = false;
        foreach (self::WIRED_FILES as $file) {
            $source = file_get_contents(__DIR__ . '/../../' . $file);
            if ($source !== false && str_contains($source, "'{$event}'")) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "docs 声明的事件 {$event} 在 app 代码中未接线");
    }

    public function testAllWiredEventsAreDeclaredInDocs(): void
    {
        $docs = (string) file_get_contents(self::DOCS_FILE);
        foreach (array_keys(self::WIRED_FILES) as $event) {
            $this->assertStringContainsString($event, $docs, "代码接线的事件 {$event} 未在 docs 登记");
        }
    }

    public function testNotificationNewPayloadMatchesDocs(): void
    {
        $source = file_get_contents(__DIR__ . '/../../' . self::WIRED_FILES['notification.new']);
        $this->assertStringContainsString("'notification_id'", (string) $source);
        $this->assertStringContainsString("'title'", (string) $source);
        $this->assertStringContainsString("'body'", (string) $source);
    }

    public static function declaredEventsProvider(): array
    {
        return [
            'order.paid'           => ['order.paid'],
            'ticket.updated'       => ['ticket.updated'],
            'notification.new'     => ['notification.new'],
            'resource.provisioned' => ['resource.provisioned'],
            'resource.expiring'    => ['resource.expiring'],
        ];
    }
}
