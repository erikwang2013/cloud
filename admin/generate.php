<?php

/**
 * Business entity generator for admin panel.
 * Reads docs/database.sql and generates models, controllers, views, and menu config
 * for all erik_* business tables.
 *
 * Usage: php admin/generate.php
 */

$sqlFile = __DIR__ . '/../docs/database.sql';
if (!file_exists($sqlFile)) {
    echo "Error: {$sqlFile} not found\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);

// Tables to skip (already managed by admin's own User model)
$skipTables = ['erik_users', 'erik_user_profiles'];

// Tables that are read-only (system-generated records, no insert/update/delete UI)
$readOnlyTables = [
    'erik_audit_logs',
    'erik_notifications',
    'erik_alerts',
    'erik_order_timeline',
    'erik_payment_transactions',
    'erik_ip_allocations',
    'erik_refresh_tokens',
];

// Parse all CREATE TABLE statements
preg_match_all(
    '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*ENGINE=/is',
    $sql, $matches, PREG_SET_ORDER
);

if (empty($matches)) {
    echo "Error: no CREATE TABLE statements found\n";
    exit(1);
}

$constraintKeywords = ['primary', 'unique', 'index', 'key', 'foreign', 'constraint', 'check', 'fulltext', 'spatial'];
$constraintLinePatterns = ['/^\s*(UNIQUE|PRIMARY|INDEX|KEY|FOREIGN|CONSTRAINT|CHECK|FULLTEXT|SPATIAL)\s/i'];

$tables = [];
foreach ($matches as $match) {
    $tableName = $match[1];
    if (!str_starts_with($tableName, 'erik_') || in_array($tableName, $skipTables)) {
        continue;
    }
    $body = $match[2];
    $columns = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        // Skip constraint/index lines
        $skip = false;
        foreach ($constraintLinePatterns as $pattern) {
            if (preg_match($pattern, $line)) { $skip = true; break; }
        }
        if ($skip) continue;
        if (preg_match('/^`?(\w+)`?\s+(\w+)/', $line, $colMatch)) {
            $colName = $colMatch[1];
            $colType = strtolower($colMatch[2]);
            if (in_array($colType, $constraintKeywords) || in_array(strtolower($colName), $constraintKeywords)) continue;
            $columns[$colName] = $colType;
        }
    }
    if (!empty($columns)) {
        $tables[$tableName] = $columns;
    }
}

// Map table name to class name
function tableToClass(string $table): string
{
    static $map = [
        'erik_user_kyc' => 'UserKyc',
        'erik_user_balances' => 'UserBalance',
        'erik_user_addresses' => 'UserAddress',
        'erik_refresh_tokens' => 'RefreshToken',
        'erik_product_categories' => 'ProductCategory',
        'erik_products' => 'Product',
        'erik_product_skus' => 'ProductSku',
        'erik_product_regions' => 'ProductRegion',
        'erik_product_images' => 'ProductImage',
        'erik_product_reviews' => 'ProductReview',
        'erik_regions' => 'Region',
        'erik_carts' => 'Cart',
        'erik_orders' => 'Order',
        'erik_order_items' => 'OrderItem',
        'erik_order_timeline' => 'OrderTimeline',
        'erik_refunds' => 'Refund',
        'erik_payment_channels' => 'PaymentChannel',
        'erik_payment_transactions' => 'PaymentTransaction',
        'erik_domain_tlds' => 'DomainTld',
        'erik_dns_zones' => 'DnsZone',
        'erik_dns_records' => 'DnsRecord',
        'erik_host_machines' => 'HostMachine',
        'erik_ip_pools' => 'IpPool',
        'erik_ip_allocations' => 'IpAllocation',
        'erik_provision_tasks' => 'ProvisionTask',
        'erik_resources' => 'Resource',
        'erik_disks' => 'Disk',
        'erik_disk_resizes' => 'DiskResize',
        'erik_tickets' => 'Ticket',
        'erik_ticket_messages' => 'TicketMessage',
        'erik_suppliers' => 'Supplier',
        'erik_supplier_settlements' => 'SupplierSettlement',
        'erik_supplier_withdraws' => 'SupplierWithdraw',
        'erik_notifications' => 'Notification',
        'erik_notification_templates' => 'NotificationTemplate',
        'erik_alerts' => 'Alert',
        'erik_audit_logs' => 'AuditLog',
    ];
    return $map[$table] ?? $table;
}

function classToViewKey(string $className): string
{
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));
}

