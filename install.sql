-- ============================================================
--
-- 新增表请通过迁移系统创建: cd service && php webman migrate
-- 本文件仅包含初始安装所需的核心表结构
--

-- CloudPlatform — Unified Database DDL
-- Tables: wa_* (admin panel) + business tables (no prefix)
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

CREATE TABLE IF NOT EXISTS `users` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    email           VARCHAR(255)    DEFAULT NULL,
    phone           VARCHAR(32)     DEFAULT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    language        VARCHAR(10)     NOT NULL DEFAULT 'en-US',
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    timezone        VARCHAR(64)     NOT NULL DEFAULT 'UTC',
    status          VARCHAR(32)     NOT NULL DEFAULT 'active',
    role            VARCHAR(32)     NOT NULL DEFAULT 'user',
    affiliate_code  VARCHAR(32)     DEFAULT NULL,
    fcm_token       TEXT            DEFAULT NULL,
    fcm_platform    VARCHAR(16)     DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        DEFAULT NULL,
    UNIQUE INDEX uk_email (email),
    UNIQUE INDEX uk_phone (phone),
    INDEX idx_status (status),
    INDEX idx_role (role),
    INDEX idx_affiliate_code (affiliate_code),
    INDEX idx_fcm_token (fcm_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_profiles` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    avatar          VARCHAR(512)    DEFAULT NULL,
    nickname        VARCHAR(128)    DEFAULT NULL,
    country         VARCHAR(10)     DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_kyc` (
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

CREATE TABLE IF NOT EXISTS `user_balance` (
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

CREATE TABLE IF NOT EXISTS `user_addresses` (
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

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
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

CREATE TABLE IF NOT EXISTS `product_categories` (
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

CREATE TABLE IF NOT EXISTS `products` (
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

CREATE TABLE IF NOT EXISTS `product_skus` (
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

CREATE TABLE IF NOT EXISTS `product_regions` (
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

CREATE TABLE IF NOT EXISTS `product_images` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    product_id      BIGINT          NOT NULL,
    url             VARCHAR(512)    NOT NULL,
    sort            INT             NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_reviews` (
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

CREATE TABLE IF NOT EXISTS `regions` (
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

CREATE TABLE IF NOT EXISTS `carts` (
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

CREATE TABLE IF NOT EXISTS `orders` (
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

CREATE TABLE IF NOT EXISTS `order_items` (
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

CREATE TABLE IF NOT EXISTS `order_timeline` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          NOT NULL,
    status          VARCHAR(32)     NOT NULL,
    operator        VARCHAR(128)    NOT NULL,
    remark          VARCHAR(512)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refunds` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          NOT NULL,
    user_id         BIGINT          NOT NULL,
    amount          DECIMAL(12,4)   NOT NULL,
    reason          VARCHAR(512)    DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    handled_by      BIGINT          DEFAULT NULL,
    reject_reason   VARCHAR(512)    DEFAULT NULL,
    pending_order_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'pending', order_id, NULL)) VIRTUAL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_refunds_pending_order (pending_order_id),
    INDEX idx_order (order_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Payment ========================

CREATE TABLE IF NOT EXISTS `payment_channels` (
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

CREATE TABLE IF NOT EXISTS `payment_transactions` (
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

CREATE TABLE IF NOT EXISTS `domain_tlds` (
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

CREATE TABLE IF NOT EXISTS `dns_zones` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    domain_name     VARCHAR(255)    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_domain (domain_name),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dns_records` (
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

CREATE TABLE IF NOT EXISTS `host_machines` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    region_id           BIGINT          NOT NULL,
    name                VARCHAR(255)    NOT NULL,
    ip_address          VARCHAR(64)     NOT NULL,
    proxmox_node        VARCHAR(128)    NOT NULL,
    storage_pool        VARCHAR(128)    NOT NULL DEFAULT 'local-lvm',
    api_token_encrypted VARCHAR(1024)   NOT NULL,
    hypervisor          VARCHAR(16)     NOT NULL DEFAULT 'proxmox',
    kvm_connection      VARCHAR(255)    DEFAULT NULL,
    specs               JSON            NOT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'active',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_region (region_id),
    INDEX idx_status (status),
    INDEX idx_hypervisor (hypervisor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ip_pools` (
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

CREATE TABLE IF NOT EXISTS `ip_allocations` (
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

CREATE TABLE IF NOT EXISTS `provision_tasks` (
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

CREATE TABLE IF NOT EXISTS `resources` (
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

CREATE TABLE IF NOT EXISTS `disks` (
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

CREATE TABLE IF NOT EXISTS `disk_resizes` (
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

-- KVM 服务隔离模型：每 VM 一条网络/防火墙/交换器服务记录即隔离单元
CREATE TABLE IF NOT EXISTS `network_services` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    host_machine_id BIGINT          NOT NULL,
    resource_id     BIGINT          NOT NULL,
    vm_id           VARCHAR(128)    NOT NULL,
    bridge_name     VARCHAR(64)     NOT NULL,
    subnet          VARCHAR(45)     DEFAULT NULL,
    gateway_ip      VARCHAR(45)     DEFAULT NULL,
    status          VARCHAR(20)     NOT NULL DEFAULT 'creating',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_host_bridge (host_machine_id, bridge_name),
    INDEX idx_host_resource (host_machine_id, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `firewall_services` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    host_machine_id BIGINT          NOT NULL,
    resource_id     BIGINT          NOT NULL,
    vm_id           VARCHAR(128)    NOT NULL,
    table_name      VARCHAR(64)     NOT NULL,
    default_policy  VARCHAR(16)     NOT NULL DEFAULT 'drop',
    rules           JSON            DEFAULT NULL,
    status          VARCHAR(20)     NOT NULL DEFAULT 'creating',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_host_table (host_machine_id, table_name),
    INDEX idx_host_resource (host_machine_id, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `switch_services` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    host_machine_id     BIGINT          NOT NULL,
    resource_id         BIGINT          NOT NULL,
    vm_id               VARCHAR(128)    NOT NULL,
    network_service_id  BIGINT          NOT NULL,
    veth_host           VARCHAR(64)     NOT NULL,
    veth_guest          VARCHAR(64)     NOT NULL,
    mac_address         VARCHAR(32)     DEFAULT NULL,
    status              VARCHAR(20)     NOT NULL DEFAULT 'creating',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_host_veth (host_machine_id, veth_host),
    INDEX idx_host_resource (host_machine_id, resource_id),
    INDEX idx_network_service (network_service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Ticket ========================

CREATE TABLE IF NOT EXISTS `tickets` (
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

CREATE TABLE IF NOT EXISTS `ticket_messages` (
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

CREATE TABLE IF NOT EXISTS `suppliers` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    user_id             BIGINT          NOT NULL,
    company_name        VARCHAR(255)    NOT NULL,
    contact_name        VARCHAR(128)    NOT NULL,
    contact_phone       VARCHAR(32)     NOT NULL,
    contact_email       VARCHAR(255)    NOT NULL,
    status              VARCHAR(32)     NOT NULL DEFAULT 'pending',
    rating_avg          DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
    rating_count        INT             NOT NULL DEFAULT 0,
    settlement_method   VARCHAR(32)     NOT NULL DEFAULT 'bank',
    approved_by         BIGINT          DEFAULT NULL,
    approved_at         DATETIME        DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_settlements` (
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

CREATE TABLE IF NOT EXISTS `supplier_withdraws` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    supplier_id     BIGINT          NOT NULL,
    amount          DECIMAL(16,4)   NOT NULL,
    method          VARCHAR(32)     NOT NULL DEFAULT 'bank',
    account_info    JSON            DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    handled_by      BIGINT          DEFAULT NULL,
    handled_at      DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supplier (supplier_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Notification ========================

CREATE TABLE IF NOT EXISTS `notifications` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    channel         VARCHAR(32)     NOT NULL DEFAULT 'in_app',
    template_code   VARCHAR(128)    NOT NULL,
    content         JSON            NOT NULL,
    send_status     VARCHAR(32)     NOT NULL DEFAULT 'queued',
    read_at         DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (send_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_templates` (
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

CREATE TABLE IF NOT EXISTS `alerts` (
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

CREATE TABLE IF NOT EXISTS `audit_logs` (
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

-- ======================== Coupon ========================

CREATE TABLE IF NOT EXISTS `coupons` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    code            VARCHAR(50)     NOT NULL,
    type            VARCHAR(20)     NOT NULL DEFAULT 'percentage',
    value           DECIMAL(10,2)   NOT NULL,
    min_amount      DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    max_discount    DECIMAL(16,4)   DEFAULT NULL,
    max_uses        INT             NOT NULL DEFAULT 0,
    used_count      INT             NOT NULL DEFAULT 0,
    starts_at       DATETIME        DEFAULT NULL,
    expires_at      DATETIME        DEFAULT NULL,
    status          VARCHAR(20)     NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_code (code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_coupons` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    coupon_id       BIGINT          NOT NULL,
    order_id        BIGINT          DEFAULT NULL,
    used_at         DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_coupon (coupon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== User ========================

CREATE TABLE IF NOT EXISTS `user_balance_log` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    type            VARCHAR(30)     NOT NULL,
    currency        VARCHAR(3)      NOT NULL,
    amount          DECIMAL(16,4)   NOT NULL,
    balance_before  DECIMAL(16,4)   NOT NULL,
    balance_after   DECIMAL(16,4)   NOT NULL,
    order_id        BIGINT          DEFAULT NULL,
    remark          VARCHAR(500)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Order ========================

CREATE TABLE IF NOT EXISTS `order_invoices` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          NOT NULL,
    user_id         BIGINT          NOT NULL,
    type            VARCHAR(20)     NOT NULL DEFAULT 'personal',
    title           VARCHAR(200)    NOT NULL,
    tax_number      VARCHAR(50)     DEFAULT NULL,
    amount          DECIMAL(14,4)   NOT NULL,
    file_url        VARCHAR(500)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Payment ========================

CREATE TABLE IF NOT EXISTS `payment_reconcile` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    date            DATE            NOT NULL,
    channel_id      BIGINT          NOT NULL,
    channel_total   DECIMAL(14,4)   NOT NULL DEFAULT 0.0000,
    system_total    DECIMAL(14,4)   NOT NULL DEFAULT 0.0000,
    diff            DECIMAL(14,4)   NOT NULL DEFAULT 0.0000,
    status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_reconcile_channel_date (channel_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Provisioning ========================

CREATE TABLE IF NOT EXISTS `resource_servers` (
    id                       BIGINT          NOT NULL PRIMARY KEY,
    resource_id              BIGINT          NOT NULL,
    hostname                 VARCHAR(255)    DEFAULT NULL,
    ip_address               VARCHAR(45)     DEFAULT NULL,
    login_user               VARCHAR(50)     DEFAULT NULL,
    login_password_encrypted VARCHAR(500)    DEFAULT NULL,
    os                       VARCHAR(100)    DEFAULT NULL,
    cpu                      INT             NOT NULL DEFAULT 0,
    ram                      INT             NOT NULL DEFAULT 0,
    disk                     INT             NOT NULL DEFAULT 0,
    bandwidth                INT             NOT NULL DEFAULT 0,
    panel_url                VARCHAR(500)    DEFAULT NULL,
    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_ips` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    resource_id     BIGINT          NOT NULL,
    ip_address      VARCHAR(45)     NOT NULL,
    subnet          VARCHAR(45)     DEFAULT NULL,
    gateway         VARCHAR(45)     DEFAULT NULL,
    rdns            VARCHAR(255)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_disks` (
    id                      BIGINT          NOT NULL PRIMARY KEY,
    resource_id             BIGINT          NOT NULL,
    disk_size               INT             NOT NULL,
    disk_type               VARCHAR(10)     NOT NULL DEFAULT 'ssd',
    attach_to_resource_id   BIGINT          DEFAULT NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_domains` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    resource_id     BIGINT          NOT NULL,
    domain_name     VARCHAR(255)    NOT NULL,
    registrar       VARCHAR(50)     DEFAULT NULL,
    dns_servers     JSON            DEFAULT NULL,
    whois_privacy   TINYINT(1)      NOT NULL DEFAULT 0,
    auto_renew      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `provider_apis` (
    id                      BIGINT          NOT NULL PRIMARY KEY,
    name                    VARCHAR(100)    NOT NULL,
    code                    VARCHAR(50)     NOT NULL,
    api_key_encrypted       VARCHAR(500)    DEFAULT NULL,
    api_secret_encrypted    VARCHAR(500)    DEFAULT NULL,
    webhook_secret          VARCHAR(255)    DEFAULT NULL,
    status                  VARCHAR(20)     NOT NULL DEFAULT 'active',
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Supplier ========================

CREATE TABLE IF NOT EXISTS `supplier_products` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    supplier_id     BIGINT          NOT NULL,
    product_id      BIGINT          NOT NULL,
    approved_at     DATETIME        DEFAULT NULL,
    commission_rate DECIMAL(5,4)    NOT NULL DEFAULT 0.1000,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_supplier_product (supplier_id, product_id),
    INDEX idx_supplier (supplier_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_ratings` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    supplier_id     BIGINT          NOT NULL,
    user_id         BIGINT          NOT NULL,
    order_id        BIGINT          NOT NULL,
    rating          TINYINT         NOT NULL,
    quality         TINYINT         NOT NULL DEFAULT 0,
    support         TINYINT         NOT NULL DEFAULT 0,
    delivery_speed  TINYINT         NOT NULL DEFAULT 0,
    value           TINYINT         NOT NULL DEFAULT 0,
    content         TEXT            DEFAULT NULL,
    status          VARCHAR(16)     NOT NULL DEFAULT 'published',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_user_order (user_id, order_id),
    INDEX idx_supplier (supplier_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_api_keys` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    supplier_id     BIGINT          NOT NULL,
    name            VARCHAR(64)     DEFAULT NULL,
    key_hash        VARCHAR(64)     NOT NULL,
    key_prefix      VARCHAR(10)     NOT NULL,
    revoked         TINYINT(1)      NOT NULL DEFAULT 0,
    last_used_at    DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_key_hash (key_hash),
    INDEX idx_supplier (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Domain ========================

CREATE TABLE IF NOT EXISTS `domain_transfers` (
    id                      BIGINT          NOT NULL PRIMARY KEY,
    domain_name             VARCHAR(255)    NOT NULL,
    user_id                 BIGINT          NOT NULL,
    auth_code_encrypted     VARCHAR(500)    NOT NULL,
    from_registrar          VARCHAR(50)     NOT NULL,
    status                  VARCHAR(20)     NOT NULL DEFAULT 'pending',
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Help ========================

CREATE TABLE IF NOT EXISTS `help_articles` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    category        VARCHAR(50)     NOT NULL,
    title           VARCHAR(200)    NOT NULL,
    slug            VARCHAR(200)    NOT NULL,
    content         TEXT            NOT NULL,
    locale          VARCHAR(10)     NOT NULL DEFAULT 'en-US',
    sort            INT             NOT NULL DEFAULT 0,
    status          VARCHAR(20)     NOT NULL DEFAULT 'published',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_slug (slug),
    INDEX idx_category_status (category, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== RBAC ========================

CREATE TABLE IF NOT EXISTS `roles` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    name            VARCHAR(50)     NOT NULL,
    display_name    VARCHAR(100)    NOT NULL,
    description     TEXT            DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    name            VARCHAR(100)    NOT NULL,
    display_name    VARCHAR(100)    NOT NULL,
    `group`         VARCHAR(50)     NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permission` (
    role_id         BIGINT          NOT NULL,
    permission_id   BIGINT          NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    INDEX idx_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 权限模型唯一事实源为 service/common/Auth/Rbac.php；
-- 种子数据由迁移系统维护（service/database/migrations/2026_08_17_000001_seed_rbac_permissions.php），
-- 本文件仅含表结构

-- ======================== SSL ========================

CREATE TABLE IF NOT EXISTS `ssl_plans` (
    id                  BIGINT          NOT NULL PRIMARY KEY,
    name                VARCHAR(128)    NOT NULL,
    cert_type           VARCHAR(10)     NOT NULL DEFAULT 'DV',
    brand               VARCHAR(64)     DEFAULT NULL,
    validity_days       INT             NOT NULL DEFAULT 90,
    validation_method   VARCHAR(16)     NOT NULL DEFAULT 'dns-01',
    wildcard            TINYINT(1)      NOT NULL DEFAULT 0,
    ca_provider         VARCHAR(64)     NOT NULL DEFAULT 'letsencrypt',
    wholesale_price     DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    retail_price        DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    currency            VARCHAR(3)      NOT NULL DEFAULT 'USD',
    status              VARCHAR(32)     NOT NULL DEFAULT 'active',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cert_type (cert_type),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_ssl_certs` (
    id                      BIGINT          NOT NULL PRIMARY KEY,
    resource_id             BIGINT          NOT NULL,
    domain_name             VARCHAR(255)    NOT NULL,
    cert_type               VARCHAR(10)     NOT NULL DEFAULT 'DV',
    wildcard                TINYINT(1)      NOT NULL DEFAULT 0,
    validity_days           INT             NOT NULL DEFAULT 90,
    status                  VARCHAR(32)     NOT NULL DEFAULT 'pending',
    csr                     TEXT            DEFAULT NULL,
    cert_pem                TEXT            DEFAULT NULL,
    private_key_encrypted   TEXT            DEFAULT NULL,
    issuer                  VARCHAR(128)    DEFAULT NULL,
    issued_at               DATETIME        DEFAULT NULL,
    expires_at              DATETIME        DEFAULT NULL,
    auto_renew              TINYINT(1)      NOT NULL DEFAULT 1,
    validation_method       VARCHAR(16)     NOT NULL DEFAULT 'http-01',
    challenge               JSON            DEFAULT NULL,
    last_checked_at         DATETIME        DEFAULT NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id),
    INDEX idx_status (status),
    INDEX idx_expires (expires_at),
    INDEX idx_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Storage ========================

CREATE TABLE IF NOT EXISTS `resource_storage_buckets` (
    id                      BIGINT          NOT NULL PRIMARY KEY,
    resource_id             BIGINT          NOT NULL,
    bucket_name             VARCHAR(255)    NOT NULL,
    endpoint                VARCHAR(512)    NOT NULL,
    region                  VARCHAR(64)     DEFAULT NULL,
    access_key_encrypted    TEXT            DEFAULT NULL,
    secret_key_encrypted    TEXT            DEFAULT NULL,
    quota_gb                INT             NOT NULL DEFAULT 10,
    used_gb                 DECIMAL(12,4)   NOT NULL DEFAULT 0.0000,
    status                  VARCHAR(32)     NOT NULL DEFAULT 'pending',
    policy                  JSON            DEFAULT NULL,
    provider_type           VARCHAR(32)     NOT NULL DEFAULT 's3',
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Usage Billing ========================

CREATE TABLE IF NOT EXISTS `resource_metrics` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    resource_id     BIGINT          NOT NULL,
    metric          VARCHAR(32)     NOT NULL,
    value           DECIMAL(20,4)   NOT NULL,
    sample_at       DATETIME        NOT NULL,
    INDEX idx_resource_metric_time (resource_id, metric, sample_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_events` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    resource_id     BIGINT          NOT NULL,
    order_item_id   BIGINT          DEFAULT NULL,
    meter           VARCHAR(32)     NOT NULL,
    quantity        DECIMAL(20,6)   NOT NULL,
    period_start    DATETIME        NOT NULL,
    period_end      DATETIME        NOT NULL,
    status          VARCHAR(16)     NOT NULL DEFAULT 'open',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_event (resource_id, meter, period_start),
    INDEX idx_status_period (status, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_rates` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    sku_id          BIGINT          NOT NULL,
    region_id       BIGINT          DEFAULT NULL,
    meter           VARCHAR(32)     NOT NULL,
    unit_price      DECIMAL(16,8)   NOT NULL,
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    unit            VARCHAR(16)     NOT NULL DEFAULT 'GB',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_rate (sku_id, region_id, meter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_invoice_items` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    order_id        BIGINT          DEFAULT NULL,
    resource_id     BIGINT          NOT NULL,
    meter           VARCHAR(32)     NOT NULL,
    quantity        DECIMAL(20,6)   NOT NULL,
    amount          DECIMAL(16,4)   NOT NULL,
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    period_start    DATETIME        NOT NULL,
    period_end      DATETIME        NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== CDN ========================

CREATE TABLE IF NOT EXISTS `resource_cdn` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    resource_id     BIGINT          NOT NULL,
    cdn_domain      VARCHAR(255)    NOT NULL,
    origin_type     VARCHAR(16)     NOT NULL DEFAULT 'server',
    origin_value    VARCHAR(512)    NOT NULL,
    plan            VARCHAR(32)     NOT NULL DEFAULT 'standard',
    `ssl`           TINYINT(1)      NOT NULL DEFAULT 1,
    cache_rules     JSON            DEFAULT NULL,
    status          VARCHAR(32)     NOT NULL DEFAULT 'pending',
    purged_at       DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource (resource_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================== Affiliate ========================

CREATE TABLE IF NOT EXISTS `affiliate_plans` (
    id                      BIGINT          NOT NULL PRIMARY KEY,
    name                    VARCHAR(128)    NOT NULL,
    commission_rate         DECIMAL(5,2)    NOT NULL,
    tier                    INT             NOT NULL DEFAULT 1,
    min_payout              DECIMAL(12,4)   NOT NULL DEFAULT 50.0000,
    lifetime_commissions    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `affiliate_links` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    user_id         BIGINT          NOT NULL,
    code            VARCHAR(32)     NOT NULL,
    source          VARCHAR(64)     DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uk_code (code),
    UNIQUE INDEX uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `affiliate_earnings` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    affiliate_id    BIGINT          NOT NULL,
    order_id        BIGINT          NOT NULL,
    user_id         BIGINT          NOT NULL,
    rate            DECIMAL(5,2)    NOT NULL,
    amount          DECIMAL(12,4)   NOT NULL,
    currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
    status          VARCHAR(16)     NOT NULL DEFAULT 'pending',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_affiliate (affiliate_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `affiliate_payouts` (
    id              BIGINT          NOT NULL PRIMARY KEY,
    affiliate_id    BIGINT          NOT NULL,
    amount          DECIMAL(12,4)   NOT NULL,
    status          VARCHAR(16)     NOT NULL DEFAULT 'pending',
    admin_notes     TEXT            DEFAULT NULL,
    paid_at         DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_affiliate (affiliate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
