<?php

/**
 * Auto-generated business management menu.
 * Include this in config/menu.php
 */

return [
    [
        'title' => '业务管理',
        'key' => 'business',
        'icon' => 'layui-icon-component',
        'weight' => 750,
        'type' => 0,
        'children' => [
            [
                'title' => '产品管理',
                'key' => 'business_产品管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 1000,
                'children' => [
                    [
                        'title' => '产品分类',
                        'key' => 'app\\controller\\ProductCategoryController',
                        'href' => '/app/admin/product_category/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '产品列表',
                        'key' => 'app\\controller\\ProductController',
                        'href' => '/app/admin/product/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                    [
                        'title' => 'SKU管理',
                        'key' => 'app\\controller\\ProductSkuController',
                        'href' => '/app/admin/product_sku/index',
                        'type' => 1,
                        'weight' => 980,
                    ],
                    [
                        'title' => '产品区域定价',
                        'key' => 'app\\controller\\ProductRegionController',
                        'href' => '/app/admin/product_region/index',
                        'type' => 1,
                        'weight' => 970,
                    ],
                    [
                        'title' => '产品图片',
                        'key' => 'app\\controller\\ProductImageController',
                        'href' => '/app/admin/product_image/index',
                        'type' => 1,
                        'weight' => 960,
                    ],
                    [
                        'title' => '产品评价',
                        'key' => 'app\\controller\\ProductReviewController',
                        'href' => '/app/admin/product_review/index',
                        'type' => 1,
                        'weight' => 950,
                    ],
                    [
                        'title' => '区域管理',
                        'key' => 'app\\controller\\RegionController',
                        'href' => '/app/admin/region/index',
                        'type' => 1,
                        'weight' => 940,
                    ],
                ]
            ],
            [
                'title' => '订单管理',
                'key' => 'business_订单管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 990,
                'children' => [
                    [
                        'title' => '订单列表',
                        'key' => 'app\\controller\\OrderController',
                        'href' => '/app/admin/order/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '订单项',
                        'key' => 'app\\controller\\OrderItemController',
                        'href' => '/app/admin/order_item/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                    [
                        'title' => '订单时间线',
                        'key' => 'app\\controller\\OrderTimelineController',
                        'href' => '/app/admin/order_timeline/index',
                        'type' => 1,
                        'weight' => 980,
                    ],
                    [
                        'title' => '退款管理',
                        'key' => 'app\\controller\\RefundController',
                        'href' => '/app/admin/refund/index',
                        'type' => 1,
                        'weight' => 970,
                    ],
                    [
                        'title' => '购物车',
                        'key' => 'app\\controller\\CartController',
                        'href' => '/app/admin/cart/index',
                        'type' => 1,
                        'weight' => 960,
                    ],
                ]
            ],
            [
                'title' => '资源管理',
                'key' => 'business_资源管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 980,
                'children' => [
                    [
                        'title' => '云服务器',
                        'key' => 'app\\controller\\ResourceController',
                        'href' => '/app/admin/resource/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '云磁盘',
                        'key' => 'app\\controller\\DiskController',
                        'href' => '/app/admin/disk/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                    [
                        'title' => '磁盘扩容',
                        'key' => 'app\\controller\\DiskResizeController',
                        'href' => '/app/admin/disk_resize/index',
                        'type' => 1,
                        'weight' => 980,
                    ],
                    [
                        'title' => 'IP池',
                        'key' => 'app\\controller\\IpPoolController',
                        'href' => '/app/admin/ip_pool/index',
                        'type' => 1,
                        'weight' => 970,
                    ],
                    [
                        'title' => 'IP分配',
                        'key' => 'app\\controller\\IpAllocationController',
                        'href' => '/app/admin/ip_allocation/index',
                        'type' => 1,
                        'weight' => 960,
                    ],
                    [
                        'title' => '物理主机',
                        'key' => 'app\\controller\\HostMachineController',
                        'href' => '/app/admin/host_machine/index',
                        'type' => 1,
                        'weight' => 950,
                    ],
                    [
                        'title' => '交付任务',
                        'key' => 'app\\controller\\ProvisionTaskController',
                        'href' => '/app/admin/provision_task/index',
                        'type' => 1,
                        'weight' => 940,
                    ],
                ]
            ],
            [
                'title' => '域名管理',
                'key' => 'business_域名管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 970,
                'children' => [
                    [
                        'title' => 'TLD管理',
                        'key' => 'app\\controller\\DomainTldController',
                        'href' => '/app/admin/domain_tld/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => 'DNS区域',
                        'key' => 'app\\controller\\DnsZoneController',
                        'href' => '/app/admin/dns_zone/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                    [
                        'title' => 'DNS记录',
                        'key' => 'app\\controller\\DnsRecordController',
                        'href' => '/app/admin/dns_record/index',
                        'type' => 1,
                        'weight' => 980,
                    ],
                ]
            ],
            [
                'title' => '供应商管理',
                'key' => 'business_供应商管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 960,
                'children' => [
                    [
                        'title' => '供应商列表',
                        'key' => 'app\\controller\\SupplierController',
                        'href' => '/app/admin/supplier/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '结算记录',
                        'key' => 'app\\controller\\SupplierSettlementController',
                        'href' => '/app/admin/supplier_settlement/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                    [
                        'title' => '提现管理',
                        'key' => 'app\\controller\\SupplierWithdrawController',
                        'href' => '/app/admin/supplier_withdraw/index',
                        'type' => 1,
                        'weight' => 980,
                    ],
                ]
            ],
            [
                'title' => '支付管理',
                'key' => 'business_支付管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 950,
                'children' => [
                    [
                        'title' => '支付通道',
                        'key' => 'app\\controller\\PaymentChannelController',
                        'href' => '/app/admin/payment_channel/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '交易记录',
                        'key' => 'app\\controller\\PaymentTransactionController',
                        'href' => '/app/admin/payment_transaction/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                ]
            ],
            [
                'title' => '用户管理',
                'key' => 'business_用户管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 940,
                'children' => [
                    [
                        'title' => 'KYC审核',
                        'key' => 'app\\controller\\UserKycController',
                        'href' => '/app/admin/user_kyc/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '用户余额',
                        'key' => 'app\\controller\\UserBalanceController',
                        'href' => '/app/admin/user_balance/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                    [
                        'title' => '用户地址',
                        'key' => 'app\\controller\\UserAddressController',
                        'href' => '/app/admin/user_address/index',
                        'type' => 1,
                        'weight' => 980,
                    ],
                    [
                        'title' => '刷新令牌',
                        'key' => 'app\\controller\\RefreshTokenController',
                        'href' => '/app/admin/refresh_token/index',
                        'type' => 1,
                        'weight' => 970,
                    ],
                ]
            ],
            [
                'title' => '报表统计',
                'key' => 'business_报表统计',
                'type' => 0,
                'icon' => 'layui-icon-chart',
                'weight' => 935,
                'children' => [
                    [
                        'title' => '经营报表',
                        'key' => 'app\\controller\\ReportController',
                        'href' => '/app/admin/report/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                ]
            ],
            [
                'title' => '工单管理',
                'key' => 'business_工单管理',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 930,
                'children' => [
                    [
                        'title' => '工单列表',
                        'key' => 'app\\controller\\TicketController',
                        'href' => '/app/admin/ticket/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '工单回复',
                        'key' => 'app\\controller\\TicketMessageController',
                        'href' => '/app/admin/ticket_message/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                ]
            ],
            [
                'title' => '系统监控',
                'key' => 'business_系统监控',
                'type' => 0,
                'icon' => 'layui-icon-file',
                'weight' => 920,
                'children' => [
                    [
                        'title' => '通知记录',
                        'key' => 'app\\controller\\NotificationController',
                        'href' => '/app/admin/notification/index',
                        'type' => 1,
                        'weight' => 1000,
                    ],
                    [
                        'title' => '通知模板',
                        'key' => 'app\\controller\\NotificationTemplateController',
                        'href' => '/app/admin/notification_template/index',
                        'type' => 1,
                        'weight' => 990,
                    ],
                    [
                        'title' => '告警记录',
                        'key' => 'app\\controller\\AlertController',
                        'href' => '/app/admin/alert/index',
                        'type' => 1,
                        'weight' => 980,
                    ],
                    [
                        'title' => '审计日志',
                        'key' => 'app\\controller\\AuditLogController',
                        'href' => '/app/admin/audit_log/index',
                        'type' => 1,
                        'weight' => 970,
                    ],
                ]
            ],
        ]
    ]
];