function tableToLabel(string $table): string
{
    static $map = [
        'erik_user_kyc' => 'KYC审核',
        'erik_user_balances' => '用户余额',
        'erik_user_addresses' => '用户地址',
        'erik_refresh_tokens' => '刷新令牌',
        'erik_product_categories' => '产品分类',
        'erik_products' => '产品',
        'erik_product_skus' => 'SKU',
        'erik_product_regions' => '产品区域定价',
        'erik_product_images' => '产品图片',
        'erik_product_reviews' => '产品评价',
        'erik_regions' => '区域',
        'erik_carts' => '购物车',
        'erik_orders' => '订单',
        'erik_order_items' => '订单项',
        'erik_order_timeline' => '订单时间线',
        'erik_refunds' => '退款',
        'erik_payment_channels' => '支付通道',
        'erik_payment_transactions' => '支付交易',
        'erik_domain_tlds' => '域名TLD',
        'erik_dns_zones' => 'DNS区域',
        'erik_dns_records' => 'DNS记录',
        'erik_host_machines' => '物理主机',
        'erik_ip_pools' => 'IP池',
        'erik_ip_allocations' => 'IP分配',
        'erik_provision_tasks' => '交付任务',
        'erik_resources' => '云服务器',
        'erik_disks' => '云磁盘',
        'erik_disk_resizes' => '磁盘扩容',
        'erik_tickets' => '工单',
        'erik_ticket_messages' => '工单回复',
        'erik_suppliers' => '供应商',
        'erik_supplier_settlements' => '供应商结算',
        'erik_supplier_withdraws' => '供应商提现',
        'erik_notifications' => '通知记录',
        'erik_notification_templates' => '通知模板',
        'erik_alerts' => '告警记录',
        'erik_audit_logs' => '审计日志',
    ];
    return $map[$table] ?? $table;
}

// ============================================================
// Generate Models
// ============================================================
$modelDir = __DIR__ . '/app/model/';
@mkdir($modelDir, 0755, true);

// Delete previously generated files
foreach (glob($modelDir . '*.php') as $f) {
    $name = basename($f, '.php');
    // Only delete erik_* business models, not existing admin models
    $adminModels = ['Base', 'Admin', 'AdminRole', 'Dict', 'Option', 'Role', 'Rule', 'Upload', 'User'];
    if (!in_array($name, $adminModels)) {
        unlink($f);
    }
}

foreach ($tables as $table => $columns) {
    $className = tableToClass($table);
    $fillable = array_keys($columns);
    $fillable = array_values(array_filter($fillable, fn($c) => !in_array($c, ['id', 'created_at', 'updated_at', 'deleted_at'])));

    $fillableStr = "['" . implode("', '", $fillable) . "']";

    $modelCode = <<<PHP
<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\\model;

class {$className} extends Base
{
    protected \$table = '{$table}';
    protected \$fillable = {$fillableStr};
}

PHP;

    file_put_contents($modelDir . $className . '.php', $modelCode);
    echo "Model: {$className}.php\n";
}

// ============================================================
// Generate Controllers
// ============================================================
$controllerDir = __DIR__ . '/app/controller/';
@mkdir($controllerDir, 0755, true);

// Delete previously generated controllers
$adminControllers = ['Base', 'Crud', 'AccountController', 'AdminController', 'ConfigController',
    'DashboardController', 'DevController', 'DictController', 'IndexController', 'InstallController',
    'PluginController', 'RoleController', 'RuleController', 'TableController', 'UploadController', 'UserController'];
foreach (glob($controllerDir . '*.php') as $f) {
    $name = basename($f, '.php');
    if (!in_array($name, $adminControllers)) {
        unlink($f);
    }
}

foreach ($tables as $table => $columns) {
    $className = tableToClass($table);
    $label = tableToLabel($table);
    $viewKey = classToViewKey($className);

    $controllerCode = <<<PHP
<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\\controller;

use app\\model\\{$className};
use support\\Request;
use support\\Response;
use Throwable;

/**
 * {$label}管理
 */
class {$className}Controller extends Crud
{
    /**
     * @var {$className}
     */
    protected \$model = null;

    public function __construct()
    {
        \$this->model = new {$className};
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('{$viewKey}/index');
    }

    /**
     * 插入
     * @param Request \$request
     * @return Response
     * @throws Throwable
     */
    public function insert(Request \$request): Response
    {
        if (\$request->method() === 'POST') {
            return parent::insert(\$request);
        }
        return raw_view('{$viewKey}/insert');
    }

    /**
     * 更新
     * @param Request \$request
     * @return Response
     * @throws Throwable
     */
    public function update(Request \$request): Response
    {
        if (\$request->method() === 'POST') {
            return parent::update(\$request);
        }
        return raw_view('{$viewKey}/update');
    }
}

PHP;

    file_put_contents($controllerDir . $className . 'Controller.php', $controllerCode);
    echo "Controller: {$className}Controller.php\n";
}

// ============================================================
// Generate Views
// ============================================================
$viewBaseDir = __DIR__ . '/app/view/';

// Helper: determine best search fields (first 3-4 non-id, non-timestamp fields)
function getSearchFields(array $columns): array
{
    $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'password_hash',
        'api_key_encrypted', 'api_token_encrypted', 'webhook_secret',
        'id_number_encrypted', 'front_image', 'back_image', 'avatar',
        'token_hash', 'device_fingerprint', 'content', 'params', 'last_error',
        'old_values', 'new_values', 'user_agent', 'specs', 'resource_snapshot',
        'account_info', 'currency_support', 'fee_config', 'visible_regions',
        'description', 'body'];
    $fields = [];
    foreach ($columns as $col => $type) {
        if (in_array($col, $skip)) continue;
        $fields[] = $col;
        if (count($fields) >= 3) break;
    }
    return $fields;
}

function getTableColumns(array $columns): array
{
    $skip = ['password_hash', 'api_key_encrypted', 'api_token_encrypted', 'webhook_secret',
        'id_number_encrypted', 'token_hash', 'device_fingerprint', 'params', 'last_error',
        'old_values', 'new_values', 'user_agent'];
    $show = [];
    $hide = [];
    $alwaysHide = ['created_at', 'updated_at', 'deleted_at', 'content'];
    $count = 0;
    foreach ($columns as $col => $type) {
        if (in_array($col, $skip)) continue;
        if (in_array($col, $alwaysHide) || $count >= 6) {
            $hide[] = $col;
        } else {
            $show[] = $col;
        }
        $count++;
    }
    return [$show, $hide];
}

