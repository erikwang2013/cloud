# Phase 3: 運営の仕上げ — 実装計画

> **エージェントワーカー向け:** 必須サブスキル: この計画をタスク単位で実装するには superpowers:subagent-driven-development（推奨）または superpowers:executing-plans を使用してください。

**ゴール:** チケットシステム、メッセージ通知、モニタリングアラート、管理バックエンドページ、クライアント App の骨格、デプロイ設定を実装します。プラットフォームを正式運営可能な状態にします。

**アーキテクチャ:** チケット/通知はイベント駆動 + Redis Queue で非同期配信します。管理バックエンドは webman-admin ベースで構築します。クライアント App は API 層を共有します。

**技術スタック:** PHP 8.2+, webman-admin, webman redis-queue, Flutter 3.x, HarmonyOS ArkTS, Docker

---

### タスク 3.1: チケットシステム

**対象ファイル:**
- 新規作成: `service/app/ticket/controller/TicketController.php`
- 新規作成: `service/app/ticket/service/TicketService.php`
- 新規作成: `service/app/ticket/model/Ticket.php`
- 新規作成: `service/app/ticket/model/TicketMessage.php`
- 新規作成: `service/app/ticket/event/TicketCreated.php`
- 新規作成: `service/app/ticket/listener/AutoAssignListener.php`

- [ ] **ステップ 1: TicketService の作成**

```php
<?php
namespace App\Ticket\Service;

use App\Ticket\Model\Ticket;
use App\Ticket\Model\TicketMessage;

class TicketService
{
    // SLA deadlines by priority
    private array $slaMinutes = [
        'urgent'  => 30,
        'high'    => 120,
        'normal'  => 480,   // 8 hours
        'low'     => 1440,  // 24 hours
    ];

    public function create(int $userId, array $data): Ticket
    {
        $ticket = Ticket::create([
            'ticket_no'    => 'TK' . date('YmdHis') . rand(100, 999),
            'user_id'      => $userId,
            'resource_id'  => $data['resource_id'] ?? null,
            'category'     => $data['category'],    // billing/technical/abuse/general
            'priority'     => $data['priority'] ?? 'normal',
            'title'        => $data['title'],
            'status'       => 'open',
            'sla_deadline' => date('Y-m-d H:i:s', time() + $this->slaMinutes[$data['priority'] ?? 'normal'] * 60),
        ]);

        TicketMessage::create([
            'ticket_id'   => $ticket->id,
            'sender_id'   => $userId,
            'sender_type' => 'user',
            'content'     => $data['content'],
        ]);

        // Fire event for auto-assignment
        event(new TicketCreated($ticket));

        return $ticket->load('messages');
    }

    public function reply(int $ticketId, int $senderId, string $senderType, string $content): TicketMessage
    {
        $ticket = Ticket::findOrFail($ticketId);

        if ($ticket->status === 'closed') {
            throw new \InvalidArgumentException('Ticket is closed');
        }

        // Re-open if user replies to on-hold ticket
        if ($ticket->status === 'on_hold' && $senderType === 'user') {
            $ticket->update(['status' => 'open']);
        }

        // Mark as in_progress on first staff reply
        if ($senderType === 'staff' && $ticket->status === 'open') {
            $ticket->update([
                'status'      => 'in_progress',
                'assigned_to' => $senderId,
            ]);
        }

        return TicketMessage::create([
            'ticket_id'   => $ticketId,
            'sender_id'   => $senderId,
            'sender_type' => $senderType,
            'content'     => $content,
        ]);
    }

    public function close(int $ticketId, int $staffId): void
    {
        Ticket::where('id', $ticketId)->update([
            'status'     => 'closed',
            'closed_by'  => $staffId,
            'closed_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function assignTicket(int $ticketId, int $staffId): void
    {
        Ticket::where('id', $ticketId)->update(['assigned_to' => $staffId]);
    }

    // Auto-assign: round-robin among support staff
    public function autoAssign(Ticket $ticket): void
    {
        $supportStaff = \App\User\Model\User::where('role', 'support')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($supportStaff->isEmpty()) return;

        // Simple round-robin: assign to staff with fewest open tickets
        $bestStaff = $supportStaff->sortBy(function ($staff) {
            return Ticket::where('assigned_to', $staff->id)
                ->whereIn('status', ['open', 'in_progress'])
                ->count();
        })->first();

        $ticket->update(['assigned_to' => $bestStaff->id]);
    }
}
```

- [ ] **ステップ 2: TicketController の作成**

```php
<?php
namespace App\Ticket\Controller;

use App\Ticket\Service\TicketService;
use App\Ticket\Model\Ticket;
use Common\Helper\Response;

class TicketController
{
    private TicketService $service;

    public function __construct()
    {
        $this->service = new TicketService();
    }

    public function create($request)
    {
        $data = $request->only(['resource_id', 'category', 'priority', 'title', 'content']);
        $ticket = $this->service->create($request->userId, $data);
        return json(Response::success($ticket, 'Ticket created'));
    }

    public function myTickets($request)
    {
        $tickets = Ticket::where('user_id', $request->userId)
            ->with(['latestMessage'])
            ->orderBy('updated_at', 'desc')
            ->paginate(20);
        return json(Response::paginated($tickets->items(), $tickets->total(), $request->input('page', 1), 20));
    }

    public function show($request, int $id)
    {
        $ticket = Ticket::with('messages')->findOrFail($id);
        if ($request->userRole === 'user' && $ticket->user_id !== $request->userId) {
            return json(Response::error(403, 'Forbidden'));
        }
        return json(Response::success($ticket));
    }

    public function reply($request, int $id)
    {
        $senderType = in_array($request->userRole, ['admin', 'support', 'super_admin']) ? 'staff' : 'user';
        $msg = $this->service->reply($id, $request->userId, $senderType, $request->input('content'));
        return json(Response::success($msg, 'Reply sent'));
    }

    public function close($request, int $id)
    {
        $this->service->close($id, $request->userId);
        return json(Response::success(null, 'Ticket closed'));
    }

    // Admin: list all tickets (with filters)
    public function index($request)
    {
        $query = Ticket::query();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }
        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }
        if ($assignedTo = $request->input('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        // SLA breach filter
        if ($request->input('sla_breached')) {
            $query->where('sla_deadline', '<', date('Y-m-d H:i:s'))
                  ->whereIn('status', ['open', 'in_progress']);
        }

        $tickets = $query->with('user.profile')->orderBy('priority_order')->orderBy('created_at')->paginate(30);
        return json(Response::paginated($tickets->items(), $tickets->total(), $request->input('page', 1), 30));
    }
}
```

