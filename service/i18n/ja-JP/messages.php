<?php
return [
    'ok' => '成功',
    'Unauthorized' => '認証が必要です',
    'Forbidden' => 'アクセス禁止',
    'Too Many Requests' => 'リクエストが多すぎます。しばらくお待ちください',
    'Authentication required' => 'ログインしてください',
    'Password confirmation required for this operation' => 'この操作にはパスワード確認が必要です',
    'Password verification failed' => 'パスワード確認に失敗しました',
    'auth.login_success' => 'ログイン成功',
    'auth.login_failed' => 'メールアドレスまたはパスワードが無効です',
    'auth.register_success' => '登録成功',
    'auth.token_expired' => 'トークンの有効期限が切れました',
    'Login and password required' => 'ログイン情報を入力してください',
    'Email required' => 'メールアドレスを入力してください',
    'Phone number required' => '電話番号を入力してください',
    'Invalid email format' => 'メールアドレスの形式が無効です',
    'Invalid credentials' => '認証情報が無効です',
    'Account temporarily locked' => 'アカウントが一時的にロックされました',
    'Invalid or expired reset code' => 'リセットコードが無効または期限切れです',
    'Password has been reset successfully' => 'パスワードが正常にリセットされました',
    'Email verified successfully' => 'メールアドレスが確認されました',
    'Verification code sent' => '確認コードを送信しました',
    'Verification email sent' => '確認メールを送信しました',
    'Session revoked' => 'セッションが無効化されました',
    'Invalid TOTP code' => '無効なTOTPコード',
    'Two-factor authentication has been enabled' => '二要素認証が有効になりました',
    'Two-factor authentication has been disabled' => '二要素認証が無効になりました',
    'Invalid recovery code' => '無効なリカバリーコード',
    'Captcha verification failed' => 'キャプチャ認証に失敗しました',
    'Product created' => '商品が作成されました',
    'Product deleted' => '商品が削除されました',
    'Search query required' => '検索キーワードが必要です',
    'SKU created' => 'SKUが作成されました',
    'Added to cart' => 'カートに追加しました',
    'Removed from cart' => 'カートから削除しました',
    'Order created' => '注文が作成されました',
    'Order cannot be paid' => 'この注文は支払いできません',
    'Order cannot be refunded' => 'この注文は返金できません',
    'Refund request submitted' => '返金リクエストが送信されました',
    'Coupon code required' => 'クーポンコードが必要です',
    'Coupon not found' => 'クーポンが見つかりません',
    'Invoice generated' => '請求書が生成されました',
    'Unsupported payment channel' => 'サポートされていない決済方法',
    'Resource IDs required' => 'リソースIDが必要です',
    'No file uploaded' => 'ファイルがアップロードされていません',
    'KYC submitted for review' => '本人確認書類を提出しました',
    'KYC approved' => '本人確認が承認されました',
    'KYC rejected' => '本人確認が拒否されました',
    'Ticket created' => 'チケットが作成されました',
    'Reply sent' => '返信を送信しました',
    'Ticket assigned' => 'チケットが割り当てられました',
    'Ticket closed' => 'チケットがクローズされました',
    'Supplier approved' => 'サプライヤーが承認されました',
    'Settlement generated' => '決済が生成されました',
    'Withdrawal requested' => '出金リクエストが送信されました',
    'Withdrawal approved' => '出金が承認されました',
    'Config updated' => '設定が更新されました',
    'error.server_error' => 'サーバーエラーが発生しました',
    'order.paid' => '支払いが完了しました',
    'order.cancelled' => '注文がキャンセルされました',
    'resource.created' => 'リソースがプロビジョニングされました',
    'resource.destroyed' => 'リソースが削除されました',
    'validation.required' => ':fieldは必須です',

    // ── 一般 ──
    'Forbidden: admin access required' => 'アクセス禁止：管理者権限が必要です',
    'Forbidden: insufficient permissions' => 'アクセス禁止：権限が不足しています',
    'Request blocked by WAF' => 'リクエストはWAFによりブロックされました',
    'Request body too large' => 'リクエストボディが大きすぎます（最大10MB）',
    'URI too long' => 'リクエストURIが長すぎます',
    'Unsupported Content-Type' => 'サポートされていないContent-Type',
    'Too many confirmation attempts, try again later' => '確認試行回数が多すぎます。15分後にもう一度お試しください',

    // ── 認証 ──
    'Email or phone required, and password required' => 'メールアドレスまたは電話番号、パスワードを入力してください',
    'Password must be at least 8 characters' => 'パスワードは8文字以上で入力してください',
    'If the email exists, a reset code has been sent' => 'メールアドレスが存在する場合、リセットコードを送信しました',
    'Email, code, and password are required' => 'メールアドレス、認証コード、新しいパスワードを入力してください',
    'Email already verified' => 'メールアドレスは確認済みです',
    'Invalid or expired verification token' => '確認リンクが無効または期限切れです',
    'Verification token required' => '確認トークンが必要です',
    'Please wait before requesting another code' => 'しばらく待ってから再度コードを取得してください',
    'Too many SMS requests' => 'SMSリクエストが多すぎます',
    'Account deleted. Data will be retained per our retention policy.' => 'アカウントが削除されました。データは保存ポリシーに従って保持されます',
    'Password verification required' => 'パスワード確認が必要です',
    'Password verification required to disable 2FA' => '二要素認証を無効にするにはパスワード確認が必要です',

    // ── OAuth ──
    'Authorization code required' => '認可コードが必要です',
    'Invalid state' => '状態パラメータが無効です',
    'Failed to obtain access token' => 'アクセストークンの取得に失敗しました',
    'Failed to obtain ID token' => 'IDトークンの取得に失敗しました',
    'Email not provided by Apple. User may need to re-authorize.' => 'Appleからメールアドレスが提供されませんでした。再認可が必要な場合があります',
    'Registration failed' => '登録に失敗しました',

    // ── TOTP ──
    'TOTP code required' => 'TOTPコードを入力してください',
    'No pending TOTP setup found. Call totp/setup first.' => '保留中のTOTP設定が見つかりません。先にsetupを呼び出してください',
    'Store these codes securely. Each code can only be used once.' => 'リカバリーコードを安全に保管してください。各コードは一度しか使用できません',
    'No recovery codes available. TOTP may not be enabled.' => '利用可能なリカバリーコードがありません。TOTPが有効でない可能性があります',
    'Login, password, and recovery code required' => 'メールアドレス/電話番号、パスワード、リカバリーコードを入力してください',
    'TOTP is not enabled' => 'TOTPが有効になっていません',

    // ── キャプチャ ──
    'Captcha generation failed' => 'キャプチャの生成に失敗しました',

    // ── 商品 ──
    'Region price set' => '地域価格が設定されました',

    // ── クーポン ──
    'Coupon is expired or has reached usage limit' => 'クーポンの有効期限が切れたか、使用上限に達しました',

    // ── 請求書 ──
    'Invoice already exists' => '請求書は既に存在します',
    'Invoice can only be generated for paid/completed orders' => '請求書は支払い済みまたは完了した注文に対してのみ生成できます',

    // ── ファイルアップロード ──
    'File type not allowed: ' => '許可されていないファイルタイプ：',
    'File too large. Max: ' => 'ファイルが大きすぎます。最大：',
    'filename' => 'ファイル名',
    'errors' => 'エラー',
    'imported' => 'インポート済み',

    // ── KYC ──
    'KYC already submitted or approved' => '本人確認は提出済みまたは承認済みです',

    // ── DNS ──
    'Invalid priority' => '優先度が無効です',

    // ── 通知 ──
    'Notification preferences updated' => '通知設定が更新されました',
    'Invalid preferences format' => '通知設定の形式が無効です',

    // ── サプライヤー ──
    'All fields are required' => 'すべての必須フィールドを入力してください',
    'You already have a supplier application' => 'サプライヤー申請を既に提出しています',
    'Product already assigned to this supplier' => 'この商品は既にこのサプライヤーに割り当てられています',
    'Insufficient withdrawable balance' => '出金可能残高が不足しています',
    'API key created. Store it securely.' => 'APIキーが作成されました。安全に保管してください（一度しか表示されません）',
    'api_key' => 'APIキー',
    'prefix' => 'プレフィックス',
    'Missing or invalid API key format' => 'APIキーの形式が無効です',
    'Invalid or revoked API key' => 'APIキーが無効または失効しています',
    'Invalid token' => 'トークンが無効です',
    'Invalid token type' => 'トークンタイプが無効です',
    'Token revoked' => 'トークンが失効しました',

    // ── Webhook ──
    'Webhook registered' => 'Webhookが登録されました',
    'Webhook removed' => 'Webhookが削除されました',
    'Test webhook sent' => 'テストWebhookを送信しました',
    'Valid URL required' => '有効なURLを入力してください',

    // ── 管理者 ──
    'CSV file required' => 'CSVファイルをアップロードしてください',
    'Invalid action. Allowed: ' => '無効な操作です。許可される操作：',

    // ── システム ──
    'Access denied for your region' => 'お住まいの地域からはアクセスできません',
    'Transfer approved' => 'ドメイン移管が承認されました',
    'Unknown feature: ' => '不明な機能：',
    'Unknown feature: {name}' => '不明な機能：{name}',
];