function getFormFields(array $columns): array
{
    $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'verified_at', 'verified_by',
        'approved_by', 'approved_at', 'finished_at', 'closed_by', 'closed_at',
        'paid_at', 'callback_at', 'allocated_at', 'released_at', 'provisioned_at',
        'expired_at', 'sla_deadline', 'next_retry_at', 'promo_end_at',
        'last_error', 'retry_count'];
    $fields = [];
    foreach ($columns as $col => $type) {
        if (in_array($col, $skip)) continue;
        $fields[] = $col;
    }
    return $fields;
}

function colLabel(string $col): string
{
    static $labels = [
        'id' => 'ID', 'user_id' => '用户ID', 'order_id' => '订单ID',
        'order_item_id' => '订单项ID', 'product_id' => '产品ID',
        'category_id' => '分类ID', 'supplier_id' => '供应商ID',
        'sku_id' => 'SKU ID', 'region_id' => '区域ID', 'resource_id' => '资源ID',
        'host_machine_id' => '主机ID', 'ip_pool_id' => 'IP池ID',
        'disk_id' => '磁盘ID', 'zone_id' => 'Zone ID', 'channel_id' => '通道ID',
        'ticket_id' => '工单ID', 'parent_id' => '父级ID',
        'assigned_to' => '指派给', 'sender_id' => '发送者ID',
        'email' => '邮箱', 'phone' => '手机', 'language' => '语言',
        'currency' => '货币', 'timezone' => '时区', 'status' => '状态',
        'role' => '角色', 'fcm_token' => 'FCM Token', 'fcm_platform' => 'FCM平台',
        'name' => '名称', 'slug' => '标识', 'sort' => '排序', 'icon' => '图标',
        'type' => '类型', 'priority' => '优先级', 'title' => '标题',
        'content' => '内容', 'remark' => '备注', 'reason' => '原因',
        'amount' => '金额', 'price' => '价格', 'original_price' => '原价',
        'total' => '总计', 'subtotal' => '小计', 'discount' => '折扣',
        'tax' => '税费', 'exchange_rate' => '汇率', 'channel_fee' => '通道费',
        'balance' => '余额', 'frozen_balance' => '冻结余额',
        'stock' => '库存', 'quantity' => '数量', 'unit_price' => '单价',
        'total_price' => '总价', 'cycle' => '周期',
        'order_no' => '订单号', 'transaction_no' => '交易号', 'ticket_no' => '工单号',
        'sku_code' => 'SKU编码', 'code' => '编码',
        'ip_address' => 'IP地址', 'ip_start' => '起始IP', 'ip_end' => '结束IP',
        'gateway' => '网关', 'total_count' => '总数', 'used_count' => '已用数',
        'size_gb' => '大小(GB)', 'old_size_gb' => '原大小(GB)', 'new_size_gb' => '新大小(GB)',
        'disk_type' => '磁盘类型', 'storage_pool' => '存储池', 'device_path' => '设备路径',
        'vm_id' => 'VM ID', 'proxmox_node' => 'Proxmox节点',
        'data_center' => '数据中心', 'continent' => '大洲', 'country' => '国家',
        'city' => '城市', 'state' => '州/省', 'address' => '地址', 'postcode' => '邮编',
        'is_default' => '默认', 'is_visible' => '可见',
        'min_amount' => '最小金额', 'max_amount' => '最大金额',
        'tld' => 'TLD', 'registrar' => '注册商', 'retail_price' => '零售价',
        'promo_price' => '促销价', 'promo_end_at' => '促销截止',
        'domain_name' => '域名', 'value' => '值', 'ttl' => 'TTL',
        'period_start' => '期间开始', 'period_end' => '期间结束',
        'total_sales' => '总销售额', 'commission' => '佣金', 'payable' => '应付',
        'method' => '方式', 'account_info' => '账户信息',
        'settlement_method' => '结算方式', 'company_name' => '公司名称',
        'contact_name' => '联系人', 'contact_phone' => '联系电话',
        'contact_email' => '联系邮箱', 'id_type' => '证件类型',
        'real_name' => '真实姓名', 'reject_reason' => '拒绝原因',
        'front_image' => '正面照', 'back_image' => '背面照',
        'sender_type' => '发送者类型', 'category' => '分类',
        'template_code' => '模板编码', 'channels' => '渠道',
        'send_status' => '发送状态', 'channel' => '渠道',
        'rule_code' => '规则编码', 'severity' => '严重程度',
        'context' => '上下文', 'action' => '操作',
        'resource_type' => '资源类型', 'ip_address' => 'IP地址',
        'user_agent' => 'User Agent', 'old_values' => '旧值', 'new_values' => '新值',
        'url' => 'URL', 'rating' => '评分', 'specs' => '规格',
        'provider' => '提供商', 'product_type' => '产品类型',
        'operator' => '操作人', 'description' => '描述', 'body' => '正文',
        'paid_at' => '支付时间', 'callback_at' => '回调时间',
        'provisioned_at' => '交付时间', 'expired_at' => '过期时间',
        'closed_at' => '关闭时间', 'approved_at' => '审批时间',
        'sla_deadline' => 'SLA截止', 'allocated_at' => '分配时间',
        'released_at' => '释放时间', 'verified_at' => '验证时间',
        'finished_at' => '完成时间', 'promo_end_at' => '促销截止',
        'next_retry_at' => '下次重试', 'created_at' => '创建时间',
        'updated_at' => '更新时间', 'deleted_at' => '删除时间',
    ];
    return $labels[$col] ?? $col;
}