- [ ] **ステップ 3: AutoAssignListener の作成**

```php
<?php
namespace App\Ticket\Listener;

use App\Ticket\Event\TicketCreated;
use App\Ticket\Service\TicketService;

class AutoAssignListener
{
    public function handle(TicketCreated $event): void
    {
        $service = new TicketService();
        $service->autoAssign($event->ticket);
    }
}
```

- [ ] **ステップ 4: コミット**

```bash
git add service/app/ticket/
git commit -m "feat: implement ticket system with auto-assign and SLA tracking"
```

---

### タスク 3.2: 通知システム

**対象ファイル:**
- 新規作成: `service/app/notification/service/NotificationDispatcher.php`
- 新規作成: `service/app/notification/queue/EmailSender.php`
- 新規作成: `service/app/notification/queue/SmsSender.php`
- 新規作成: `service/app/notification/queue/PushSender.php`
- 新規作成: `service/app/notification/model/Notification.php`
- 新規作成: `service/app/notification/model/NotificationTemplate.php`

- [ ] **ステップ 1: NotificationDispatcher の作成**

```php
<?php
namespace App\Notification\Service;

use App\Notification\Model\Notification;
use App\Notification\Model\NotificationTemplate;
use Common\I18n\I18n;

class NotificationDispatcher
{
    /**
     * Dispatch notification to user's preferred channels
     *
     * @param int    $userId   Recipient user ID
     * @param string $code     Template code, e.g. 'order_paid', 'resource_expiring'
     * @param array  $data     Template variables: ['order_no' => '...', 'amount' => '...']
     * @param array  $channels Override channels, empty = user's preference
     */
    public function dispatch(int $userId, string $code, array $data = [], array $channels = []): void
    {
        $user = \App\User\Model\User::with('profile')->find($userId);
        if (!$user || $user->status !== 'active') return;

        // Get user's language preference
        $locale = $user->language ?? config('i18n.default_locale');
        I18n::setLocale($locale);

        // Load template
        $template = NotificationTemplate::where('code', $code)->first();
        if (!$template) return;

        // Render content
        $title = $this->renderTemplate($template->getLocalizedTitle($locale), $data);
        $body  = $this->renderTemplate($template->getLocalizedBody($locale), $data);

        // Determine channels
        if (empty($channels)) {
            $prefs  = json_decode($user->notification_prefs ?? '{}', true);
            $userChannels = $prefs[$code] ?? $template->channels;
            $channels = is_array($userChannels) ? $userChannels : explode(',', $template->channels);
        }

        // Write to notifications table (in-app)
        if (in_array('in_app', $channels)) {
            Notification::create([
                'user_id'       => $userId,
                'channel'       => 'in_app',
                'template_code' => $code,
                'content'       => json_encode(['title' => $title, 'body' => $body]),
                'send_status'   => 'sent',
            ]);
        }

        // Push to queue for async channels
        if (in_array('email', $channels) && $user->email) {
            \Webman\RedisQueue\Client::send('notification_email', [
                'to'      => $user->email,
                'title'   => $title,
                'body'    => $body,
                'user_id' => $userId,
                'code'    => $code,
            ]);
        }

        if (in_array('sms', $channels) && $user->phone) {
            \Webman\RedisQueue\Client::send('notification_sms', [
                'to'      => $user->phone,
                'body'    => $body,
                'user_id' => $userId,
                'code'    => $code,
            ]);
        }

        if (in_array('push', $channels)) {
            \Webman\RedisQueue\Client::send('notification_push', [
                'user_id' => $userId,
                'title'   => $title,
                'body'    => $body,
                'code'    => $code,
            ]);
        }
    }

    private function renderTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("{{{$key}}}", $value, $template);
        }
        return $template;
    }
}
```

- [ ] **ステップ 2: EmailSender キューワーカーの作成**

```php
<?php
namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;

class EmailSender implements Consumer
{
    public string $queue = 'notification_email';

    public function consume($data)
    {
        try {
            // Send via configured mailer
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host       = getenv('SMTP_HOST');
            $mailer->SMTPAuth   = true;
            $mailer->Username   = getenv('SMTP_USERNAME');
            $mailer->Password   = getenv('SMTP_PASSWORD');
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Port       = 587;

            $mailer->setFrom(getenv('MAIL_FROM_ADDRESS'), getenv('MAIL_FROM_NAME'));
            $mailer->addAddress($data['to']);
            $mailer->isHTML(true);
            $mailer->Subject = $data['title'];
            $mailer->Body    = $data['body'];

            $mailer->send();

            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'email',
                'template_code' => $data['code'],
                'content'       => json_encode(['title' => $data['title'], 'body' => $data['body']]),
                'send_status'   => 'sent',
            ]);

        } catch (\Exception $e) {
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'email',
                'template_code' => $data['code'],
                'content'       => json_encode(['to' => $data['to']]),
                'send_status'   => 'failed',
            ]);
            throw $e; // Re-throw for queue retry
        }
    }
}
```

- [ ] **ステップ 3: SmsSender キューワーカーの作成**

```php
<?php
namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;

class SmsSender implements Consumer
{
    public string $queue = 'notification_sms';

    public function consume($data)
    {
        try {
            // Twilio SMS (or Alibaba Cloud SMS for Chinese users)
            $twilio = new \Twilio\Rest\Client(
                getenv('TWILIO_ACCOUNT_SID'),
                getenv('TWILIO_AUTH_TOKEN')
            );

            $twilio->messages->create($data['to'], [
                'from' => getenv('TWILIO_PHONE_NUMBER'),
                'body' => $data['body'],
            ]);

            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'sms',
                'template_code' => $data['code'],
                'content'       => json_encode(['body' => $data['body']]),
                'send_status'   => 'sent',
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'sms',
                'template_code' => $data['code'],
                'send_status'   => 'failed',
            ]);
            throw $e;
        }
    }
}
```

- [ ] **ステップ 4: PushSender キューワーカーの作成**

