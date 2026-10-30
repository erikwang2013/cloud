<?php

namespace Tests\ticket;

use App\ticket\controller\TicketController;
use Common\hashid\HashidService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Tests\support\RequestMock;

/**
 * AUD-03：普通用户只能回复自己的工单；staff/support 可回复任意工单。
 */
final class TicketReplyAuthorizationTest extends TestCase
{
    private const TICKET_ID = 9001;
    private const OWNER_ID  = 100;
    private const OTHER_ID  = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('tickets', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->string('ticket_no');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->string('category');
            $t->string('priority')->default('normal');
            $t->string('title');
            $t->string('status')->default('open');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->dateTime('sla_deadline')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();
        });
        $schema->create('ticket_messages', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('sender_id');
            $t->string('sender_type');
            $t->text('content');
            $t->timestamps();
        });

        $config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'salt' => getenv('HASHIDS_SALT') ?: 'test-salt',
                    'length' => (int)(getenv('HASHIDS_LENGTH') ?: 12),
                    'alphabet' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
                ],
            ],
        ];
        $manager = new \Erikwang2013\Hashids\HashidsManager($config, new \Erikwang2013\Hashids\HashidsFactory());
        (new \ReflectionProperty(HashidService::class, 'manager'))->setValue(null, $manager);
    }

    private function seedTicket(string $status = 'open'): void
    {
        Capsule::table('tickets')->insert([
            'id' => self::TICKET_ID,
            'ticket_no' => 'TK9001',
            'user_id' => self::OWNER_ID,
            'category' => 'billing',
            'priority' => 'normal',
            'title' => 'Refund question',
            'status' => $status,
            'created_at' => '2026-10-30 10:00:00',
            'updated_at' => '2026-10-30 10:00:00',
        ]);
    }

    private function replyAs(int $userId, string $role, string $content)
    {
        $request = new RequestMock([], ['content' => $content]);
        $request->userId = $userId;
        $request->userRole = $role;

        return (new TicketController())->reply($request, HashidService::encode(self::TICKET_ID));
    }

    public function testUserCannotReplyToAnotherUsersTicket(): void
    {
        $this->seedTicket();

        $this->expectException(ModelNotFoundException::class);
        $this->replyAs(self::OTHER_ID, 'user', 'hijack');
    }

    public function testStaffCanReplyToAnyTicket(): void
    {
        // in_progress 避免 staff 回复 open 工单时触发的状态事件
        $this->seedTicket('in_progress');

        $response = $this->replyAs(self::OTHER_ID, 'support', 'on it');
        $this->assertSame(0, json_decode((string) $response->rawBody(), true)['code']);

        $messages = Capsule::table('ticket_messages')->where('ticket_id', self::TICKET_ID)->get();
        $this->assertCount(1, $messages);
        $this->assertSame(self::OTHER_ID, (int) $messages[0]->sender_id);
        $this->assertSame('staff', $messages[0]->sender_type);
        $this->assertSame('on it', $messages[0]->content);
    }
}