function colInputType(string $col, string $type): string
{
    if (in_array($col, ['status', 'type', 'role', 'category', 'priority', 'cycle',
        'language', 'currency', 'timezone', 'channel', 'method', 'settlement_method',
        'id_type', 'sender_type', 'severity', 'disk_type', 'provider', 'product_type',
        'gender', 'sex', 'action', 'resource_type', 'continent', 'country', 'fcm_platform',
        'send_status', 'storage_pool'])) {
        return 'text';
    }
    if (in_array($type, ['int', 'bigint', 'tinyint', 'smallint', 'mediumint', 'integer'])) {
        return 'number';
    }
    if (in_array($type, ['decimal', 'float', 'double'])) {
        return 'number';
    }
    if (in_array($type, ['datetime', 'date', 'timestamp'])) {
        return 'date';
    }
    if (in_array($type, ['text', 'mediumtext', 'longtext', 'json'])) {
        return 'textarea';
    }
    return 'text';
}

function fieldOptions(string $col): array
{
    static $options = [
        'status' => ['active' => '启用', 'inactive' => '禁用', 'pending' => '待处理',
            'draft' => '草稿', 'suspended' => '停用', 'deleted' => '已删除',
            'banned' => '封禁', 'open' => '打开', 'closed' => '关闭',
            'running' => '运行中', 'success' => '成功', 'failed' => '失败',
            'retryable' => '可重试', 'provisioning' => '交付中', 'completed' => '已完成',
            'creating' => '创建中', 'cancelled' => '已取消', 'refunding' => '退款中',
            'refunded' => '已退款', 'paid' => '已支付', 'triggered' => '已触发',
            'queued' => '队列中'],
        'type' => ['new' => '新购', 'renew' => '续费', 'upgrade' => '升级',
            'downgrade' => '降级', 'billing' => '账单', 'shipping' => '配送',
            'primary' => '主IP', 'secondary' => '附加IP'],
        'priority' => ['low' => '低', 'normal' => '普通', 'high' => '高', 'urgent' => '紧急'],
        'cycle' => ['hourly' => '按小时', 'daily' => '按天', 'monthly' => '按月',
            'quarterly' => '按季', 'yearly' => '按年'],
        'currency' => ['USD' => 'USD', 'EUR' => 'EUR', 'CNY' => 'CNY',
            'JPY' => 'JPY', 'GBP' => 'GBP', 'HKD' => 'HKD'],
        'language' => ['en-US' => 'English', 'zh-CN' => '简体中文',
            'ja-JP' => '日本語', 'ko-KR' => '한국어'],
        'timezone' => ['UTC' => 'UTC', 'Asia/Shanghai' => 'Asia/Shanghai',
            'Asia/Tokyo' => 'Asia/Tokyo', 'America/New_York' => 'America/New_York',
            'Europe/London' => 'Europe/London'],
        'role' => ['user' => '用户', 'admin' => '管理员', 'supplier' => '供应商'],
        'severity' => ['info' => '信息', 'warning' => '警告', 'critical' => '严重', 'emergency' => '紧急'],
        'channel' => ['in_app' => '站内信', 'email' => '邮件', 'sms' => '短信', 'push' => '推送'],
        'send_status' => ['queued' => '队列中', 'sent' => '已发送', 'failed' => '失败', 'dev-stub' => '开发桩'],
        'sender_type' => ['user' => '用户', 'admin' => '管理员', 'system' => '系统'],
        'disk_type' => ['ssd' => 'SSD', 'hdd' => 'HDD', 'nvme' => 'NVMe'],
        'method' => ['bank' => '银行转账', 'paypal' => 'PayPal', 'stripe' => 'Stripe',
            'alipay' => '支付宝', 'wechat' => '微信支付'],
        'settlement_method' => ['bank' => '银行转账', 'paypal' => 'PayPal'],
        'provider' => ['proxmox' => 'Proxmox', 'vmware' => 'VMware', 'hyperv' => 'Hyper-V'],
        'product_type' => ['vm' => '云服务器', 'disk' => '云磁盘', 'ip' => 'IP地址', 'domain' => '域名'],
        'action' => ['create' => '创建', 'renew' => '续费', 'upgrade' => '升级',
            'downgrade' => '降级', 'delete' => '删除'],
        'resource_type' => ['vm' => '云服务器', 'disk' => '云磁盘', 'ip' => 'IP地址', 'domain' => '域名'],
        'continent' => ['Asia' => '亚洲', 'Europe' => '欧洲', 'North America' => '北美洲',
            'South America' => '南美洲', 'Africa' => '非洲', 'Oceania' => '大洋洲'],
        'storage_pool' => ['local-lvm' => 'local-lvm', 'local-zfs' => 'local-zfs',
            'ceph' => 'Ceph', 'nfs' => 'NFS'],
        'id_type' => ['passport' => '护照', 'id_card' => '身份证', 'driver_license' => '驾驶证'],
        'fcm_platform' => ['android' => 'Android', 'ios' => 'iOS', 'web' => 'Web'],
    ];
    return $options[$col] ?? [];
}