```php
<?php
namespace App\Notification\Queue;

use Webman\RedisQueue\Consumer;
use App\Notification\Model\Notification;

class PushSender implements Consumer
{
    public string $queue = 'notification_push';

    public function consume($data)
    {
        try {
            // Firebase Cloud Messaging (FCM) for Flutter
            $fcm = new \Google\Client();
            // ... FCM send logic using service account credentials

            // Record success
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'push',
                'template_code' => $data['code'],
                'content'       => json_encode(['title' => $data['title'], 'body' => $data['body']]),
                'send_status'   => 'sent',
            ]);
        } catch (\Exception $e) {
            Notification::create([
                'user_id'       => $data['user_id'],
                'channel'       => 'push',
                'template_code' => $data['code'],
                'send_status'   => 'failed',
            ]);
            throw $e;
        }
    }
}
```

- [ ] **ステップ 5: コミット**

```bash
git add service/app/notification/
git commit -m "feat: implement notification system (email/sms/push/in-app with queue workers)"
```

---

### タスク 3.3: モニタリングとアラート

**対象ファイル:**
- 新規作成: `service/app/monitor/controller/MonitorController.php`
- 新規作成: `service/app/monitor/service/ResourceMonitor.php`
- 新規作成: `service/app/monitor/service/AlertEngine.php`
- 新規作成: `service/app/monitor/cron/CollectMetrics.php`
- 新規作成: `service/app/monitor/cron/CheckExpirations.php`

- [ ] **ステップ 1: ResourceMonitor の作成**

```php
<?php
namespace App\Monitor\Service;

use App\Provisioning\Model\Resource;
use App\Provisioning\Model\Disk;
use App\Provisioning\Model\HostMachine;
use App\Provisioning\Service\ProviderFactory;
use App\Provisioning\Model\ProvisionTask;

class ResourceMonitor
{
    private ProviderFactory $factory;

    public function __construct()
    {
        $this->factory = new ProviderFactory();
    }

    // Collect metrics from all active resources
    public function collectAllMetrics(): void
    {
        $resources = Resource::where('status', 'active')
            ->where('type', 'server')
            ->get();

        foreach ($resources as $resource) {
            $task = ProvisionTask::where('resource_id', $resource->id)->first();
            if (!$task) continue;

            $provider = $this->factory->create($task);
            $status   = $provider->status($resource);

            // Store metrics
            Redis::hset("resource:{$resource->id}:status", 'status', $status->status);
            Redis::hset("resource:{$resource->id}:metrics", 'cpu', $status->metrics['cpu_percent'] ?? 0);
            Redis::hset("resource:{$resource->id}:metrics", 'mem', $status->metrics['mem_percent'] ?? 0);
            Redis::expire("resource:{$resource->id}:metrics", 3600);

            // Check if VM is down
            if ($status->status === 'stopped' || $status->status === 'error') {
                $this->checkDowntime($resource);
            }
        }
    }

    private function checkDowntime(Resource $resource): void
    {
        $key = "downtime:{$resource->id}";
        $count = Redis::incr($key);
        Redis::expire($key, 600); // 10 min window

        if ($count >= 3) {
            // Alert: server down
            $alertEngine = new AlertEngine();
            $alertEngine->trigger('server_down', $resource, [
                'consecutive_checks' => $count,
            ]);
        }
    }

    // Check resources nearing expiration
    public function checkExpirations(): void
    {
        $windows = [7, 3, 1]; // days before expiry

        foreach ($windows as $days) {
            $expiring = Resource::where('status', 'active')
                ->whereBetween('expired_at', [
                    date('Y-m-d H:i:s', strtotime("+{$days} days")),
                    date('Y-m-d H:i:s', strtotime("+{$days} days + 1 hour")),
                ])
                ->get();

            foreach ($expiring as $resource) {
                $dispatcher = new \App\Notification\Service\NotificationDispatcher();
                $dispatcher->dispatch($resource->user_id, 'resource_expiring', [
                    'resource_type' => $resource->type,
                    'days'          => $days,
                    'expired_at'    => $resource->expired_at,
                ]);
            }
        }
    }

    // Check SSL certificate expiration
    public function checkSslCertificates(): void
    {
        $domains = Resource::where('type', 'domain')->where('status', 'active')->get();

        foreach ($domains as $domain) {
            $url = "https://{$domain->domain_name}";
            $cert = $this->getCertInfo($url);

            if ($cert && isset($cert['validTo_time_t'])) {
                $daysLeft = ($cert['validTo_time_t'] - time()) / 86400;

                if ($daysLeft <= 30) {
                    $alertEngine = new AlertEngine();
                    $alertEngine->trigger('ssl_expiring', $domain, [
                        'domain'   => $domain->domain_name,
                        'days_left'=> round($daysLeft),
                    ]);
                }
            }
        }
    }

    private function getCertInfo(string $url): ?array
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
        $stream  = @stream_socket_client("ssl://{$url}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) return null;

        $cert = stream_context_get_params($stream);
        fclose($stream);

        return openssl_x509_parse($cert['options']['ssl']['peer_certificate']);
    }
}
```

- [ ] **ステップ 2: AlertEngine の作成**

```php
<?php
namespace App\Monitor\Service;

class AlertEngine
{
    private array $alertRules = [
        'server_down' => [
            'severity' => 'critical',
            'notify'   => ['in_app', 'email', 'sms'],
        ],
        'cpu_high' => [
            'severity' => 'warning',
            'threshold'=> ['cpu_percent' => 90, 'duration_minutes' => 10],
            'notify'   => ['in_app', 'email'],
        ],
        'disk_high' => [
            'severity' => 'warning',
            'threshold'=> ['disk_percent' => 90, 'duration_minutes' => 5],
            'notify'   => ['in_app', 'email'],
        ],
        'ssl_expiring' => [
            'severity' => 'warning',
            'threshold'=> ['days_left' => 30],
            'notify'   => ['in_app', 'email'],
        ],
        'domain_expiring' => [
            'severity' => 'warning',
            'threshold'=> ['days_left' => 30],
            'notify'   => ['in_app', 'email'],
        ],
        'provision_failed' => [
            'severity' => 'critical',
            'notify'   => ['in_app', 'email', 'sms'],
        ],
    ];

    public function trigger(string $ruleCode, $resource, array $context = []): void
    {
        $rule = $this->alertRules[$ruleCode] ?? null;
        if (!$rule) return;

        // Log alert
        \App\Monitor\Model\Alert::create([
            'rule_code'    => $ruleCode,
            'severity'     => $rule['severity'],
            'resource_id'  => $resource->id ?? null,
            'user_id'      => $resource->user_id ?? 0,
            'context'      => json_encode($context),
            'status'       => 'triggered',
        ]);

        // Notify user
        $dispatcher = new \App\Notification\Service\NotificationDispatcher();
        $dispatcher->dispatch(
            $resource->user_id ?? 0,
            'alert_' . $ruleCode,
            array_merge($context, ['resource_type' => $resource->type ?? 'unknown']),
            $rule['notify']
        );

        // P0/P1: also notify on-call staff
        if (in_array($rule['severity'], ['critical', 'major'])) {
            $this->notifyOnCall($ruleCode, $resource, $context);
        }
    }

    private function notifyOnCall(string $ruleCode, $resource, array $context): void
    {
        $oncallStaff = \App\User\Model\User::where('role', 'admin')
            ->where('status', 'active')
            ->get();

        foreach ($oncallStaff as $staff) {
            $dispatcher = new \App\Notification\Service\NotificationDispatcher();
            $dispatcher->dispatch($staff->id, 'alert_oncall', array_merge($context, [
                'rule_code'    => $ruleCode,
                'resource_id'  => $resource->id ?? 'N/A',
            ]), ['sms']);
        }
    }
}
```

