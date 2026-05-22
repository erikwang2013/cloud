<?php
return [
    'enable'  => true,
    'apidoc' => [
        'title'  => 'CloudPlatform Admin Panel API',
        'desc'   => '管理后台 Layui 面板 — 内部接口文档',
        'apps'   => [
            ['title'=>'账户','path'=>'app\controller','key'=>'account','group'=>[
                ['title'=>'登录管理','path'=>'AccountController'],
            ]],
            ['title'=>'仪表盘','path'=>'app\controller','key'=>'dashboard','group'=>[
                ['title'=>'仪表盘数据','path'=>'DashboardController'],
            ]],
            ['title'=>'用户管理','path'=>'app\controller','key'=>'user','group'=>[
                ['title'=>'用户CRUD','path'=>'UserController'],
                ['title'=>'用户KYC','path'=>'UserKycController'],
            ]],
            ['title'=>'商品管理','path'=>'app\controller','key'=>'product','group'=>[
                ['title'=>'商品CRUD','path'=>'ProductController'],
                ['title'=>'分类管理','path'=>'ProductCategoryController'],
                ['title'=>'SKU管理','path'=>'ProductSkuController'],
                ['title'=>'区域定价','path'=>'ProductRegionController'],
                ['title'=>'商品图片','path'=>'ProductImageController'],
                ['title'=>'商品评价','path'=>'ProductReviewController'],
            ]],
            ['title'=>'订单管理','path'=>'app\controller','key'=>'order','group'=>[
                ['title'=>'订单CRUD','path'=>'OrderController'],
                ['title'=>'订单明细','path'=>'OrderItemController'],
                ['title'=>'订单时间线','path'=>'OrderTimelineController'],
                ['title'=>'购物车','path'=>'CartController'],
                ['title'=>'退款管理','path'=>'RefundController'],
            ]],
            ['title'=>'支付管理','path'=>'app\controller','key'=>'payment','group'=>[
                ['title'=>'支付通道','path'=>'PaymentChannelController'],
                ['title'=>'交易记录','path'=>'PaymentTransactionController'],
            ]],
            ['title'=>'资源管理','path'=>'app\controller','key'=>'provisioning','group'=>[
                ['title'=>'资源CRUD','path'=>'ResourceController'],
                ['title'=>'开通任务','path'=>'ProvisionTaskController'],
                ['title'=>'云盘管理','path'=>'DiskController'],
                ['title'=>'云盘扩容','path'=>'DiskResizeController'],
                ['title'=>'宿主机','path'=>'HostMachineController'],
                ['title'=>'IP池','path'=>'IpPoolController'],
                ['title'=>'IP分配','path'=>'IpAllocationController'],
            ]],
            ['title'=>'域名管理','path'=>'app\controller','key'=>'domain','group'=>[
                ['title'=>'TLD管理','path'=>'DomainTldController'],
                ['title'=>'DNS区域','path'=>'DnsZoneController'],
                ['title'=>'DNS记录','path'=>'DnsRecordController'],
            ]],
            ['title'=>'工单管理','path'=>'app\controller','key'=>'ticket','group'=>[
                ['title'=>'工单CRUD','path'=>'TicketController'],
                ['title'=>'工单消息','path'=>'TicketMessageController'],
            ]],
            ['title'=>'通知管理','path'=>'app\controller','key'=>'notification','group'=>[
                ['title'=>'通知CRUD','path'=>'NotificationController'],
                ['title'=>'通知模板','path'=>'NotificationTemplateController'],
            ]],
            ['title'=>'供应商管理','path'=>'app\controller','key'=>'supplier','group'=>[
                ['title'=>'供应商CRUD','path'=>'SupplierController'],
                ['title'=>'结算单','path'=>'SupplierSettlementController'],
                ['title'=>'提现记录','path'=>'SupplierWithdrawController'],
            ]],
            ['title'=>'系统管理','path'=>'app\controller','key'=>'system','group'=>[
                ['title'=>'角色管理','path'=>'RoleController'],
                ['title'=>'权限规则','path'=>'RuleController'],
                ['title'=>'字典管理','path'=>'DictController'],
                ['title'=>'配置管理','path'=>'ConfigController'],
                ['title'=>'审计日志','path'=>'AuditLogController'],
                ['title'=>'告警记录','path'=>'AlertController'],
                ['title'=>'管理员','path'=>'AdminController'],
                ['title'=>'上传','path'=>'UploadController'],
                ['title'=>'数据表','path'=>'TableController'],
                ['title'=>'插件管理','path'=>'PluginController'],
                ['title'=>'安装向导','path'=>'InstallController'],
                ['title'=>'开发工具','path'=>'DevController'],
            ]],
            ['title'=>'其他管理','path'=>'app\controller','key'=>'other','group'=>[
                ['title'=>'首页','path'=>'IndexController'],
                ['title'=>'Token管理','path'=>'RefreshTokenController'],
                ['title'=>'区域管理','path'=>'RegionController'],
                ['title'=>'用户地址','path'=>'UserAddressController'],
                ['title'=>'用户余额','path'=>'UserBalanceController'],
            ]],
        ],
        'auto_url' => [
            'letter_rule' => "lcfirst",
            'prefix'=>"/app/admin",
        ],
        'auto_register_routes'=>false,
        'cache'    => ['enable' => false],
        'auth'     => ['enable' => false],
        'params'   => [],
        'responses'=> [
            'success'=>[
                ['name'=>'code','desc'=>'业务代码','type'=>'int','require'=>1],
                ['name'=>'msg','desc'=>'业务信息','type'=>'string','require'=>1],
                ['name'=>'data','desc'=>'业务数据','main'=>true,'type'=>'object','require'=>1],
            ],
            'error'=>[
                ['name'=>'code','desc'=>'错误码','type'=>'int','require'=>1],
                ['name'=>'msg','desc'=>'错误信息','type'=>'string','require'=>1],
            ]
        ],
        'default_method'=>'GET',
        'allowCrossDomain'=>true,
    ]
];