foreach ($tables as $table => $columns) {
    $className = tableToClass($table);
    $viewKey = classToViewKey($className);
    $label = tableToLabel($table);
    $viewDir = $viewBaseDir . $viewKey . '/';
    @mkdir($viewDir, 0755, true);

    $searchFields = getSearchFields($columns);
    [$showCols, $hideCols] = getTableColumns($columns);
    $formFields = getFormFields($columns);
    $controllerPath = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));
    $isReadOnly = in_array($table, $readOnlyTables);

    // Build toolbar and row bar HTML based on read-only status
    if ($isReadOnly) {
        $toolbarHtml = <<<HTML
        <script type="text/html" id="table-toolbar">
            <span class="layui-font-16">{$label}列表（只读）</span>
        </script>

HTML;
        $rowBarHtml = ''; // No row actions for read-only tables
    } else {
        $toolbarHtml = <<<HTML
        <script type="text/html" id="table-toolbar">
            <button class="pear-btn pear-btn-primary pear-btn-md" lay-event="add" permission="app.admin.{$controllerPath}.insert">
                <i class="layui-icon layui-icon-add-1"></i>新增
            </button>
            <button class="pear-btn pear-btn-danger pear-btn-md" lay-event="batchRemove" permission="app.admin.{$controllerPath}.delete">
                <i class="layui-icon layui-icon-delete"></i>删除
            </button>
        </script>

HTML;
        $rowBarHtml = <<<HTML
        <script type="text/html" id="table-bar">
            <button class="pear-btn pear-btn-xs tool-btn" lay-event="edit" permission="app.admin.{$controllerPath}.update">编辑</button>
            <button class="pear-btn pear-btn-xs tool-btn" lay-event="remove" permission="app.admin.{$controllerPath}.delete">删除</button>
        </script>

HTML;
    }

    // ========================
    // index.html
    // ========================
    $searchHtml = '';
    foreach ($searchFields as $col) {
        $colType = $columns[$col];
        $inputType = colInputType($col, $colType);
        $colLabel = colLabel($col);
        $searchHtml .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <input type="{$inputType}" name="{$col}" value="" class="layui-input">
                        </div>
                    </div>
HTML;
    }

    $showColNames = array_merge($showCols, $hideCols);
    $allDisplayCols = $showColNames;
    if (in_array('id', $allDisplayCols)) {
        $allDisplayCols = array_unique(array_merge(['id'], $allDisplayCols));
    }

    $indexHtml = <<<HTML
<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="utf-8">
        <title>{$label}管理</title>
        <link rel="stylesheet" href="/app/admin/component/pear/css/pear.css" />
        <link rel="stylesheet" href="/app/admin/admin/css/reset.css" />
    </head>
    <body class="pear-container">

        <div class="layui-card">
            <div class="layui-card-body">
                <form class="layui-form top-search-from">
                    {$searchHtml}
                    <div class="layui-form-item layui-inline">
                        <label class="layui-form-label"></label>
                        <button class="pear-btn pear-btn-md pear-btn-primary" lay-submit lay-filter="table-query">
                            <i class="layui-icon layui-icon-search"></i>查询
                        </button>
                        <button type="reset" class="pear-btn pear-btn-md" lay-submit lay-filter="table-reset">
                            <i class="layui-icon layui-icon-refresh"></i>重置
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="layui-card">
            <div class="layui-card-body">
                <table id="data-table" lay-filter="data-table"></table>
            </div>
        </div>

