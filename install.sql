-- ============================================================
-- CloudPlatform — Unified Database DDL
-- Tables: wa_* (admin panel) + erik_* (business)
-- Primary Key: BIGINT (non auto-increment, application-generated)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ======================== Admin Panel ========================

CREATE TABLE IF NOT EXISTS `wa_admin_roles` (
  `id` bigint unsigned NOT NULL COMMENT '主键',
  `role_id` bigint unsigned NOT NULL COMMENT '角色id',
  `admin_id` bigint unsigned NOT NULL COMMENT '管理员id',
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_admin_id` (`role_id`,`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员角色表';

CREATE TABLE IF NOT EXISTS `wa_admins` (
  `id` bigint unsigned NOT NULL COMMENT 'ID',
  `username` varchar(32) NOT NULL COMMENT '用户名',
  `nickname` varchar(40) NOT NULL COMMENT '昵称',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `avatar` varchar(255) DEFAULT '/app/admin/avatar.png' COMMENT '头像',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `mobile` varchar(16) DEFAULT NULL COMMENT '手机',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  `login_at` datetime DEFAULT NULL COMMENT '登录时间',
  `status` tinyint(4) DEFAULT NULL COMMENT '禁用',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员表';

CREATE TABLE IF NOT EXISTS `wa_options` (
  `id` bigint unsigned NOT NULL,
  `name` varchar(128) NOT NULL COMMENT '键',
  `value` longtext NOT NULL COMMENT '值',
  `created_at` datetime NOT NULL DEFAULT '2022-08-15 00:00:00' COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT '2022-08-15 00:00:00' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='选项表';

CREATE TABLE IF NOT EXISTS `wa_roles` (
  `id` bigint unsigned NOT NULL COMMENT '主键',
  `name` varchar(80) NOT NULL COMMENT '角色组',
  `rules` text COMMENT '权限',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  `pid` bigint unsigned DEFAULT NULL COMMENT '父级',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员角色';

CREATE TABLE IF NOT EXISTS `wa_rules` (
  `id` bigint unsigned NOT NULL COMMENT '主键',
  `title` varchar(255) NOT NULL COMMENT '标题',
  `icon` varchar(255) DEFAULT NULL COMMENT '图标',
  `key` varchar(255) NOT NULL COMMENT '标识',
  `pid` bigint unsigned DEFAULT '0' COMMENT '上级菜单',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  `href` varchar(255) DEFAULT NULL COMMENT 'url',
  `type` int(11) NOT NULL DEFAULT '1' COMMENT '类型',
  `weight` int(11) DEFAULT '0' COMMENT '排序',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='权限规则';

CREATE TABLE IF NOT EXISTS `wa_uploads` (
  `id` bigint unsigned NOT NULL COMMENT '主键',
  `name` varchar(128) NOT NULL COMMENT '名称',
  `url` varchar(255) NOT NULL COMMENT '文件',
  `admin_id` bigint unsigned DEFAULT NULL COMMENT '管理员',
  `file_size` int(11) NOT NULL COMMENT '文件大小',
  `mime_type` varchar(255) NOT NULL COMMENT 'mime类型',
  `image_width` int(11) DEFAULT NULL COMMENT '图片宽度',
  `image_height` int(11) DEFAULT NULL COMMENT '图片高度',
  `ext` varchar(128) NOT NULL COMMENT '扩展名',
  `storage` varchar(255) NOT NULL DEFAULT 'local' COMMENT '存储位置',
  `created_at` date DEFAULT NULL COMMENT '上传时间',
  `category` varchar(128) DEFAULT NULL COMMENT '类别',
  `updated_at` date DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `category` (`category`),
  KEY `admin_id` (`admin_id`),
  KEY `name` (`name`),
  KEY `ext` (`ext`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='附件';

CREATE TABLE IF NOT EXISTS `wa_users` (
  `id` bigint unsigned NOT NULL COMMENT '主键',
  `username` varchar(32) NOT NULL COMMENT '用户名',
  `nickname` varchar(40) NOT NULL COMMENT '昵称',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `sex` enum('0','1') NOT NULL DEFAULT '1' COMMENT '性别',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像',
  `email` varchar(128) DEFAULT NULL COMMENT '邮箱',
  `mobile` varchar(16) DEFAULT NULL COMMENT '手机',
  `level` tinyint(4) NOT NULL DEFAULT '0' COMMENT '等级',
  `birthday` date DEFAULT NULL COMMENT '生日',
  `money` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额(元)',
  `score` int(11) NOT NULL DEFAULT '0' COMMENT '积分',
  `last_time` datetime DEFAULT NULL COMMENT '登录时间',
  `last_ip` varchar(50) DEFAULT NULL COMMENT '登录ip',
  `join_time` datetime DEFAULT NULL COMMENT '注册时间',
  `join_ip` varchar(50) DEFAULT NULL COMMENT '注册ip',
  `token` varchar(50) DEFAULT NULL COMMENT 'token',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  `role` bigint unsigned NOT NULL DEFAULT '1' COMMENT '角色',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '禁用',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `join_time` (`join_time`),
  KEY `mobile` (`mobile`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户表';

-- ======================== User ========================

CREATE TABLE IF NOT EXISTS `erik_users` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    email           VARCHAR(255)    DEFAULT NULL,
    phone           VARCHAR(32)     DEFAULT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    language        VARCHAR(10)     NOT NULL DEFAULT 'en-US',
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    timezone        VARCHAR(64)     NOT NULL DEFAULT 'UTC',
    status          VARCHAR(32)     NOT NULL DEFAULT 'active',
    role            VARCHAR(32)     NOT NULL DEFAULT 'user',
    fcm_token       TEXT            DEFAULT NULL,
    fcm_platform    VARCHAR(16)     DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        DEFAULT NULL,
    UNIQUE INDEX uk_email (email),
    UNIQUE INDEX uk_phone (phone),
    INDEX idx_status (status),
    INDEX idx_role (role),
    INDEX idx_fcm_token (fcm_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_user_profiles` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    avatar          VARCHAR(512)    DEFAULT NULL,
    nickname        VARCHAR(128)    DEFAULT NULL,
    country         VARCHAR(10)     DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_user_kyc` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    user_id             BIGINT          NOT NULL,
    id_type             VARCHAR(32)     NOT NULL,
    id_number_encrypted VARCHAR(512)    NOT NULL,
    real_name           VARCHAR(128)    NOT NULL,
    front_image         VARCHAR(512)    DEFAULT NULL,
    back_image          VARCHAR(512)    DEFAULT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'pending',
    reject_reason       VARCHAR(512)    DEFAULT NULL,
    verified_at         DATETIME        DEFAULT NULL,
    verified_by         BIGINT          DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_user_balances` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    balance         DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    frozen_balance  DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_user_currency (user_id, currency),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_user_addresses` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    type            VARCHAR(32)     NOT NULL DEFAULT 'billing',
    name            VARCHAR(128)    NOT NULL,
    phone           VARCHAR(32)     DEFAULT NULL,
    country         VARCHAR(10)     NOT NULL,
    state           VARCHAR(128)    DEFAULT NULL,
    city            VARCHAR(128)    NOT NULL,
    address         VARCHAR(512)    NOT NULL,
    postcode        VARCHAR(16)     DEFAULT NULL,
    is_default      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_refresh_tokens` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    user_id             BIGINT          NOT NULL,
    token_hash          VARCHAR(128)    NOT NULL,
    device_fingerprint  VARCHAR(255)    NOT NULL DEFAULT '',
    expires_at          DATETIME        NOT NULL,
    revoked             TINYINT(1)      NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_token_hash (token_hash),
    INDEX idx_revoked_expires (revoked, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Product ========================

CREATE TABLE IF NOT EXISTS `erik_product_categories` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    parent_id       BIGINT          DEFAULT NULL,
    name            JSON            NOT NULL,
    slug            VARCHAR(128)    NOT NULL,
    type            VARCHAR(30)     DEFAULT NULL,
    sort            INT             NOT NULL DEFAULT 0,
    icon            VARCHAR(255)    DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_slug (slug),
    INDEX idx_parent_id (parent_id),
    INDEX idx_type (type),
    INDEX idx_sort (sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_products` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    category_id     BIGINT          NOT NULL,
    supplier_id     BIGINT          DEFAULT NULL,
    name            JSON            NOT NULL,
    description     JSON            DEFAULT NULL,
    slug            VARCHAR(255)    NOT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'draft',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_slug (slug),
    INDEX idx_category (category_id),
    INDEX idx_supplier (supplier_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_product_skus` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    product_id      BIGINT          NOT NULL,
    sku_code        VARCHAR(128)    NOT NULL,
    specs           JSON            DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_sku_code (sku_code),
    INDEX idx_product (product_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_product_regions` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    sku_id          BIGINT          NOT NULL,
    region_id       BIGINT          NOT NULL,
    price           DECIMAL(12,4)   NOT NULL,
    original_price  DECIMAL(12,4)   DEFAULT NULL,
    stock           INT             NOT NULL DEFAULT 0,
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_sku_region_currency (sku_id, region_id, currency),
    INDEX idx_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_product_images` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    product_id      BIGINT          NOT NULL,
    url             VARCHAR(512)    NOT NULL,
    sort            INT             NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_product_reviews` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    product_id      BIGINT          NOT NULL,
    order_id        BIGINT          DEFAULT NULL,
    rating          TINYINT         NOT NULL,
    content         TEXT            DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product (product_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_regions` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    name            VARCHAR(128)    NOT NULL,
    continent       VARCHAR(32)     NOT NULL,
    country         VARCHAR(10)     NOT NULL,
    city            VARCHAR(128)    DEFAULT NULL,
    data_center     VARCHAR(128)    DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Order ========================

CREATE TABLE IF NOT EXISTS `erik_carts` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    sku_id          BIGINT          NOT NULL,
    region_id       BIGINT          NOT NULL,
    quantity        INT             NOT NULL DEFAULT 1,
    cycle           VARCHAR(16)     NOT NULL DEFAULT 'monthly',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_orders` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_no        VARCHAR(32)     NOT NULL,
    user_id         BIGINT          NOT NULL,
    type            VARCHAR(32)     NOT NULL DEFAULT 'new',
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    subtotal        DECIMAL(12,4)   NOT NULL DEFAULT 0.0000,
    discount        DECIMAL(12,4)   NOT NULL DEFAULT 0.0000,
    tax             DECIMAL(12,4)   NOT NULL DEFAULT 0.0000,
    total           DECIMAL(12,4)   NOT NULL DEFAULT 0.0000,
    exchange_rate   DECIMAL(12,6)   NOT NULL DEFAULT 1.000000,
    paid_at         DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_order_no (order_no),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_order_items` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    order_id            BIGINT          NOT NULL,
    sku_id              BIGINT          NOT NULL,
    region_id           BIGINT          NOT NULL,
    product_id          BIGINT          NOT NULL,
    quantity            INT             NOT NULL DEFAULT 1,
    cycle               VARCHAR(16)     NOT NULL DEFAULT 'monthly',
    unit_price          DECIMAL(12,4)   NOT NULL,
    total_price         DECIMAL(12,4)   NOT NULL,
    resource_snapshot   JSON            DEFAULT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'pending',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_sku (sku_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_order_timeline` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          NOT NULL,
    status          VARCHAR(32)     NOT NULL,
    operator        VARCHAR(128)    NOT NULL,
    remark          VARCHAR(512)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_refunds` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          NOT NULL,
    user_id         BIGINT          NOT NULL,
    amount          DECIMAL(12,4)   NOT NULL,
    reason          VARCHAR(512)    DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Payment ========================

CREATE TABLE IF NOT EXISTS `erik_payment_channels` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    name                VARCHAR(128)    NOT NULL,
    code                VARCHAR(64)     NOT NULL,
    api_key_encrypted   VARCHAR(1024)   DEFAULT NULL,
    currency_support    JSON            NOT NULL,
    fee_config          JSON            NOT NULL,
    is_visible          TINYINT(1)      NOT NULL DEFAULT 1,
    visible_regions     JSON            DEFAULT NULL,
    min_amount          DECIMAL(12,4)   NOT NULL DEFAULT 0.0000,
    max_amount          DECIMAL(12,4)   NOT NULL DEFAULT 999999.9999,
    webhook_secret      VARCHAR(512)    DEFAULT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'active',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_code (code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_payment_transactions` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          NOT NULL,
    user_id         BIGINT          NOT NULL,
    channel_id      BIGINT          NOT NULL,
    amount          DECIMAL(12,4)   NOT NULL,
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    exchange_rate   DECIMAL(12,6)   NOT NULL DEFAULT 1.000000,
    channel_fee     DECIMAL(12,4)   NOT NULL DEFAULT 0.0000,
    transaction_no  VARCHAR(128)    NOT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    callback_at     DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_transaction_no (transaction_no),
    INDEX idx_order (order_id),
    INDEX idx_user (user_id),
    INDEX idx_channel (channel_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Domain ========================

CREATE TABLE IF NOT EXISTS `erik_domain_tlds` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    tld             VARCHAR(32)     NOT NULL,
    registrar       VARCHAR(128)    NOT NULL,
    retail_price    DECIMAL(12,4)   NOT NULL,
    promo_price     DECIMAL(12,4)   DEFAULT NULL,
    promo_end_at    DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_tld (tld)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_dns_zones` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    domain_name     VARCHAR(255)    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_domain (domain_name),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_dns_records` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    zone_id         BIGINT          NOT NULL,
    type            VARCHAR(16)     NOT NULL,
    name            VARCHAR(255)    NOT NULL,
    value           VARCHAR(512)    NOT NULL,
    ttl             INT             NOT NULL DEFAULT 600,
    priority        INT             DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_zone (zone_id),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Provisioning ========================

CREATE TABLE IF NOT EXISTS `erik_host_machines` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    region_id           BIGINT          NOT NULL,
    name                VARCHAR(255)    NOT NULL,
    ip_address          VARCHAR(64)     NOT NULL,
    proxmox_node        VARCHAR(128)    NOT NULL,
    storage_pool        VARCHAR(128)    NOT NULL DEFAULT 'local-lvm',
    api_token_encrypted VARCHAR(1024)   NOT NULL,
    specs               JSON            NOT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'active',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_region (region_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_ip_pools` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    host_machine_id BIGINT          NOT NULL,
    ip_start        VARCHAR(64)     NOT NULL,
    ip_end          VARCHAR(64)     NOT NULL,
    gateway         VARCHAR(64)     NOT NULL,
    total_count     INT             NOT NULL,
    used_count      INT             NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_host (host_machine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_ip_allocations` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    ip_pool_id      BIGINT          NOT NULL,
    resource_id     BIGINT          NOT NULL,
    ip_address      VARCHAR(64)     NOT NULL,
    type            VARCHAR(32)     NOT NULL DEFAULT 'primary',
    allocated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at     DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_ip (ip_address),
    INDEX idx_pool (ip_pool_id),
    INDEX idx_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_provision_tasks` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          NOT NULL,
    order_item_id   BIGINT          NOT NULL,
    resource_id     BIGINT          DEFAULT NULL,
    product_type    VARCHAR(32)     NOT NULL,
    provider        VARCHAR(64)     NOT NULL,
    region_id       BIGINT          NOT NULL,
    action          VARCHAR(32)     NOT NULL DEFAULT 'create',
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    params          TEXT            DEFAULT NULL,
    retry_count     INT             NOT NULL DEFAULT 0,
    last_error      TEXT            DEFAULT NULL,
    next_retry_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_order_item (order_item_id),
    INDEX idx_resource (resource_id),
    INDEX idx_status_next (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_resources` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_item_id   BIGINT          NOT NULL,
    user_id         BIGINT          NOT NULL,
    product_id      BIGINT          NOT NULL,
    type            VARCHAR(32)     NOT NULL,
    provider        VARCHAR(64)     NOT NULL,
    region_id       BIGINT          NOT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'provisioning',
    specs           JSON            DEFAULT NULL,
    provisioned_at  DATETIME        DEFAULT NULL,
    expired_at      DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_order_item (order_item_id),
    INDEX idx_type (type),
    INDEX idx_status (status),
    INDEX idx_expired (expired_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_disks` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    resource_id     BIGINT          NOT NULL,
    host_machine_id BIGINT          NOT NULL,
    vm_id           VARCHAR(128)    DEFAULT NULL,
    size_gb         INT             NOT NULL,
    disk_type       VARCHAR(32)     NOT NULL DEFAULT 'ssd',
    storage_pool    VARCHAR(128)    NOT NULL DEFAULT 'local-lvm',
    device_path     VARCHAR(255)    DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'creating',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id),
    INDEX idx_host (host_machine_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_disk_resizes` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    disk_id         BIGINT          NOT NULL,
    old_size_gb     INT             NOT NULL,
    new_size_gb     INT             NOT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    finished_at     DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_disk (disk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Ticket ========================

CREATE TABLE IF NOT EXISTS `erik_tickets` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    ticket_no       VARCHAR(32)     NOT NULL,
    user_id         BIGINT          NOT NULL,
    resource_id     BIGINT          DEFAULT NULL,
    category        VARCHAR(64)     NOT NULL,
    priority        VARCHAR(32)     NOT NULL DEFAULT 'normal',
    title           VARCHAR(512)    NOT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'open',
    assigned_to     BIGINT          DEFAULT NULL,
    sla_deadline    DATETIME        DEFAULT NULL,
    closed_by       BIGINT          DEFAULT NULL,
    closed_at       DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_ticket_no (ticket_no),
    INDEX idx_user (user_id),
    INDEX idx_resource (resource_id),
    INDEX idx_status (status),
    INDEX idx_assigned (assigned_to),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_ticket_messages` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    ticket_id       BIGINT          NOT NULL,
    sender_id       BIGINT          NOT NULL,
    sender_type     VARCHAR(32)     NOT NULL DEFAULT 'user',
    content         TEXT            NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket (ticket_id),
    INDEX idx_sender (sender_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Supplier ========================

CREATE TABLE IF NOT EXISTS `erik_suppliers` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    user_id             BIGINT          NOT NULL,
    company_name        VARCHAR(255)    NOT NULL,
    contact_name        VARCHAR(128)    NOT NULL,
    contact_phone       VARCHAR(32)     NOT NULL,
    contact_email       VARCHAR(255)    NOT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'pending',
    settlement_method   VARCHAR(32)     NOT NULL DEFAULT 'bank',
    approved_by         BIGINT          DEFAULT NULL,
    approved_at         DATETIME        DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_supplier_settlements` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    supplier_id     BIGINT          NOT NULL,
    period_start    DATE            NOT NULL,
    period_end      DATE            NOT NULL,
    total_sales     DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    commission      DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    payable         DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supplier (supplier_id),
    INDEX idx_period (period_start, period_end),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_supplier_withdraws` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    supplier_id     BIGINT          NOT NULL,
    amount          DECIMAL(16,4)   NOT NULL,
    method          VARCHAR(32)     NOT NULL DEFAULT 'bank',
    account_info    JSON            DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supplier (supplier_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Notification ========================

CREATE TABLE IF NOT EXISTS `erik_notifications` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    channel         VARCHAR(32)     NOT NULL DEFAULT 'in_app',
    template_code   VARCHAR(128)    NOT NULL,
    content         JSON            NOT NULL,
    send_status     VARCHAR(32)     NOT NULL DEFAULT 'queued',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (send_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erik_notification_templates` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    code            VARCHAR(128)    NOT NULL,
    name            VARCHAR(255)    NOT NULL,
    title           JSON            NOT NULL,
    body            JSON            NOT NULL,
    channels        VARCHAR(255)    NOT NULL DEFAULT 'in_app',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Monitor ========================

CREATE TABLE IF NOT EXISTS `erik_alerts` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    rule_code       VARCHAR(128)    NOT NULL,
    severity        VARCHAR(32)     NOT NULL DEFAULT 'warning',
    resource_id     BIGINT          DEFAULT NULL,
    user_id         BIGINT          NOT NULL DEFAULT 0,
    context         JSON            DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'triggered',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id),
    INDEX idx_user (user_id),
    INDEX idx_rule (rule_code),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Audit ========================

CREATE TABLE IF NOT EXISTS `erik_audit_logs` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          DEFAULT NULL,
    action          VARCHAR(128)    NOT NULL,
    resource_type   VARCHAR(128)    DEFAULT NULL,
    resource_id     BIGINT          DEFAULT NULL,
    ip_address      VARCHAR(64)     DEFAULT NULL,
    user_agent      VARCHAR(512)    DEFAULT NULL,
    old_values      JSON            DEFAULT NULL,
    new_values      JSON            DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
