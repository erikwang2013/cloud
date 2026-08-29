# API 概览

> 完整接口参考（200+ 端点，含请求/响应示例与错误码）：[API 接口文档](api-reference.md)
> 在线调试：[service API 文档](http://localhost:8787/apidoc) · [admin API 文档](http://localhost:8788/apidoc)

## 公开接口

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/health` | 健康检查 |
| POST | `/api/auth/register` | 用户注册（请求体需 AES-256-GCM 加密） |
| POST | `/api/auth/login` | 用户登录（请求体需 AES-256-GCM 加密） |
| POST | `/api/auth/refresh` | 刷新 Token（请求体需 AES-256-GCM 加密） |
| POST | `/api/captcha/create` | 生成点击验证码（登录/注册前获取） |
| GET | `/api/products` | 产品列表（支持分类/区域/关键词筛选） |
| GET | `/api/products/{id}` | 产品详情（id 为 hashid 字符串） |
| GET | `/api/regions` | 可用区域 |
| GET | `/api/domain/check/{domain}/{tld}` | 域名可用性查询 |
| GET | `/api/domain/tlds` | 可注册后缀列表 |
| POST | `/api/payments/webhook/stripe` | Stripe 回调（签名校验，无需加密） |

## 认证接口（需 Bearer Token）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/user/profile` | 个人信息 |
| PUT | `/api/user/profile` | 更新信息 |
| POST | `/api/user/kyc` | 提交实名认证 |
| GET | `/api/user/balance` | 账户余额 |
| GET/POST | `/api/cart` | 购物车 |
| POST/GET | `/api/orders` | 订单 |
| GET | `/api/orders/{id}/payment-methods` | 可用支付方式 |
| POST | `/api/orders/{id}/pay` | 发起支付 |
| GET/POST | `/api/resources` | 我的资源 |
| GET | `/api/resources/{id}/status` | 资源状态 |
| GET | `/api/resources/{id}/console` | VNC 控制台链接 |
| GET/POST | `/api/cdn/domains` | CDN 域名列表 / 创建（cloudflare \| cloudfront \| aliyun \| tencent） |
| GET/DELETE | `/api/cdn/domains/{id}` | CDN 域名详情 / 删除 |
| POST | `/api/cdn/domains/{id}/purge` | 清除缓存（幂等，最多 100 个 URL） |
| GET/POST | `/api/tickets` | 工单 |
| POST | `/api/tickets/{id}/reply` | 工单回复 |
| GET/POST | `/api/dns/{domain}` | DNS 管理 |
| POST | `/api/supplier/apply` | 供应商申请 |
| GET | `/api/supplier/settlements` | 供应商结算记录 |
| POST | `/api/supplier/withdraw` | 供应商提现 |

> **说明：** 所有 API 请求需携带 `X-Api-Version: v1` 请求头（缺失默认 `v1`，由 `VersionMiddleware` 校验）。认证接口和管理员接口的请求/响应均经过 `EncryptionMiddleware` 处理。客户端设置 `X-Encrypted: 1` 请求头，请求体格式为 `{"payload": "<base64(AES-256-GCM)>"}`，响应体同样加密后包裹于 `payload` 字段。所有整数 ID 在 API 响应中自动转为 12 位 Hashid 字符串，请求中的 Hashid 字符串由 `HashidRequestMiddleware` 自动解码回整数 ID。

## 管理员接口

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/admin/api/dashboard` | 运营仪表盘 |
| GET/PUT | `/admin/api/users` | 用户管理 |
| GET/POST | `/admin/api/kyc` | KYC 审核 |
| GET/POST/PUT/DELETE | `/admin/api/products` | 产品管理 |
| POST | `/admin/api/products/{productId}/skus` | 创建 SKU |
| POST | `/admin/api/skus/{skuId}/region-price` | 设置区域价格 |
| GET/POST | `/admin/api/orders` | 订单管理（含退款） |
| GET | `/admin/api/orders/export` | 订单导出 (.xlsx) |
| GET | `/admin/api/users/export` | 用户导出 (.xlsx) |
| GET | `/admin/api/suppliers/export` | 供应商导出 (.xlsx) |
| GET/PUT | `/admin/api/payments/*` | 支付通道 / 交易 / 对账 |
| GET/POST | `/admin/api/provisioning/*` | 交付任务 / 主机管理 |
| GET/PUT | `/admin/api/cdn/domains` | CDN 域名管理（套餐变更） |
| GET/POST/PUT/DELETE | `/admin/api/providers` | 服务商账号凭据管理（CDN/交付共用，Encryptable 加密） |
| GET/POST | `/admin/api/suppliers/*` | 供应商审批 / 结算 / 提现 |
| GET/POST | `/admin/api/tickets` | 工单分配 / 关闭 |
| GET | `/admin/api/reports/*` | 营收 / 区域 / 供应商报表 |
| GET | `/admin/api/monitor/*` | 监控面板 / 资源指标 |
| GET | `/admin/api/audit-logs` | 审计日志 |
| PUT | `/admin/api/system/config` | 系统配置 |
