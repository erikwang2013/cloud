<?php
return [
    'enable'  => true,
    'apidoc' => [
        'title'              => 'CloudPlatform API',
        'desc'               => '全球云资源交易平台 — 用户端 + 管理端 + 供应商外部 API',
        'apps'           => [
            // ── 公开接口 ──
            ['title'=>'公开接口','path'=>'app\Controller','key'=>'public','group'=>[
                ['title'=>'健康检查','path'=>'HealthController'],
                ['title'=>'服务状态','path'=>'StatusController'],
                ['title'=>'帮助中心','path'=>'HelpController'],
            ]],
            // ── 商品 ──
            ['title'=>'商品','path'=>'app\Product\Controller','key'=>'products','group'=>[
                ['title'=>'商品列表','path'=>'ProductController'],
                ['title'=>'商品评价','path'=>'ReviewController'],
            ]],
            // ── 域名（公开） ──
            ['title'=>'域名','path'=>'app\Domain\Controller','key'=>'domains','group'=>[
                ['title'=>'域名查询','path'=>'DomainController'],
            ]],
            // ── 认证 ──
            ['title'=>'认证','path'=>'app\User\Controller','key'=>'auth','group'=>[
                ['title'=>'注册登录','path'=>'AuthController'],
            ]],
            // ── 验证码 ──
            ['title'=>'验证码','path'=>'app\Captcha\Controller','key'=>'captcha'],
            // ── 用户中心 ──
            ['title'=>'用户中心','path'=>'app\User\Controller','key'=>'user','group'=>[
                ['title'=>'个人资料','path'=>'ProfileController'],
                ['title'=>'KYC认证','path'=>'KycController'],
                ['title'=>'余额','path'=>'BalanceController'],
                ['title'=>'地址管理','path'=>'AddressController'],
            ]],
            // ── 购物车与订单 ──
            ['title'=>'购物车与订单','path'=>'app\Order\Controller','key'=>'orders','group'=>[
                ['title'=>'购物车/订单','path'=>'OrderController'],
                ['title'=>'优惠券','path'=>'CouponController'],
                ['title'=>'发票','path'=>'InvoiceController'],
            ]],
            // ── 支付 ──
            ['title'=>'支付','path'=>'app\Payment\Controller','key'=>'payments','group'=>[
                ['title'=>'支付','path'=>'PaymentController'],
            ]],
            // ── 资源与DNS ──
            ['title'=>'资源与DNS','path'=>'app\Provisioning\Controller','key'=>'resources','group'=>[
                ['title'=>'资源管理','path'=>'ResourceController'],
                ['title'=>'批量操作','path'=>'BatchController'],
            ]],
            // ── 工单 ──
            ['title'=>'工单','path'=>'app\Ticket\Controller','key'=>'tickets','group'=>[
                ['title'=>'工单','path'=>'TicketController'],
            ]],
            // ── 通知 ──
            ['title'=>'通知','path'=>'app\Notification\Controller','key'=>'notifications','group'=>[
                ['title'=>'通知','path'=>'NotificationController'],
            ]],
            // ── 供应商外部 API ──
            ['title'=>'供应商外部API','path'=>'app\Supplier\Controller\External','key'=>'supplier_external','group'=>[
                ['title'=>'订单','path'=>'OrderController'],
                ['title'=>'资源','path'=>'ResourceController'],
                ['title'=>'结算','path'=>'SettlementController'],
                ['title'=>'提现','path'=>'WithdrawController'],
            ]],
            // ── 上传 ──
            ['title'=>'文件上传','path'=>'app\Controller','key'=>'upload','group'=>[
                ['title'=>'上传','path'=>'UploadController'],
            ]],

            // ══════════════════════════════════════
            // 管理后台 API
            // ══════════════════════════════════════
            ['title'=>'管理后台-仪表盘','path'=>'app\Admin\Controller','key'=>'admin_dashboard','group'=>[
                ['title'=>'仪表盘','path'=>'DashboardController'],
            ]],
            ['title'=>'管理后台-用户','path'=>'app\Admin\Controller','key'=>'admin_users','group'=>[
                ['title'=>'用户管理','path'=>'UserController'],
            ]],
            ['title'=>'管理后台-商品','path'=>'app\Admin\Controller','key'=>'admin_products','group'=>[
                ['title'=>'商品管理','path'=>'ProductController'],
                ['title'=>'导入导出','path'=>'ImportExportController'],
            ]],
            ['title'=>'管理后台-订单','path'=>'app\Admin\Controller','key'=>'admin_orders','group'=>[
                ['title'=>'订单管理','path'=>'OrderController'],
                ['title'=>'发票管理','path'=>'InvoiceController'],
            ]],
            ['title'=>'管理后台-支付','path'=>'app\Admin\Controller','key'=>'admin_payments','group'=>[
                ['title'=>'支付管理','path'=>'PaymentController'],
            ]],
            ['title'=>'管理后台-资源','path'=>'app\Provisioning\Controller','key'=>'admin_provisioning','group'=>[
                ['title'=>'开通管理','path'=>'TaskController'],
                ['title'=>'主机管理','path'=>'HostController'],
            ]],
            ['title'=>'管理后台-工单','path'=>'app\Ticket\Controller','key'=>'admin_tickets'],
            ['title'=>'管理后台-域名','path'=>'app\Admin\Controller','key'=>'admin_domains','group'=>[
                ['title'=>'域名管理','path'=>'DomainController'],
            ]],
            ['title'=>'管理后台-通知','path'=>'app\Admin\Controller','key'=>'admin_notifications','group'=>[
                ['title'=>'通知管理','path'=>'NotificationController'],
            ]],
            ['title'=>'管理后台-优惠券','path'=>'app\Admin\Controller','key'=>'admin_coupons','group'=>[
                ['title'=>'优惠券管理','path'=>'CouponController'],
            ]],
            ['title'=>'管理后台-帮助','path'=>'app\Admin\Controller','key'=>'admin_help','group'=>[
                ['title'=>'帮助管理','path'=>'HelpController'],
            ]],
            ['title'=>'管理后台-Webhook','path'=>'app\Admin\Controller','key'=>'admin_webhooks','group'=>[
                ['title'=>'Webhook管理','path'=>'WebhookController'],
            ]],
            ['title'=>'管理后台-云厂商','path'=>'app\Admin\Controller','key'=>'admin_providers','group'=>[
                ['title'=>'云厂商管理','path'=>'ProviderApiController'],
            ]],
            ['title'=>'管理后台-报表','path'=>'app\Report\Controller','key'=>'admin_reports','group'=>[
                ['title'=>'报表','path'=>'ReportController'],
            ]],
            ['title'=>'管理后台-监控','path'=>'app\Monitor\Controller','key'=>'admin_monitor','group'=>[
                ['title'=>'监控','path'=>'MonitorController'],
            ]],
            ['title'=>'管理后台-系统','path'=>'app\Admin\Controller','key'=>'admin_system','group'=>[
                ['title'=>'审计日志','path'=>'SystemController'],
                ['title'=>'系统配置','path'=>'SystemController'],
            ]],
        ],
        'auto_url' => [
            'letter_rule' => "lcfirst",
            'prefix'=>"/api",
        ],
        'auto_register_routes'=>false,
        'cache'              => ['enable' => false],
        'auth'               => ['enable' => false],
        'params'=>[
            'header'=>[
                ['name'=>'X-Api-Version','type'=>'string','require'=>true,'default'=>'v1','desc'=>'API版本'],
                ['name'=>'Accept-Language','type'=>'string','require'=>false,'default'=>'en-US','desc'=>'多语言'],
                ['name'=>'X-Client-Platform','type'=>'string','require'=>false,'desc'=>'客户端平台'],
            ],
        ],
        'responses'=>[
            'success'=>[
                ['name'=>'code','desc'=>'业务代码','type'=>'int','require'=>1],
                ['name'=>'message','desc'=>'业务信息','type'=>'string','require'=>1],
                ['name'=>'data','desc'=>'业务数据','main'=>true,'type'=>'object','require'=>1],
            ],
            'error'=>[
                ['name'=>'code','desc'=>'错误码','type'=>'int','require'=>1],
                ['name'=>'message','desc'=>'错误信息','type'=>'string','require'=>1],
            ]
        ],
        'default_method'=>'GET',
        'allowCrossDomain'=>true,
    ]
];
