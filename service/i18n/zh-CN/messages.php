<?php
return [
    // ── 通用 ──
    'ok'                                                => '操作成功',
    'Unauthorized'                                      => '未授权访问',
    'Forbidden'                                         => '禁止访问',
    'Forbidden: admin access required'                  => '禁止访问：需要管理员权限',
    'Forbidden: insufficient permissions'               => '禁止访问：权限不足',
    'Too Many Requests'                                 => '请求过于频繁，请稍后再试',
    'Request blocked by WAF'                            => '请求已被安全防火墙拦截',
    'Request body too large'                            => '请求体过大（最大 10MB）',
    'URI too long'                                      => '请求地址过长',
    'Unsupported Content-Type'                          => '不支持的 Content-Type',
    'Authentication required'                           => '请先登录',
    'Password confirmation required for this operation' => '此操作需要密码二次确认',
    'Password verification failed'                      => '密码验证失败',
    'Too many confirmation attempts, try again later'   => '密码确认失败次数过多，请 15 分钟后再试',

    // ── 认证 ──
    'auth.login_success'                                => '登录成功',
    'auth.login_failed'                                 => '邮箱或密码错误',
    'auth.register_success'                             => '注册成功',
    'auth.token_expired'                                => '令牌已过期，请重新登录',
    'Login and password required'                       => '请输入邮箱/手机号和密码',
    'Email or phone required, and password required'    => '请填写邮箱或手机号，并设置密码',
    'Password must be at least 8 characters'            => '密码长度至少为 8 个字符',
    'Email required'                                    => '请输入邮箱',
    'Phone number required'                             => '请输入手机号',
    'Invalid email format'                              => '邮箱格式不正确',
    'Invalid credentials'                               => '邮箱或密码错误',
    'Account temporarily locked'                        => '账户已被临时锁定，请 15 分钟后再试',
    'Invalid or expired reset code'                     => '验证码无效或已过期',
    'Password has been reset successfully'              => '密码重置成功',
    'If the email exists, a reset code has been sent'   => '如果邮箱存在，重置验证码已发送',
    'Email, code, and password are required'            => '请填写邮箱、验证码和新密码',
    'Email verified successfully'                       => '邮箱验证成功',
    'Email already verified'                            => '邮箱已验证',
    'Invalid or expired verification token'             => '验证链接无效或已过期',
    'Verification token required'                       => '缺少验证令牌',
    'Verification code sent'                            => '验证码已发送',
    'Verification email sent'                           => '验证邮件已发送',
    'Please wait before requesting another code'        => '请等待后再重新获取验证码',
    'Too many SMS requests'                             => '短信请求过于频繁',
    'Session revoked'                                   => '会话已撤销',
    'Account deleted. Data will be retained per our retention policy.' => '账户已注销，数据将按保留政策处理',
    'Password verification required'                    => '需要密码验证',
    'Password verification required to disable 2FA'     => '禁用两步验证需要密码确认',

    // ── OAuth ──
    'Authorization code required'                       => '缺少授权码',
    'Invalid state'                                     => '状态参数无效',
    'Failed to obtain access token'                     => '获取访问令牌失败',
    'Failed to obtain ID token'                         => '获取身份令牌失败',
    'Email not provided by Apple. User may need to re-authorize.' => 'Apple 未提供邮箱，可能需要重新授权',
    'Registration failed'                               => '注册失败',

    // ── TOTP 两步验证 ──
    'Invalid TOTP code'                                 => '两步验证码无效',
    'TOTP code required'                                => '请输入两步验证码',
    'No pending TOTP setup found. Call totp/setup first.' => '未找到等待确认的 TOTP 设置，请先调用 setup 接口',
    'Two-factor authentication has been enabled'        => '两步验证已启用',
    'Two-factor authentication has been disabled'       => '两步验证已禁用',
    'Store these codes securely. Each code can only be used once.' => '请安全保存恢复码，每个恢复码只能使用一次',
    'No recovery codes available. TOTP may not be enabled.' => '没有可用的恢复码，TOTP 可能未启用',
    'Invalid recovery code'                             => '恢复码无效',
    'Login, password, and recovery code required'       => '请填写邮箱/手机号、密码和恢复码',
    'TOTP is not enabled'                               => '两步验证未启用',

    // ── 验证码 ──
    'Captcha verification failed'                       => '验证码验证失败',
    'Captcha generation failed'                         => '验证码生成失败',

    // ── 商品 ──
    'Product created'                                   => '商品创建成功',
    'Product deleted'                                   => '商品删除成功',
    'Search query required'                             => '请输入搜索关键词',
    'SKU created'                                       => 'SKU 创建成功',
    'Region price set'                                  => '区域价格设置成功',

    // ── 购物车与订单 ──
    'Added to cart'                                     => '已添加到购物车',
    'Removed from cart'                                 => '已从购物车移除',
    'Order created'                                     => '订单创建成功',
    'Order cannot be paid'                              => '订单无法支付',
    'Order cannot be refunded'                          => '订单无法退款',
    'Refund request submitted'                          => '退款申请已提交',

    // ── 优惠券 ──
    'Coupon code required'                              => '请输入优惠券代码',
    'Coupon not found'                                  => '优惠券不存在',
    'Coupon is expired or has reached usage limit'      => '优惠券已过期或已达使用上限',

    // ── 发票 ──
    'Invoice already exists'                            => '发票已存在',
    'Invoice can only be generated for paid/completed orders' => '发票只能为已支付或已完成的订单生成',
    'Invoice generated'                                 => '发票已生成',

    // ── 支付 ──
    'Unsupported payment channel'                       => '不支持的支付通道',

    // ── 资源 ──
    'Resource IDs required'                             => '请选择要操作的资源',

    // ── 文件上传 ──
    'No file uploaded'                                  => '未上传文件',
    'File type not allowed: '                           => '不支持的文件类型：',
    'File too large. Max: '                             => '文件过大，最大允许：',
    'filename'                                          => '文件名',
    'errors'                                            => '错误',
    'imported'                                          => '已导入',

    // ── KYC 认证 ──
    'KYC submitted for review'                          => '实名认证已提交审核',
    'KYC already submitted or approved'                 => '实名认证已提交或已通过',
    'KYC approved'                                      => '实名认证审核通过',
    'KYC rejected'                                      => '实名认证审核已拒绝',

    // ── 工单 ──
    'Ticket created'                                    => '工单创建成功',
    'Reply sent'                                        => '回复发送成功',
    'Ticket assigned'                                   => '工单分配成功',
    'Ticket closed'                                     => '工单已关闭',

    // ── DNS ──
    'Invalid priority'                                  => '优先级无效',

    // ── 通知 ──
    'Notification preferences updated'                  => '通知偏好已更新',
    'Invalid preferences format'                        => '通知偏好格式无效',

    // ── 供应商 ──
    'Supplier approved'                                 => '供应商已批准',
    'All fields are required'                           => '请填写所有必填字段',
    'You already have a supplier application'           => '您已提交过供应商申请',
    'Product already assigned to this supplier'         => '该商品已分配给此供应商',
    'Settlement generated'                              => '结算单已生成',
    'Withdrawal requested'                              => '提现申请已提交',
    'Withdrawal approved'                               => '提现已批准',
    'Insufficient withdrawable balance'                 => '可提现余额不足',
    'API key created. Store it securely.'               => 'API Key 已创建，请安全保存（仅显示一次）',
    'api_key'                                           => 'API Key',
    'prefix'                                            => '前缀',
    'Missing or invalid API key format'                 => 'API Key 格式无效',
    'Invalid or revoked API key'                        => 'API Key 无效或已撤销',
    'Invalid token'                                     => '令牌无效',
    'Invalid token type'                                => '令牌类型无效',
    'Token revoked'                                     => '令牌已撤销',

    // ── Webhook ──
    'Webhook registered'                                => 'Webhook 注册成功',
    'Webhook removed'                                   => 'Webhook 已移除',
    'Test webhook sent'                                 => '测试 Webhook 已发送',
    'Valid URL required'                                => '请输入有效的 URL',

    // ── 管理员 ──
    'Config updated'                                    => '配置已更新',
    'CSV file required'                                 => '请上传 CSV 文件',
    'Invalid action. Allowed: '                         => '无效的操作，允许：',

    // ── 系统消息 ──
    'Access denied for your region'                     => '您所在的地区无法访问',
    'error.server_error'                                => '服务器内部错误',
    'order.paid'                                        => '订单支付成功',
    'order.cancelled'                                   => '订单已取消',
    'resource.created'                                  => '资源开通成功',
    'resource.destroyed'                                => '资源已销毁',
    'validation.required'                               => ':field 不能为空',
];