- [ ] **ステップ 3: cron タスクの作成**

```php
<?php
// service/app/monitor/cron/CollectMetrics.php
// Run every 5 minutes via crontab:
// */5 * * * * php /path/to/service/app/monitor/cron/CollectMetrics.php

require_once __DIR__ . '/../../../start.php';

$monitor = new \App\Monitor\Service\ResourceMonitor();
$monitor->collectAllMetrics();
echo "Metrics collected at " . date('Y-m-d H:i:s') . "\n";
```

```php
<?php
// service/app/monitor/cron/CheckExpirations.php
// Run every hour via crontab:
// 7 * * * * php /path/to/service/app/monitor/cron/CheckExpirations.php

require_once __DIR__ . '/../../../start.php';

$monitor = new \App\Monitor\Service\ResourceMonitor();
$monitor->checkExpirations();
$monitor->checkSslCertificates();
echo "Expiration checks completed at " . date('Y-m-d H:i:s') . "\n";
```

- [ ] **ステップ 4: 管理モニタリングダッシュボードコントローラの作成**

```php
<?php
namespace App\Monitor\Controller;

use App\Provisioning\Model\Resource;
use Common\Helper\Response;

class MonitorController
{
    public function dashboard()
    {
        return json(Response::success([
            'total_resources'      => Resource::count(),
            'active_resources'     => Resource::where('status', 'active')->count(),
            'resources_by_type'    => Resource::selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type'),
            'resources_by_region'  => Resource::selectRaw('region_id, count(*) as count')->where('status', 'active')->groupBy('region_id')->with('region')->get(),
            'recent_alerts'        => \App\Monitor\Model\Alert::orderBy('created_at', 'desc')->take(20)->get(),
            'provisioning_queue'   => \App\Provisioning\Model\ProvisionTask::where('status', 'pending')->count(),
        ]));
    }

    public function resourceMetrics($request, int $id)
    {
        $metrics = [
            'cpu'    => Redis::hget("resource:{$id}:metrics", 'cpu') ?? 0,
            'memory' => Redis::hget("resource:{$id}:metrics", 'mem') ?? 0,
            'disk'   => Redis::hget("resource:{$id}:metrics", 'disk') ?? 0,
            'status' => Redis::hget("resource:{$id}:status", 'status') ?? 'unknown',
        ];
        return json(Response::success($metrics));
    }
}
```

- [ ] **ステップ 5: コミット**

```bash
git add service/app/monitor/
git commit -m "feat: implement monitoring, alerting, and cron jobs"
```

---

### タスク 3.4: 管理バックエンド（webman-admin ページ）

**対象ファイル:**
- 新規作成: `service/app/admin/controller/DashboardController.php`
- 新規作成: `service/app/admin/controller/UserController.php`
- 新規作成: `service/app/admin/controller/ProductController.php`
- 新規作成: `service/app/admin/controller/OrderController.php`
- 新規作成: `service/app/admin/controller/PaymentController.php`
- 新規作成: `service/app/admin/controller/SupplierController.php`

- [ ] **ステップ 1: DashboardController の作成**

```php
<?php
namespace App\Admin\Controller;

use App\Order\Model\Order;
use App\User\Model\User;
use App\Provisioning\Model\Resource;
use Common\Helper\Response;

class DashboardController
{
    public function index()
    {
        $today = date('Y-m-d');

        $todayOrders = Order::whereDate('created_at', $today)->count();
        $todayRevenue = Order::whereDate('paid_at', $today)->where('status', '!=', 'refunded')->sum('total');
        $newUsers     = User::whereDate('created_at', $today)->count();
        $activeResources = Resource::where('status', 'active')->count();

        // 30-day trend
        $thirtyDays = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $thirtyDays[$date] = Order::whereDate('paid_at', $date)
                ->where('status', '!=', 'refunded')
                ->sum('total');
        }

        // Region distribution
        $regionDistribution = Resource::where('status', 'active')
            ->selectRaw('region_id, count(*) as count')
            ->groupBy('region_id')
            ->with('region')
            ->get();

        return json(Response::success([
            'today_stats' => compact('todayOrders', 'todayRevenue', 'newUsers', 'activeResources'),
            'revenue_trend_30d' => $thirtyDays,
            'region_distribution' => $regionDistribution,
            'pending_orders' => Order::where('status', 'pending')->count(),
            'pending_kyc'    => \App\User\Model\UserKyc::where('status', 'pending')->count(),
            'open_tickets'   => \App\Ticket\Model\Ticket::whereIn('status', ['open', 'in_progress'])->count(),
        ]));
    }

    // KYC review
    public function kycList()
    {
        $kycs = \App\User\Model\UserKyc::with('user.profile')
            ->orderBy('created_at')
            ->paginate(20);
        return json(Response::paginated($kycs->items(), $kycs->total(), request()->input('page', 1), 20));
    }

    public function kycApprove($request, int $id)
    {
        $kyc = \App\User\Model\UserKyc::findOrFail($id);
        $kyc->update([
            'status'      => 'approved',
            'verified_by' => $request->userId,
            'verified_at' => date('Y-m-d H:i:s'),
        ]);
        return json(Response::success(null, 'KYC approved'));
    }

    public function kycReject($request, int $id)
    {
        $kyc = \App\User\Model\UserKyc::findOrFail($id);
        $kyc->update([
            'status'        => 'rejected',
            'verified_by'   => $request->userId,
            'reject_reason' => $request->input('reason'),
        ]);
        return json(Response::success(null, 'KYC rejected'));
    }
}
```

