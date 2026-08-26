# 2026-08-26 service 缺陷修复报告（A/C/F）

## 结论

- 3 个缺陷全部修复并端到端复测通过（9/9 PASS）
- PHPUnit 全量回归：672 tests / 1632 assertions / 15 skipped / 0 failures
- 未触碰 .env、app/grpc/Generated、数据库 schema；未新增 composer 依赖

## 缺陷 A：encryptable 密钥未 base64 解码 → 注册/登录/刷新/地址 全部 500

### 根因（三层叠加）

1. `config/encryptable.php` 把 `ENCRYPTION_KEY`（base64，解码后 16 字节，cipher=aes-128-ecb）原文当密钥传，密钥长度校验抛 `MissingEncryptionKeyException`。
2. 运行时实际读取的是 `config/plugin/erikwang2013/encryptable/app.php`（只有 `enable`），该插件配置里根本没有 key。
3. webman 无全局 `app()` helper，`Encryption::doResolve()` 走不到容器路径，回退到 `EnvEncryptableConfig`（读原始 env base64 串，不解码）——即使插件配置修好也仍 500。

### 修复

| 文件 | 改动 |
|------|------|
| `service/config/encryptable.php` | `'key' => base64_decode(getenv('ENCRYPTION_KEY'), true) ?: ''`（legacy 路径，一并修正） |
| `service/config/plugin/erikwang2013/encryptable/app.php` | 补全 `key`（base64 解码）/ `cipher` / `previous_keys` |
| `service/support/bootstrap.php` | `Encryption::setFallbackConfig(new WebmanPluginEncryptableConfig())`，让运行时走插件配置（密钥已解码） |

### 链路上发现的同源 bug（一并修复）

加密修复生效后，注册/登录/刷新开始 500 之外的失败：

- **登录 401**：`User::where('email', $login)->orWhere('phone', $login)` 明文查询永远匹配不到加密列。修复：`where('email', Encryption::php()->encrypt($login))`（加密确定性，密文相等即可命中）。
- **刷新 401 "Device mismatch"**：两层问题——
  - `RefreshToken::where('token_hash', hash(...))` 明文查询同样不命中，改为 `encrypt(hash(...))`；
  - 注册路径从不记录设备指纹（`AuthService::register()` 内部 `issueTokens(..., '')`），而刷新时校验指纹 → 注册后刷新必失败。修复：`AuthController::register` 把 `deviceFingerprint($request)` 传入，`AuthService::register` 增加 `$deviceFingerprint` 参数。
- **注册邮箱/手机唯一性校验**：`User::where('email', ...)->exists()` 同 bug，改为加密值查询（`recordFailedLogin` 一并修正）。

## 缺陷 C：Searchable 模型无 ES 客户端 → 改资料/建单 500

### 决策：webman-scout driver 改 `database`（而非 `null`）

`config/plugin/erikwang2013/webman-scout/app.php`：`'driver' => 'elasticsearch' → 'database'`。

理由：elasticsearch/elasticsearch 客户端未安装，elasticsearch 驱动在模型保存时抛异常；`database` 引擎写入为 no-op、搜索走 SQL LIKE（产品搜索保留可用），`null` 引擎的 `search()` 静默返回空数组、会吞掉产品关键词搜索结果。软删除配置保持默认。

## 缺陷 F：dns_rebinding 检测器 403 掉 Host=127.0.0.1 本机请求

### 决策：dns_rebinding mode 改 `log`（而非 whitelist_ips）

`config/plugin/erikwang2013/security-php/app.php`：`dns_rebinding.mode = 'block' → 'log'`。

理由：`whitelist_ips` 按客户端 IP 跳过**全部**检测器——本环境所有流量都经 nginx 转发、客户端 IP 恒为回环，等于关掉全部 31 个检测器。本机直连（Host=127.0.0.1/localhost）是开发/测试常态，改为 log 只放行该检测器，其余 30 个保持 block。

## 额外发现：user_addresses.phone VARCHAR(20) 装不下加密密文

加密生效后地址新增 500（`SQLSTATE[22001] Data too long for column 'phone'`）。约束"不改数据库"，采用代码侧修复：

- `service/app/user/model/UserAddress.php`：`phone` 移出 Encryptable casts（表内 0 行，无存量数据迁移风险）。`address` 保持加密（VARCHAR(500) 放得下）。

**权衡与后续**：phone 为 PII，现在明文落库。若要恢复落盘加密，需将 `user_addresses.phone` 与 `users.phone`（同为 VARCHAR(20) + Encryptable，手机号注册同样会 500）扩列至 VARCHAR(255) —— 需要一次 schema migration，超出本次"不改数据库"约束，建议单独立项。

## 评审跟进：cipher 确定性守卫（reviewer blocking 已消解）

reviewer 指出：按密文等值查询依赖确定性加密（ECB 无随机 IV），而 `.env.example` 建议 aes-256-cbc（随机 IV）——新环境照示例部署会"启动成功但登录/刷新/唯一性校验全部永不命中"，静默不可登录。

修复（fail-fast 守卫，防静默故障）：

- `service/support/bootstrap.php`：encryptable 配置接线后加守卫——`PHPEncrypter(WebmanPluginEncryptableConfig)->cipher()` 非 `aes-128-ecb`/`aes-256-ecb` 时启动即抛 `RuntimeException`，明确"确定性查询模式仅支持 ECB，换 cipher 须先重加密迁移"。
- `service/.env.example`：加密节注释补充警告（CBC/GCM 会启动即抛错；确定性查询仅 ECB）。

验证：当前 .env（aes-128-ecb）守卫通过；服务重启后 E2E 9/9 PASS；phpunit 672/1632/15 skipped/0 failures。

## 环境事故（非代码，需环境侧处理）

会话中途 `/usr/local/php/conf.d/002-imagick.ini`（root 属主，mtime 2026-08-26 23:31）被创建，其加载的 imagick.so 在 libgomp 构造函数中崩溃 → **所有带 ini 的 php CLI 调用段错误**（phpunit、start.php、php -l 全挂；gdb 证实 dlopen imagick.so 即 SIGSEGV，OMP_NUM_THREADS=1 无效）。无 root 权限无法删除该文件，本会话用 `PHP_INI_SCAN_DIR=/tmp/confd`（扫描目录副本，剔除 imagick）规避，服务与 phpunit 均以此方式运行。

建议环境侧：删除或注释 `/usr/local/php/conf.d/002-imagick.ini`（imagick.so 本身损坏），并排查是谁在会话中创建了该文件。

## 变更文件清单（均属 service/）

- `config/encryptable.php`
- `config/plugin/erikwang2013/encryptable/app.php`
- `config/plugin/erikwang2013/webman-scout/app.php`
- `config/plugin/erikwang2013/security-php/app.php`
- `support/bootstrap.php`（含 cipher 确定性守卫）
- `.env.example`（仅注释，未动 .env 值）
- `app/user/service/AuthService.php`
- `app/user/controller/AuthController.php`
- `app/user/model/UserAddress.php`

## 验证记录

- E2E（`/tmp/verify_chain.php`，临时脚本不入库）：F（Host=127.0.0.1 不 403）、注册→登录→刷新→地址新增、改资料 9/9 PASS。
- `vendor/bin/phpunit`：672 tests / 1632 assertions / 15 skipped / 0 failures。
