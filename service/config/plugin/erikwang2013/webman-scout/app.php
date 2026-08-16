<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 启用 webman-scout 插件：Elasticsearch 全文搜索与数据同步
    'enable' => true,

    // 默认搜索引擎：elasticsearch
    'driver' => 'elasticsearch',

    // 索引名称前缀（所有模型索引自动添加此前缀）
    'prefix' => getenv('SCOUT_PREFIX') ?: '',

    // 异步索引：启用后通过 Redis Queue 异步同步，否则同步写入
    'queue'  => false,

    // 批量同步时每批处理的记录数
    'chunk'  => [
        'searchable'   => 500,
        'unsearchable' => 500,
    ],

    // 软删除：true 时保留软删除记录在索引中
    'soft_delete' => false,

    // Elasticsearch 连接配置
    'elasticsearch' => [
        // ES 服务地址（支持集群，逗号分隔）
        'hosts' => array_filter(explode(',', getenv('ELASTICSEARCH_HOSTS') ?: 'http://127.0.0.1:9200')),

        // 基本认证（可选）
        'username' => getenv('ELASTICSEARCH_USERNAME') ?: null,
        'password' => getenv('ELASTICSEARCH_PASSWORD') ?: null,

        // SSL 证书验证（生产环境建议开启）
        'ssl_verification' => (bool) (getenv('ELASTICSEARCH_SSL_VERIFICATION') ?: false),

        // HTTP 客户端选项
        'http_client_options' => [
            'connect_timeout' => 3,
            'timeout'         => 10,
        ],

        // 各模型索引定义（settings + mappings）
        // 索引在 scout:import 时自动创建
        // ponytail: 单节点配置（shards=1/replicas=0）；生产集群需改回多分片+副本。
        // 分词用内置 standard：docker 官方镜像无 ik 插件，索引创建会失败；
        // 生产如需中文分词，装 ik 插件后改回 ik_max_word/ik_smart。
        'indices' => [
            // 产品索引 — 支持多语言全文搜索、分类筛选、价格排序
            'products' => [
                'settings' => [
                    'number_of_shards'   => 1,
                    'number_of_replicas' => 0,
                ],
                'mappings' => [
                    'properties' => [
                        'id'          => ['type' => 'long'],
                        'category_id' => ['type' => 'keyword'],
                        'name'        => ['type' => 'text', 'analyzer' => 'standard'],
                        'description' => ['type' => 'text', 'analyzer' => 'standard'],
                        'status'      => ['type' => 'keyword'],
                        'base_price'  => ['type' => 'double'],
                        'created_at'  => ['type' => 'date'],
                        'updated_at'  => ['type' => 'date'],
                    ],
                ],
            ],

            // 用户索引 — 管理后台搜索用户
            'users' => [
                'settings' => [
                    'number_of_shards'   => 1,
                    'number_of_replicas' => 0,
                ],
                'mappings' => [
                    'properties' => [
                        'id'         => ['type' => 'long'],
                        'email'      => ['type' => 'keyword'],
                        'phone'      => ['type' => 'keyword'],
                        'status'     => ['type' => 'keyword'],
                        'role'       => ['type' => 'keyword'],
                        'language'   => ['type' => 'keyword'],
                        'currency'   => ['type' => 'keyword'],
                        'timezone'   => ['type' => 'keyword'],
                        'created_at' => ['type' => 'date'],
                    ],
                ],
            ],

            // 订单索引 — 用户/管理后台搜索订单
            'orders' => [
                'settings' => [
                    'number_of_shards'   => 1,
                    'number_of_replicas' => 0,
                ],
                'mappings' => [
                    'properties' => [
                        'id'         => ['type' => 'long'],
                        'order_no'   => ['type' => 'keyword'],
                        'user_id'    => ['type' => 'long'],
                        'type'       => ['type' => 'keyword'],
                        'status'     => ['type' => 'keyword'],
                        'currency'   => ['type' => 'keyword'],
                        'total'      => ['type' => 'double'],
                        'subtotal'   => ['type' => 'double'],
                        'paid_at'    => ['type' => 'date'],
                        'created_at' => ['type' => 'date'],
                    ],
                ],
            ],

            // 工单索引 — 用户/管理后台搜索工单
            'tickets' => [
                'settings' => [
                    'number_of_shards'   => 1,
                    'number_of_replicas' => 0,
                ],
                'mappings' => [
                    'properties' => [
                        'id'           => ['type' => 'long'],
                        'ticket_no'    => ['type' => 'keyword'],
                        'user_id'      => ['type' => 'long'],
                        'resource_id'  => ['type' => 'long'],
                        'category'     => ['type' => 'keyword'],
                        'priority'     => ['type' => 'keyword'],
                        'title'        => ['type' => 'text', 'analyzer' => 'standard'],
                        'status'       => ['type' => 'keyword'],
                        'assigned_to'  => ['type' => 'long'],
                        'sla_deadline' => ['type' => 'date'],
                        'created_at'   => ['type' => 'date'],
                    ],
                ],
            ],
        ],
    ],
];