- [ ] **ステップ 2: 注文管理の管理コントローラの作成**

```php
<?php
namespace App\Admin\Controller;

use App\Order\Model\Order;
use App\Order\Model\OrderTimeline;
use Common\Helper\Response;

class OrderController
{
    public function index($request)
    {
        $query = Order::with(['user.profile', 'items']);

        if ($status = $request->input('status')) $query->where('status', $status);
        if ($type = $request->input('type')) $query->where('type', $type);
        if ($userId = $request->input('user_id')) $query->where('user_id', $userId);
        if ($orderNo = $request->input('order_no')) $query->where('order_no', $orderNo);
        if ($dateStart = $request->input('date_start')) $query->whereDate('created_at', '>=', $dateStart);
        if ($dateEnd = $request->input('date_end')) $query->whereDate('created_at', '<=', $dateEnd);

        $orders = $query->orderBy('created_at', 'desc')->paginate(30);
        return json(Response::paginated($orders->items(), $orders->total(), $request->input('page', 1), 30));
    }

    public function show(int $id)
    {
        $order = Order::with(['items', 'timeline', 'transactions', 'resources', 'refund'])->findOrFail($id);
        return json(Response::success($order));
    }

    public function refund($request, int $id)
    {
        $data = $request->only(['amount', 'reason']);

        $order = Order::findOrFail($id);
        if (!in_array($order->status, ['paid', 'completed'])) {
            return json(Response::error(422, 'Order cannot be refunded'));
        }

        // Create refund record
        $refund = \App\Order\Model\Refund::create([
            'order_id'  => $order->id,
            'user_id'   => $order->user_id,
            'amount'    => $data['amount'],
            'reason'    => $data['reason'],
            'status'    => 'pending',
        ]);

        // Submit for approval (2-step process)
        // In production, this goes through approval workflow

        OrderTimeline::create([
            'order_id' => $order->id,
            'status'   => 'refunding',
            'operator' => "admin:{$request->userId}",
            'remark'   => "Refund requested: {$data['amount']} {$order->currency}",
        ]);

        return json(Response::success($refund, 'Refund request submitted'));
    }
}
```

- [ ] **ステップ 3: コミット**

```bash
git add service/app/admin/
git commit -m "feat: implement admin backend controllers (dashboard, users, orders, KYC)"
```

---

### タスク 3.5: Flutter アプリの骨格

**対象ファイル:**
- 新規作成: `apps/flutter/` プロジェクト構造
- 新規作成: `apps/flutter/lib/core/`（network, auth, i18n, theme）
- 新規作成: `apps/flutter/lib/features/`（auth, products, orders, resources）

- [ ] **ステップ 1: Flutter プロジェクトの初期化**

```bash
cd apps && flutter create --org com.cloudplatform --project-name cloud_platform flutter
```

- [ ] **ステップ 2: コアネットワーク層の作成** — `apps/flutter/lib/core/network/api_client.dart`

```dart
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ApiClient {
  late final Dio dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  static const String baseUrl = 'https://api.example.com/api';

  ApiClient() {
    dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 10),
      headers: {'Content-Type': 'application/json'},
    ));

    dio.interceptors.add(AuthInterceptor(_storage));
    dio.interceptors.add(LocaleInterceptor());
    dio.interceptors.add(LogInterceptor(requestBody: true, responseBody: true));
  }
}

class AuthInterceptor extends Interceptor {
  final FlutterSecureStorage _storage;

  AuthInterceptor(this._storage);

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await _storage.read(key: 'access_token');
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode == 401) {
      // Try refresh token
      final refreshed = await _tryRefresh();
      if (refreshed) {
        // Retry the request
        final response = await dio.fetch(err.requestOptions);
        handler.resolve(response);
        return;
      }
    }
    handler.next(err);
  }

  Future<bool> _tryRefresh() async {
    final refreshToken = await _storage.read(key: 'refresh_token');
    if (refreshToken == null) return false;

    try {
      final response = await Dio().post('$baseUrl/auth/refresh', data: {
        'refresh_token': refreshToken,
      });
      await _storage.write(key: 'access_token', value: response.data['data']['access_token']);
      await _storage.write(key: 'refresh_token', value: response.data['data']['refresh_token']);
      return true;
    } catch (_) {
      await _storage.deleteAll();
      return false;
    }
  }
}

class LocaleInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    // Use device locale
    options.headers['Accept-Language'] = 'zh-CN';
    handler.next(options);
  }
}
```

- [ ] **ステップ 3: フィーチャー構造の作成**

```
apps/flutter/lib/
├── main.dart
├── core/
│   ├── network/
│   │   ├── api_client.dart
│   │   └── api_response.dart
│   ├── auth/
│   │   └── auth_provider.dart
│   ├── i18n/
│   │   ├── app_localizations.dart
│   │   └── locales/
│   │       ├── en.dart
│   │       └── zh.dart
│   └── theme/
│       └── app_theme.dart
├── features/
│   ├── auth/
│   │   ├── pages/
│   │   │   ├── login_page.dart
│   │   │   └── register_page.dart
│   │   └── providers/
│   │       └── auth_provider.dart
│   ├── products/
│   │   ├── pages/
│   │   │   ├── product_list_page.dart
│   │   │   └── product_detail_page.dart
│   │   └── providers/
│   │       └── product_provider.dart
│   ├── orders/
│   │   ├── pages/
│   │   │   ├── cart_page.dart
│   │   │   ├── checkout_page.dart
│   │   │   └── order_detail_page.dart
│   │   └── providers/
│   │       └── order_provider.dart
│   └── resources/
│       ├── pages/
│       │   ├── resource_list_page.dart
│       │   └── resource_detail_page.dart
│       └── providers/
│           └── resource_provider.dart
└── shared/
    └── widgets/
        ├── loading_indicator.dart
        └── error_view.dart
```

- [ ] **ステップ 4: コミット**

```bash
git add apps/flutter/
git commit -m "feat: create Flutter app skeleton with core network/auth/i18n layer"
```

---

### タスク 3.6: HarmonyOS アプリの骨格

**対象ファイル:**
- 新規作成: `apps/harmonyos/` プロジェクト構造

- [ ] **ステップ 1: HarmonyOS プロジェクト構造の作成**