{$toolbarHtml}
        {$rowBarHtml}
        <script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
        <script src="/app/admin/component/pear/pear.js"></script>
        <script src="/app/admin/admin/js/permission.js"></script>
        <script src="/app/admin/admin/js/common.js"></script>
        <script>
            const PRIMARY_KEY = "id";
            const SELECT_API = "/app/admin/{$controllerPath}/select";
            const UPDATE_API = "/app/admin/{$controllerPath}/update";
            const DELETE_API = "/app/admin/{$controllerPath}/delete";
            const INSERT_URL = "/app/admin/{$controllerPath}/insert";
            const UPDATE_URL = "/app/admin/{$controllerPath}/update";

            layui.use(["table", "form", "common", "popup", "util"], function() {
                let table = layui.table;
                let form = layui.form;
                let $ = layui.$;
                let common = layui.common;
                let util = layui.util;

                let cols = [{
                    type: "checkbox"
                },{
                    title: "ID",
                    field: "id",
                    sort: true

HTML;
    // Add remaining columns (loop prepends "},{" to separate)
    $colIdx = 0;
    foreach ($allDisplayCols as $col) {
        if ($col === 'id') continue;
        $hidden = in_array($col, $hideCols) ? "true" : "false";
        $sortable = ($colIdx === 0 && !in_array($col, $hideCols)) ? "true" : "false";
        $colLabel = colLabel($col);
        $sortStr = $sortable === 'true' ? ",\n                        sort: true" : "";
        $hideStr = $hidden === 'true' ? ",\n                        hide: true" : "";
        $indexHtml .= "                },{\n                    title: \"{$colLabel}\",\n                    field: \"{$col}\"{$sortStr}{$hideStr}\n";
        $colIdx++;
    }

    if (!$isReadOnly) {
        $indexHtml .= <<<HTML
                },{
                    title: "操作",
                    toolbar: "#table-bar",
                    align: "center",
                    fixed: "right",
                    width: 130,
HTML;
    }
    $indexHtml .= <<<HTML
                }];

                function render() {
                    table.render({
                        elem: "#data-table",
                        url: SELECT_API,
                        page: true,
                        cols: [cols],
                        skin: "line",
                        size: "lg",
                        toolbar: "#table-toolbar",
                        autoSort: false,
                        defaultToolbar: [{
                            title: "刷新",
                            layEvent: "refresh",
                            icon: "layui-icon-refresh",
                        }, "filter", "print", "exports"],
                        done: function () {
                            layer.photos({photos: 'div[lay-id="data-table"]', anim: 5});
                        }
                    });
                }
                render();

                table.on("tool(data-table)", function(obj) {
                    if (obj.event === "remove") { remove(obj); }
                    else if (obj.event === "edit") { edit(obj); }
                });

                table.on("toolbar(data-table)", function(obj) {
                    if (obj.event === "add") { add(); }
                    else if (obj.event === "refresh") { refreshTable(); }
                    else if (obj.event === "batchRemove") { batchRemove(obj); }
                });

                form.on("submit(table-query)", function(data) {
                    table.reload("data-table", { page: { curr: 1 }, where: data.field });
                    return false;
                });

                form.on("submit(table-reset)", function(data) {
                    table.reload("data-table", { where: [] });
                });

                table.on("sort(data-table)", function(obj){
                    table.reload("data-table", {
                        initSort: obj,
                        scrollPos: "fixed",
                        where: { field: obj.field, order: obj.type }
                    });
                });

                let add = function() {
                    layer.open({
                        type: 2,
                        title: "新增",
                        shade: 0.1,
                        area: [common.isModile()?"100%":"600px", common.isModile()?"100%":"500px"],
                        content: INSERT_URL
                    });
                }

                let edit = function(obj) {
                    let value = obj.data[PRIMARY_KEY];
                    layer.open({
                        type: 2,
                        title: "修改",
                        shade: 0.1,
                        area: [common.isModile()?"100%":"600px", common.isModile()?"100%":"500px"],
                        content: UPDATE_URL + "?" + PRIMARY_KEY + "=" + value
                    });
                }

                let remove = function(obj) { return doRemove(obj.data[PRIMARY_KEY]); }

                let batchRemove = function(obj) {
                    let checkIds = common.checkField(obj, PRIMARY_KEY);
                    if (checkIds === "") { layui.popup.warning("未选中数据"); return false; }
                    doRemove(checkIds.split(","));
                }

                let doRemove = function (ids) {
                    let data = {};
                    data[PRIMARY_KEY] = ids;
                    layer.confirm("确定删除?", { icon: 3, title: "提示" }, function(index) {
                        layer.close(index);
                        let loading = layer.load();
                        $.ajax({
                            url: DELETE_API, data: data, dataType: "json", type: "post",
                            success: function(res) {
                                layer.close(loading);
                                if (res.code) { return layui.popup.failure(res.msg); }
                                return layui.popup.success("操作成功", refreshTable);
                            }
                        });
                    });
                }

                window.refreshTable = function() {
                    table.reloadData("data-table", {
                        scrollPos: "fixed",
                        done: function (res, curr) {
                            if (curr > 1 && res.data && !res.data.length) {
                                curr = curr - 1;
                                table.reloadData("data-table", { page: { curr: curr } });
                            }
                        }
                    });
                }
            });
        </script>
    </body>
</html>
HTML;

    file_put_contents($viewDir . 'index.html', $indexHtml);

    // ========================
    // insert.html
    // ========================
    $formInputs = '';
    foreach ($formFields as $col) {
        $colType = $columns[$col];
        $inputType = colInputType($col, $colType);
        $colLabel = colLabel($col);
        $options = fieldOptions($col);
        if (!empty($options)) {
            $selectOpts = '';
            foreach ($options as $val => $label) {
                $selectOpts .= "                                <option value=\"{$val}\">{$label}</option>\n";
            }
            $formInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <select name="{$col}" class="layui-input">
                                <option value="">请选择</option>
{$selectOpts}                            </select>
                        </div>
                    </div>
HTML;
        } elseif ($inputType === 'textarea') {
            $formInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <textarea name="{$col}" class="layui-textarea"></textarea>
                        </div>
                    </div>
HTML;
        } elseif ($inputType === 'date') {
            $formInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <input type="text" name="{$col}" id="{$col}" autocomplete="off" class="layui-input">
                        </div>
                    </div>
HTML;
        } else {
            $inputVal = $inputType === 'number' ? '0' : '';
            $formInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <input type="{$inputType}" name="{$col}" value="{$inputVal}" class="layui-input">
                        </div>
                    </div>
HTML;
        }
    }

    $dateInitScript = '';
    foreach ($formFields as $col) {
        $colType = $columns[$col];
        $inputType = colInputType($col, $colType);
        if ($inputType === 'date') {
            $dateInitScript .= <<<JS

            layui.use(["laydate"], function() {
                layui.laydate.render({ elem: "#{$col}", type: "datetime" });
            });
JS;
        }
    }

    $insertHtml = <<<HTML
