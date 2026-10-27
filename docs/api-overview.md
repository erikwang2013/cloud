# API 概览

> 完整接口参考（含请求/响应示例与错误码）：[API 接口文档](api-reference.md)
> 在线调试：[service API 文档](http://localhost:8787/apidoc)

## 公开接口

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/` | 站点落地页（HTML，非 JSON 接口） |
| GET | `/health` | 健康检查 |
| POST | `/api/v1/auth/register` | 用户注册（请求体需 AES-256-GCM 加密） |
| POST | `/api/v1/auth/login` | 用户登录（请求体需 AES-256-GCM 加密） |
| POST | `/api/v1/auth/refresh` | 刷新 Token（请求体需 AES-256-GCM 加密） |
| POST | `/api/v1/captcha/create` | 生成点击验证码（登录/注册前获取） |
| GET | `/api/v1/products` | 产品列表（支持分类/区域/关键词筛选） |
| GET | `/api/v1/products/{id}` | 产品详情（id 为 hashid 字符串） |
| GET | `/api/v1/regions` | 可用区域 |
| GET | `/api/v1/domain/check/{domain}/{tld}` | 域名可用性查询 |
| GET | `/api/v1/domain/tlds` | 可注册后缀列表 |
| POST | `/api/v1/payments/webhook/stripe` | Stripe 回调（签名校验，无需加密） |

## 认证接口（需 Bearer Token）

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/user/profile` | 个人信息 |
| PUT | `/api/v1/user/profile` | 更新信息 |
| POST | `/api/v1/user/kyc` | 提交实名认证 |
| GET | `/api/v1/user/balance` | 账户余额 |
| GET/POST | `/api/v1/cart` | 购物车 |
| POST/GET | `/api/v1/orders` | 订单 |
| GET | `/api/v1/orders/{id}/payment-methods` | 可用支付方式 |
| POST | `/api/v1/orders/{id}/pay` | 发起支付 |
| GET/POST | `/api/v1/resources` | 我的资源 |
| GET | `/api/v1/resources/{id}/status` | 资源状态 |
| GET | `/api/v1/resources/{id}/console` | VNC 控制台链接 |
| GET/POST | `/api/v1/tickets` | 工单 |
| POST | `/api/v1/tickets/{id}/reply` | 工单回复 |
| GET/POST | `/api/v1/dns/{domain}` | DNS 管理 |
| POST | `/api/v1/supplier/apply` | 供应商申请 |
| GET | `/api/v1/supplier/settlements` | 供应商结算记录 |
| POST | `/api/v1/supplier/withdraw` | 供应商提现 |

> **说明：** API 版本位于 URL 路径中（如 `/api/v1/...`）。认证接口的请求/响应均经过 `EncryptionMiddleware` 处理。客户端设置 `X-Encrypted: 1` 请求头，请求体格式为 `{"payload": "<base64(AES-256-GCM)>"}`，响应体同样加密后包裹于 `payload` 字段。所有整数 ID 在 API 响应中自动转为 12 位 Hashid 字符串，请求中的 Hashid 字符串由 `HashidRequestMiddleware` 自动解码回整数 ID。