```
apps/harmonyos/
├── entry/
│   └── src/
│       └── main/
│           ├── ets/
│           │   ├── entryability/
│           │   │   └── EntryAbility.ets
│           │   ├── pages/
│           │   │   ├── LoginPage.ets
│           │   │   ├── RegisterPage.ets
│           │   │   ├── HomePage.ets
│           │   │   ├── ProductListPage.ets
│           │   │   ├── ProductDetailPage.ets
│           │   │   ├── CartPage.ets
│           │   │   ├── OrderDetailPage.ets
│           │   │   ├── ResourceListPage.ets
│           │   │   ├── ResourceDetailPage.ets
│           │   │   ├── TicketListPage.ets
│           │   │   └── ProfilePage.ets
│           │   ├── common/
│           │   │   ├── api/
│           │   │   │   └── ApiClient.ets
│           │   │   ├── auth/
│           │   │   │   └── AuthManager.ets
│           │   │   └── i18n/
│           │   │       ├── I18nManager.ets
│           │   │       └── resources/
│           │   │           ├── en_US.json
│           │   │           └── zh_CN.json
│           │   └── components/
│           │       ├── LoadingView.ets
│           │       └── ErrorView.ets
│           └── module.json5
├── oh-package.json5
└── build-profile.json5
```

- [ ] **ステップ 2: ApiClient の作成（ArkTS）**

```typescript
// apps/harmonyos/entry/src/main/ets/common/api/ApiClient.ets
import http from '@ohos.net.http';
import { preferences } from '@kit.ArkData';

const BASE_URL = 'https://api.example.com/api';

export class ApiClient {
  private static instance: ApiClient;

  static getInstance(): ApiClient {
    if (!ApiClient.instance) {
      ApiClient.instance = new ApiClient();
    }
    return ApiClient.instance;
  }

  async get(path: string, params?: Record<string, string>): Promise<ApiResponse> {
    return this.request('GET', path, params);
  }

  async post(path: string, body?: object): Promise<ApiResponse> {
    return this.request('POST', path, undefined, body);
  }

  private async request(
    method: http.RequestMethod,
    path: string,
    params?: Record<string, string>,
    body?: object
  ): Promise<ApiResponse> {
    const token = await this.getToken();

    const request = http.createHttp();
    const response = await request.request(BASE_URL + path, {
      method: method,
      header: {
        'Content-Type': 'application/json',
        'Authorization': token ? `Bearer ${token}` : '',
        'Accept-Language': 'zh-CN',
      },
      extraData: body ? JSON.stringify(body) : undefined,
    });

    request.destroy();

    const data = JSON.parse(response.result as string);
    return {
      code: data.code,
      message: data.message,
      data: data.data,
      meta: data.meta,
    };
  }

  private async getToken(): Promise<string> {
    const prefs = await preferences.getPreferences(getContext(), 'auth');
    return await prefs.get('access_token', '') as string;
  }
}

interface ApiResponse {
  code: number;
  message: string;
  data?: object;
  meta?: object;
}
```

- [ ] **ステップ 3: コミット**

```bash
git add apps/harmonyos/
git commit -m "feat: create HarmonyOS app skeleton with ApiClient and page structure"
```

---

### タスク 3.7: Docker とデプロイ設定

**対象ファイル:**
- 新規作成: `docker/Dockerfile`
- 新規作成: `docker/docker-compose.yml`
- 新規作成: `docker/nginx.conf`
- 新規作成: `docker/supervisor.conf`
- 新規作成: `service/.env.example`

- [ ] **ステップ 1: Dockerfile の作成**

```dockerfile
# docker/Dockerfile
FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip libpng-dev libjpeg-dev \
    libfreetype6-dev nginx supervisor \
    && docker-php-ext-configure gd \
    && docker-php-ext-install gd pdo_mysql zip bcmath \
    && pecl install redis && docker-php-ext-enable redis

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY service/ /app/

RUN composer install --no-dev --optimize-autoloader

COPY docker/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/supervisor.conf /etc/supervisor/conf.d/app.conf

EXPOSE 80
CMD ["supervisord", "-n"]
```

- [ ] **ステップ 2: docker-compose.yml の作成**

```yaml
# docker/docker-compose.yml
version: '3.8'

services:
  app:
    build:
      context: ..
      dockerfile: docker/Dockerfile
    ports:
      - "80:80"
    environment:
      - APP_DEBUG=false
      - DB_HOST=mysql
      - DB_DATABASE=cloud_platform
      - DB_USERNAME=app_user
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=redis
      - JWT_PRIVATE_KEY=${JWT_PRIVATE_KEY}
      - JWT_PUBLIC_KEY=${JWT_PUBLIC_KEY}
      - ENCRYPTION_MASTER_KEY=${ENCRYPTION_MASTER_KEY}
    depends_on:
      - mysql
      - redis
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
      - MYSQL_DATABASE=cloud_platform
      - MYSQL_USER=app_user
      - MYSQL_PASSWORD=${DB_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
      - ./mysql/init:/docker-entrypoint-initdb.d
    ports:
      - "3306:3306"
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    command: redis-server --appendonly yes
    volumes:
      - redis_data:/data
    ports:
      - "6379:6379"
    restart: unless-stopped

volumes:
  mysql_data:
  redis_data:
```

- [ ] **ステップ 3: nginx.conf の作成**

```nginx
server {
    listen 80;
    server_name api.example.com;

    root /app/public;

    add_header X-Frame-Options "DENY";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    location / {
        proxy_pass http://127.0.0.1:8787;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /health {
        proxy_pass http://127.0.0.1:8787/health;
    }
}
```

- [ ] **ステップ 4: .env.example の作成**

```
APP_NAME=CloudPlatform
APP_DEBUG=false

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cloud_platform
DB_USERNAME=app_user
DB_PASSWORD=

AUDIT_DB_HOST=127.0.0.1
AUDIT_DB_DATABASE=cloud_platform_audit

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

JWT_PRIVATE_KEY=
JWT_PUBLIC_KEY=
ENCRYPTION_MASTER_KEY=

SMTP_HOST=smtp.sendgrid.net
SMTP_USERNAME=apikey
SMTP_PASSWORD=
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=CloudPlatform

STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_PHONE_NUMBER=
```

- [ ] **ステップ 5: コミット**

```bash
git add docker/ service/.env.example
git commit -m "feat: add Docker deployment config and .env.example"
```

---

### タスク 3.8: レポートサービス