<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="UTF-8">
        <title>新增{$label}</title>
        <link rel="stylesheet" href="/app/admin/component/pear/css/pear.css" />
        <link rel="stylesheet" href="/app/admin/admin/css/reset.css" />
    </head>
    <body>

        <form class="layui-form" action="">
            <div class="mainBox">
                <div class="main-container mr-5">
                    {$formInputs}
                </div>
            </div>
            <div class="bottom">
                <div class="button-container">
                    <button type="submit" class="pear-btn pear-btn-primary pear-btn-md" lay-submit="" lay-filter="save">
                        提交
                    </button>
                    <button type="reset" class="pear-btn pear-btn-md">
                        重置
                    </button>
                </div>
            </div>
        </form>

        <script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
        <script src="/app/admin/component/pear/pear.js"></script>
        <script src="/app/admin/admin/js/permission.js"></script>
        <script>
            const INSERT_API = "/app/admin/{$controllerPath}/insert";
            {$dateInitScript}
            layui.use(["form", "popup"], function () {
                layui.form.on("submit(save)", function (data) {
                    layui.$.ajax({
                        url: INSERT_API,
                        type: "POST",
                        dateType: "json",
                        data: data.field,
                        success: function (res) {
                            if (res.code) { return layui.popup.failure(res.msg); }
                            return layui.popup.success("操作成功", function () {
                                parent.refreshTable();
                                parent.layer.close(parent.layer.getFrameIndex(window.name));
                            });
                        }
                    });
                    return false;
                });
            });
        </script>
    </body>
</html>
HTML;

    file_put_contents($viewDir . 'insert.html', $insertHtml);

    // ========================
    // update.html
    // ========================
    $updateFormInputs = '';
    foreach ($formFields as $col) {
        $colType = $columns[$col];
        $inputType = colInputType($col, $colType);
        $colLabel = colLabel($col);
        $options = fieldOptions($col);
        if (!empty($options)) {
            $selectOpts = '';
            foreach ($options as $val => $label) {
                $selectOpts .= "                                <option value=\"{$val}\">{$label}</option>\n";
            }
            $updateFormInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <select name="{$col}" class="layui-input">
                                <option value="">请选择</option>
{$selectOpts}                            </select>
                        </div>
                    </div>
HTML;
        } elseif ($inputType === 'textarea') {
            $updateFormInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <textarea name="{$col}" class="layui-textarea"></textarea>
                        </div>
                    </div>
HTML;
        } elseif ($inputType === 'date') {
            $updateFormInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <input type="text" name="{$col}" id="{$col}" autocomplete="off" class="layui-input">
                        </div>
                    </div>
HTML;
        } else {
            $updateFormInputs .= <<<HTML

                    <div class="layui-form-item">
                        <label class="layui-form-label">{$colLabel}</label>
                        <div class="layui-input-block">
                            <input type="{$inputType}" name="{$col}" value="" class="layui-input">
                        </div>
                    </div>
HTML;
        }
    }

    $updateDateInitScript = '';
    foreach ($formFields as $col) {
        $colType = $columns[$col];
        $inputType = colInputType($col, $colType);
        if ($inputType === 'date') {
            $updateDateInitScript .= <<<JS

            layui.use(["laydate"], function() {
                layui.laydate.render({ elem: "#{$col}", type: "datetime" });
            });
JS;
        }
    }

    $updateHtml = <<<HTML
<!DOCTYPE html>
<html lang="zh-cn">
    <head>
        <meta charset="UTF-8">
        <title>更新{$label}</title>
        <link rel="stylesheet" href="/app/admin/component/pear/css/pear.css" />
        <link rel="stylesheet" href="/app/admin/admin/css/reset.css" />
    </head>
    <body>

        <form class="layui-form">
            <div class="mainBox">
                <div class="main-container mr-5">
                    {$updateFormInputs}
                </div>
            </div>
            <div class="bottom">
                <div class="button-container">
                    <button type="submit" class="pear-btn pear-btn-primary pear-btn-md" lay-submit="" lay-filter="save">
                        提交
                    </button>
                    <button type="reset" class="pear-btn pear-btn-md">
                        重置
                    </button>
                </div>
            </div>
        </form>

        <script src="/app/admin/component/layui/layui.js?v=2.8.12"></script>
        <script src="/app/admin/component/pear/pear.js"></script>
        <script src="/app/admin/admin/js/permission.js"></script>
        <script>
            const PRIMARY_KEY = "id";
            const SELECT_API = "/app/admin/{$controllerPath}/select" + location.search;
            const UPDATE_API = "/app/admin/{$controllerPath}/update";

            layui.use(["form", "util", "popup"], function () {
                let $ = layui.$;
                $.ajax({
                    url: SELECT_API,
                    dataType: "json",
                    success: function (res) {
                        layui.each(res.data[0], function (key, value) {
                            let obj = $('*[name="'+key+'"]');
                            if (typeof obj[0] === "undefined" || !obj[0].nodeName) return;
                            let tag = obj[0].nodeName.toLowerCase();
                            if (tag === "textarea" || tag === "select") {
                                obj.val(layui.util.escape(value));
                            } else {
                                obj.attr("value", value);
                            }
                        });
                        {$updateDateInitScript}
                        if (res.code) { layui.popup.failure(res.msg); }
                    }
                });
            });

            layui.use(["form", "popup"], function () {
                layui.form.on("submit(save)", function (data) {
                    data.field[PRIMARY_KEY] = layui.url().search[PRIMARY_KEY];
                    layui.$.ajax({
                        url: UPDATE_API,
                        type: "POST",
                        dateType: "json",
                        data: data.field,
                        success: function (res) {
                            if (res.code) { return layui.popup.failure(res.msg); }
                            return layui.popup.success("操作成功", function () {
                                parent.refreshTable();
                                parent.layer.close(parent.layer.getFrameIndex(window.name));
                            });
                        }
                    });
                    return false;
                });
            });
        </script>
    </body>