**対象ファイル:**
- 新規作成: `service/app/report/service/ReportService.php`
- 新規作成: `service/app/report/controller/ReportController.php`

- [ ] **ステップ 1: ReportService の作成**

```php
<?php
namespace App\Report\Service;

use App\Order\Model\Order;
use App\Supplier\Model\SupplierSettlement;
use Illuminate\Database\Capsule\Manager as DB;

class ReportService
{
    public function revenueReport(string $startDate, string $endDate): array
    {
        $daily = Order::whereBetween('paid_at', [$startDate, $endDate])
            ->where('status', '!=', 'refunded')
            ->selectRaw('DATE(paid_at) as date, currency, SUM(total) as revenue, COUNT(*) as orders')
            ->groupBy('date', 'currency')
            ->orderBy('date')
            ->get();

        $totalRevenue = $daily->sum('revenue');
        $totalOrders  = $daily->sum('orders');

        // By product category
        $byCategory = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_skus', 'product_skus.id', '=', 'order_items.sku_id')
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->join('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->whereBetween('orders.paid_at', [$startDate, $endDate])
            ->where('orders.status', '!=', 'refunded')
            ->selectRaw('product_categories.id, product_categories.name, SUM(order_items.total_price) as revenue')
            ->groupBy('product_categories.id', 'product_categories.name')
            ->get();

        return compact('daily', 'totalRevenue', 'totalOrders', 'byCategory');
    }

    public function supplierReport(int $supplierId, string $startDate, string $endDate): array
    {
        $settlements = SupplierSettlement::where('supplier_id', $supplierId)
            ->whereBetween('period_start', [$startDate, $endDate])
            ->get();

        $totalPayable = $settlements->sum('payable');
        $totalPaid    = $settlements->where('status', 'paid')->sum('payable');

        return compact('settlements', 'totalPayable', 'totalPaid');
    }

    public function salesByRegion(string $startDate, string $endDate): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('regions', 'regions.id', '=', 'order_items.region_id')
            ->whereBetween('orders.paid_at', [$startDate, $endDate])
            ->where('orders.status', '!=', 'refunded')
            ->selectRaw('regions.name, regions.continent, COUNT(*) as orders, SUM(order_items.total_price) as revenue')
            ->groupBy('regions.id', 'regions.name', 'regions.continent')
            ->orderBy('revenue', 'desc')
            ->get()
            ->toArray();
    }
}
```

- [ ] **ステップ 2: コミット**

```bash
git add service/app/report/
git commit -m "feat: implement reporting service (revenue, supplier, regional analysis)"
```

---

### タスク 3.9: 最終統合 — 全ルート、キュー、イベントの配線

- [ ] **ステップ 1: ルート設定の完成** — `service/config/route.php`

```php
<?php
// Final route configuration combining all modules

// --- Public routes ---
Route::get('/health', [\App\Controller\HealthController::class, 'index']);

// Auth
Route::post('/api/auth/register', [\App\User\Controller\AuthController::class, 'register'])
    ->middleware([\Common\Security\RateLimitMiddleware::class . ':register']);
Route::post('/api/auth/login', [\App\User\Controller\AuthController::class, 'login'])
    ->middleware([\Common\Security\RateLimitMiddleware::class . ':login']);
Route::post('/api/auth/refresh', [\App\User\Controller\AuthController::class, 'refresh']);

// Products (public read)
Route::get('/api/products', [\App\Product\Controller\ProductController::class, 'index']);
Route::get('/api/products/{id}', [\App\Product\Controller\ProductController::class, 'show']);
Route::get('/api/regions', [\App\Product\Controller\ProductController::class, 'regions']);

// Domains (public)
Route::get('/api/domain/check/{domain}/{tld}', [\App\Domain\Controller\DomainController::class, 'check']);
Route::get('/api/domain/tlds', [\App\Domain\Controller\DomainController::class, 'tlds']);

// Payment webhooks (no auth, signature verified)
Route::post('/api/payments/webhook/stripe', [\App\Payment\Controller\PaymentController::class, 'stripeWebhook']);

// --- User authenticated routes ---
Route::group('/api', function () {
    // Profile
    Route::get('/user/profile', [\App\User\Controller\ProfileController::class, 'show']);
    Route::put('/user/profile', [\App\User\Controller\ProfileController::class, 'update']);
    Route::post('/user/kyc', [\App\User\Controller\KycController::class, 'submit']);
    Route::get('/user/balance', [\App\User\Controller\BalanceController::class, 'index']);
    Route::get('/user/notifications', [\App\Notification\Controller\NotificationController::class, 'index']);

    // Cart & Orders
    Route::post('/cart', [\App\Order\Controller\OrderController::class, 'addToCart']);
    Route::get('/cart', [\App\Order\Controller\OrderController::class, 'cart']);
    Route::delete('/cart/{id}', [\App\Order\Controller\OrderController::class, 'removeFromCart']);
    Route::post('/orders', [\App\Order\Controller\OrderController::class, 'store']);
    Route::get('/orders', [\App\Order\Controller\OrderController::class, 'myOrders']);
    Route::get('/orders/{id}', [\App\Order\Controller\OrderController::class, 'show']);
    Route::get('/orders/{id}/payment-methods', [\App\Payment\Controller\PaymentController::class, 'availableChannels']);
    Route::post('/orders/{id}/pay', [\App\Payment\Controller\PaymentController::class, 'pay']);

    // Resources
    Route::get('/resources', [\App\Provisioning\Controller\ResourceController::class, 'myResources']);
    Route::get('/resources/{id}', [\App\Provisioning\Controller\ResourceController::class, 'show']);
    Route::get('/resources/{id}/status', [\App\Provisioning\Controller\ResourceController::class, 'status']);
    Route::get('/resources/{id}/console', [\App\Provisioning\Controller\ResourceController::class, 'consoleUrl']);

    // DNS
    Route::get('/dns/{domain}', [\App\Domain\Controller\DomainController::class, 'listRecords']);
    Route::post('/dns/{domain}/records', [\App\Domain\Controller\DomainController::class, 'addRecord']);
    Route::delete('/dns/{domain}/records/{id}', [\App\Domain\Controller\DomainController::class, 'deleteRecord']);

    // Tickets
    Route::post('/tickets', [\App\Ticket\Controller\TicketController::class, 'create']);
    Route::get('/tickets', [\App\Ticket\Controller\TicketController::class, 'myTickets']);
    Route::get('/tickets/{id}', [\App\Ticket\Controller\TicketController::class, 'show']);
    Route::post('/tickets/{id}/reply', [\App\Ticket\Controller\TicketController::class, 'reply']);

    // Supplier
    Route::post('/supplier/apply', [\App\Supplier\Controller\SupplierController::class, 'apply']);
})->middleware([\Common\Auth\Middleware\AuthMiddleware::class]);

// --- Admin routes ---
Route::group('/admin/api', function () {
    Route::get('/dashboard', [\App\Admin\Controller\DashboardController::class, 'index']);

    // Users
    Route::get('/users', [\App\Admin\Controller\UserController::class, 'index']);
    Route::get('/users/{id}', [\App\Admin\Controller\UserController::class, 'show']);
    Route::get('/kyc', [\App\Admin\Controller\DashboardController::class, 'kycList']);
    Route::post('/kyc/{id}/approve', [\App\Admin\Controller\DashboardController::class, 'kycApprove']);
    Route::post('/kyc/{id}/reject', [\App\Admin\Controller\DashboardController::class, 'kycReject']);

    // Products
    Route::post('/products', [\App\Admin\Controller\ProductController::class, 'store']);
    Route::put('/products/{id}', [\App\Admin\Controller\ProductController::class, 'update']);
    Route::delete('/products/{id}', [\App\Admin\Controller\ProductController::class, 'destroy']);

    // Orders
    Route::get('/orders', [\App\Admin\Controller\OrderController::class, 'index']);
    Route::get('/orders/{id}', [\App\Admin\Controller\OrderController::class, 'show']);
    Route::post('/orders/{id}/refund', [\App\Admin\Controller\OrderController::class, 'refund']);

    // Provisioning
    Route::get('/provisioning/tasks', [\App\Provisioning\Controller\TaskController::class, 'index']);
    Route::post('/provisioning/tasks/{id}/retry', [\App\Provisioning\Controller\TaskController::class, 'retry']);
    Route::get('/provisioning/hosts', [\App\Provisioning\Controller\HostController::class, 'index']);

    // Payment
    Route::get('/payments/channels', [\App\Admin\Controller\PaymentController::class, 'channels']);
    Route::put('/payments/channels/{id}', [\App\Admin\Controller\PaymentController::class, 'updateChannel']);
    Route::get('/payments/transactions', [\App\Admin\Controller\PaymentController::class, 'transactions']);
    Route::get('/payments/reconcile', [\App\Admin\Controller\PaymentController::class, 'reconcile']);

    // Supplier management
    Route::get('/suppliers', [\App\Admin\Controller\SupplierController::class, 'index']);
    Route::post('/suppliers/{id}/approve', [\App\Admin\Controller\SupplierController::class, 'approve']);
    Route::post('/suppliers/{id}/settle', [\App\Admin\Controller\SupplierController::class, 'generateSettlement']);
    Route::post('/suppliers/withdraws/{id}/approve', [\App\Admin\Controller\SupplierController::class, 'approveWithdraw']);

    // Tickets
    Route::get('/tickets', [\App\Ticket\Controller\TicketController::class, 'index']);
    Route::post('/tickets/{id}/assign', [\App\Ticket\Controller\TicketController::class, 'assign']);
    Route::post('/tickets/{id}/close', [\App\Ticket\Controller\TicketController::class, 'close']);

    // System
    Route::get('/audit-logs', [\App\Admin\Controller\SystemController::class, 'auditLogs']);
    Route::put('/system/config', [\App\Admin\Controller\SystemController::class, 'updateConfig']);

    // Reports
    Route::get('/reports/revenue', [\App\Report\Controller\ReportController::class, 'revenue']);
    Route::get('/reports/supplier', [\App\Report\Controller\ReportController::class, 'supplier']);
    Route::get('/reports/region', [\App\Report\Controller\ReportController::class, 'byRegion']);

    // Monitoring
    Route::get('/monitor/dashboard', [\App\Monitor\Controller\MonitorController::class, 'dashboard']);
    Route::get('/monitor/resources/{id}', [\App\Monitor\Controller\MonitorController::class, 'resourceMetrics']);
})->middleware([
    \Common\Auth\Middleware\AuthMiddleware::class,
]);
```

- [ ] **ステップ 2: 設定へのキューユンシューマの登録**

```php
// config/plugin/webman/redis-queue/process.php
return [
    'provisioning' => [
        'handler' => \App\Provisioning\Queue\ProvisionWorker::class,
        'count'   => 2,
    ],
    'notification_email' => [
        'handler' => \App\Notification\Queue\EmailSender::class,
        'count'   => 5,
    ],
    'notification_sms' => [
        'handler' => \App\Notification\Queue\SmsSender::class,
        'count'   => 10,
    ],
    'notification_push' => [
        'handler' => \App\Notification\Queue\PushSender::class,
        'count'   => 20,
    ],
];
```

- [ ] **ステップ 3: start.php へのイベントリスナーの登録**

```php
// Event listeners
use App\Payment\Event\OrderPaid;
use App\Provisioning\Listener\OrderPaidListener;
use App\Ticket\Event\TicketCreated;
use App\Ticket\Listener\AutoAssignListener;

Event::listen(OrderPaid::class, [OrderPaidListener::class, 'handle']);
Event::listen(TicketCreated::class, [AutoAssignListener::class, 'handle']);
```

- [ ] **ステップ 4: crontab の設定**

```
# m h dom mon dow command
*/5 * * * * php /app/app/monitor/cron/CollectMetrics.php
7   * * * * php /app/app/monitor/cron/CheckExpirations.php
0   3 * * * php /app/app/payment/cron/DailyReconcile.php
0   6 * * * php /app/app/supplier/cron/WeeklySettlement.php
```

- [ ] **ステップ 5: 最終コミット**

```bash
git add -A
git commit -m "feat: complete integration — routes, queues, events, crontab, all modules wired"
```

---

**Phase 3 完了。プラットフォームは完全に運用可能です:**
- ユーザー: 登録 → 閲覧 → 注文 → 支払い → 自動開通 → リソース管理 → チケット提出
- 管理者: ダッシュボード → ユーザー/商品/注文/決済の管理 → KYC 審査 → チケット対応 → レポート閲覧
- サプライヤー: 申請 → 承認 → 商品出品 → 決済受領 → 出金
- モニタリング: 5 分ごとのメトリクス収集、障害/期限切れ時のアラート
- デプロイ: Docker Compose でワンコマンド起動