</html>
HTML;

    file_put_contents($viewDir . 'update.html', $updateHtml);
    echo "Views: {$viewKey}/*.html\n";
}

// ============================================================
// Generate Menu Config
// ============================================================
$menuFile = __DIR__ . '/config/business_menu.php';
$menuCode = "<?php\n\n/**\n * Auto-generated business management menu.\n * Include this in config/menu.php\n */\n\nreturn [\n";
$menuCode .= "    [\n        'title' => '业务管理',\n        'key' => 'business',\n        'icon' => 'layui-icon-component',\n        'weight' => 750,\n        'type' => 0,\n        'children' => [\n";

// Define menu groups
$menuGroups = [
    '产品管理' => [
        'erik_product_categories' => '产品分类',
        'erik_products' => '产品列表',
        'erik_product_skus' => 'SKU管理',
        'erik_product_regions' => '产品区域定价',
        'erik_product_images' => '产品图片',
        'erik_product_reviews' => '产品评价',
        'erik_regions' => '区域管理',
    ],
    '订单管理' => [
        'erik_orders' => '订单列表',
        'erik_order_items' => '订单项',
        'erik_order_timeline' => '订单时间线',
        'erik_refunds' => '退款管理',
        'erik_carts' => '购物车',
    ],
    '资源管理' => [
        'erik_resources' => '云服务器',
        'erik_disks' => '云磁盘',
        'erik_disk_resizes' => '磁盘扩容',
        'erik_ip_pools' => 'IP池',
        'erik_ip_allocations' => 'IP分配',
        'erik_host_machines' => '物理主机',
        'erik_provision_tasks' => '交付任务',
    ],
    '域名管理' => [
        'erik_domain_tlds' => 'TLD管理',
        'erik_dns_zones' => 'DNS区域',
        'erik_dns_records' => 'DNS记录',
    ],
    '供应商管理' => [
        'erik_suppliers' => '供应商列表',
        'erik_supplier_settlements' => '结算记录',
        'erik_supplier_withdraws' => '提现管理',
    ],
    '支付管理' => [
        'erik_payment_channels' => '支付通道',
        'erik_payment_transactions' => '交易记录',
    ],
    '用户管理' => [
        'erik_user_kyc' => 'KYC审核',
        'erik_user_balances' => '用户余额',
        'erik_user_addresses' => '用户地址',
        'erik_refresh_tokens' => '刷新令牌',
    ],
    '工单管理' => [
        'erik_tickets' => '工单列表',
        'erik_ticket_messages' => '工单回复',
    ],
    '系统监控' => [
        'erik_notifications' => '通知记录',
        'erik_notification_templates' => '通知模板',
        'erik_alerts' => '告警记录',
        'erik_audit_logs' => '审计日志',
    ],
];

$weight = 1000;
foreach ($menuGroups as $groupName => $items) {
    $groupKey = strtolower(preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9]/u', '_', $groupName));
    $menuCode .= "            [\n";
    $menuCode .= "                'title' => '{$groupName}',\n";
    $menuCode .= "                'key' => 'business_{$groupKey}',\n";
    $menuCode .= "                'type' => 0,\n";
    $menuCode .= "                'icon' => 'layui-icon-file',\n";
    $menuCode .= "                'weight' => {$weight},\n";
    $menuCode .= "                'children' => [\n";

    $subWeight = 1000;
    foreach ($items as $tableName => $title) {
        if (!isset($tables[$tableName])) continue;
        $className = tableToClass($tableName);
        $controllerPath = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));
        $controllerClass = "app\\\\controller\\\\{$className}Controller";
        $menuCode .= "                    [\n";
        $menuCode .= "                        'title' => '{$title}',\n";
        $menuCode .= "                        'key' => '{$controllerClass}',\n";
        $menuCode .= "                        'href' => '/app/admin/{$controllerPath}/index',\n";
        $menuCode .= "                        'type' => 1,\n";
        $menuCode .= "                        'weight' => {$subWeight},\n";
        $menuCode .= "                    ],\n";
        $subWeight -= 10;
    }

    $menuCode .= "                ]\n";
    $menuCode .= "            ],\n";
    $weight -= 10;
}

$menuCode .= "        ]\n    ]\n];\n";

file_put_contents($menuFile, $menuCode);
echo "\nMenu: config/business_menu.php\n";
echo "\nDone. Generated " . count($tables) . " models, controllers, view sets, and menu config.\n";
echo "Next: Integrate business_menu.php into config/menu.php\n";
