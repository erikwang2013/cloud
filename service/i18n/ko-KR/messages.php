<?php
return [
    'ok' => '성공',
    'Unauthorized' => '인증 필요',
    'Forbidden' => '접근 금지',
    'Too Many Requests' => '요청이 너무 많습니다. 잠시 후 다시 시도하세요',
    'Authentication required' => '로그인이 필요합니다',
    'auth.login_success' => '로그인 성공',
    'auth.login_failed' => '이메일 또는 비밀번호가 올바르지 않습니다',
    'auth.register_success' => '회원가입 성공',
    'auth.token_expired' => '토큰이 만료되었습니다',
    'Email required' => '이메일을 입력하세요',
    'Phone number required' => '전화번호를 입력하세요',
    'Invalid email format' => '올바르지 않은 이메일 형식',
    'Invalid credentials' => '로그인 정보가 올바르지 않습니다',
    'Account temporarily locked' => '계정이 일시적으로 잠겼습니다',
    'Password has been reset successfully' => '비밀번호가 성공적으로 재설정되었습니다',
    'Email verified successfully' => '이메일이 인증되었습니다',
    'Verification code sent' => '인증 코드가 전송되었습니다',
    'Session revoked' => '세션이 만료되었습니다',
    'Invalid TOTP code' => '유효하지 않은 TOTP 코드',
    'Two-factor authentication has been enabled' => '2단계 인증이 활성화되었습니다',
    'Two-factor authentication has been disabled' => '2단계 인증이 비활성화되었습니다',
    'Captcha verification failed' => '보안문자 인증 실패',
    'Product created' => '상품이 생성되었습니다',
    'Product deleted' => '상품이 삭제되었습니다',
    'Search query required' => '검색어가 필요합니다',
    'Added to cart' => '장바구니에 추가됨',
    'Removed from cart' => '장바구니에서 제거됨',
    'Order created' => '주문이 생성되었습니다',
    'Order cannot be paid' => '결제할 수 없는 주문입니다',
    'Refund request submitted' => '환불 요청이 제출되었습니다',
    'Coupon not found' => '쿠폰을 찾을 수 없습니다',
    'Invoice generated' => '인보이스가 생성되었습니다',
    'Unsupported payment channel' => '지원되지 않는 결제 수단',
    'No file uploaded' => '업로드된 파일이 없습니다',
    'KYC submitted for review' => '신원 확인 서류가 제출되었습니다',
    'KYC approved' => '신원 확인이 승인되었습니다',
    'KYC rejected' => '신원 확인이 거부되었습니다',
    'Ticket created' => '티켓이 생성되었습니다',
    'Reply sent' => '답변이 전송되었습니다',
    'Ticket closed' => '티켓이 종료되었습니다',
    'Supplier approved' => '공급업체가 승인되었습니다',
    'Settlement generated' => '정산이 생성되었습니다',
    'Withdrawal requested' => '출금 요청이 제출되었습니다',
    'Withdrawal approved' => '출금이 승인되었습니다',
    'Config updated' => '설정이 업데이트되었습니다',
    'error.server_error' => '서버 오류가 발생했습니다',
    'order.paid' => '결제 완료',
    'order.cancelled' => '주문 취소',
    'resource.created' => '리소스가 프로비저닝되었습니다',
    'resource.destroyed' => '리소스가 삭제되었습니다',
    'validation.required' => ':field은(는) 필수입니다',

    // ── 일반 ──
    'Forbidden: admin access required' => '접근 금지: 관리자 권한이 필요합니다',
    'Forbidden: insufficient permissions' => '접근 금지: 권한이 부족합니다',
    'Request blocked by WAF' => '요청이 WAF에 의해 차단되었습니다',
    'Request body too large' => '요청 본문이 너무 큽니다 (최대 10MB)',
    'URI too long' => '요청 URI가 너무 깁니다',
    'Unsupported Content-Type' => '지원되지 않는 Content-Type',
    'Password confirmation required for this operation' => '이 작업에는 비밀번호 확인이 필요합니다',
    'Password verification failed' => '비밀번호 확인에 실패했습니다',
    'Too many confirmation attempts, try again later' => '비밀번호 확인 시도가 너무 많습니다. 15분 후 다시 시도하세요',

    // ── 인증 ──
    'Login and password required' => '이메일/전화번호와 비밀번호를 입력하세요',
    'Email or phone required, and password required' => '이메일 또는 전화번호와 비밀번호를 입력하세요',
    'Password must be at least 8 characters' => '비밀번호는 최소 8자 이상이어야 합니다',
    'Invalid or expired reset code' => '재설정 코드가 잘못되었거나 만료되었습니다',
    'If the email exists, a reset code has been sent' => '이메일이 존재하면 재설정 코드가 전송되었습니다',
    'Email, code, and password are required' => '이메일, 인증 코드, 새 비밀번호를 입력하세요',
    'Email already verified' => '이메일이 이미 인증되었습니다',
    'Invalid or expired verification token' => '인증 링크가 잘못되었거나 만료되었습니다',
    'Verification token required' => '인증 토큰이 필요합니다',
    'Verification email sent' => '인증 이메일이 전송되었습니다',
    'Please wait before requesting another code' => '잠시 후 다시 코드를 요청하세요',
    'Too many SMS requests' => 'SMS 요청이 너무 많습니다',
    'Account deleted. Data will be retained per our retention policy.' => '계정이 삭제되었습니다. 데이터는 보존 정책에 따라 유지됩니다',
    'Password verification required' => '비밀번호 확인이 필요합니다',
    'Password verification required to disable 2FA' => '2단계 인증을 비활성화하려면 비밀번호 확인이 필요합니다',

    // ── OAuth ──
    'Authorization code required' => '인가 코드가 필요합니다',
    'Invalid state' => '상태 매개변수가 잘못되었습니다',
    'Failed to obtain access token' => '액세스 토큰을 가져오지 못했습니다',
    'Failed to obtain ID token' => 'ID 토큰을 가져오지 못했습니다',
    'Email not provided by Apple. User may need to re-authorize.' => 'Apple에서 이메일이 제공되지 않았습니다. 재인증이 필요할 수 있습니다',
    'Registration failed' => '등록에 실패했습니다',

    // ── TOTP ──
    'TOTP code required' => 'TOTP 코드를 입력하세요',
    'No pending TOTP setup found. Call totp/setup first.' => '보류 중인 TOTP 설정이 없습니다. 먼저 setup을 호출하세요',
    'Store these codes securely. Each code can only be used once.' => '복구 코드를 안전하게 보관하세요. 각 코드는 한 번만 사용할 수 있습니다',
    'No recovery codes available. TOTP may not be enabled.' => '사용 가능한 복구 코드가 없습니다. TOTP가 활성화되지 않았을 수 있습니다',
    'Invalid recovery code' => '잘못된 복구 코드',
    'Login, password, and recovery code required' => '이메일/전화번호, 비밀번호, 복구 코드를 입력하세요',
    'TOTP is not enabled' => 'TOTP가 활성화되지 않았습니다',

    // ── 캡차 ──
    'Captcha generation failed' => '보안문자 생성에 실패했습니다',

    // ── 상품 ──
    'SKU created' => 'SKU가 생성되었습니다',
    'Region price set' => '지역 가격이 설정되었습니다',

    // ── 주문 ──
    'Order cannot be refunded' => '환불할 수 없는 주문입니다',

    // ── 쿠폰 ──
    'Coupon code required' => '쿠폰 코드가 필요합니다',
    'Coupon is expired or has reached usage limit' => '쿠폰이 만료되었거나 사용 한도에 도달했습니다',

    // ── 인보이스 ──
    'Invoice already exists' => '인보이스가 이미 존재합니다',
    'Invoice can only be generated for paid/completed orders' => '인보이스는 결제 완료 또는 완료된 주문에 대해서만 생성할 수 있습니다',

    // ── 리소스 ──
    'Resource IDs required' => '리소스 ID가 필요합니다',

    // ── 파일 업로드 ──
    'File type not allowed: ' => '허용되지 않는 파일 유형: ',
    'File too large. Max: ' => '파일이 너무 큽니다. 최대: ',
    'filename' => '파일 이름',
    'errors' => '오류',
    'imported' => '가져옴',

    // ── KYC ──
    'KYC already submitted or approved' => '신원 확인이 이미 제출되었거나 승인되었습니다',

    // ── 티켓 ──
    'Ticket assigned' => '티켓이 할당되었습니다',

    // ── DNS ──
    'Invalid priority' => '잘못된 우선순위',

    // ── 알림 ──
    'Notification preferences updated' => '알림 설정이 업데이트되었습니다',
    'Invalid preferences format' => '알림 설정 형식이 잘못되었습니다',

    // ── 공급업체 ──
    'All fields are required' => '모든 필수 필드를 입력하세요',
    'You already have a supplier application' => '이미 공급업체 신청을 제출했습니다',
    'Product already assigned to this supplier' => '이 상품은 이미 이 공급업체에 할당되었습니다',
    'Insufficient withdrawable balance' => '출금 가능 잔액이 부족합니다',
    'API key created. Store it securely.' => 'API 키가 생성되었습니다. 안전하게 보관하세요 (한 번만 표시됩니다)',
    'api_key' => 'API 키',
    'prefix' => '접두사',
    'Missing or invalid API key format' => 'API 키 형식이 잘못되었습니다',
    'Invalid or revoked API key' => 'API 키가 잘못되었거나 취소되었습니다',
    'Invalid token' => '잘못된 토큰',
    'Invalid token type' => '잘못된 토큰 유형',
    'Token revoked' => '토큰이 취소되었습니다',

    // ── Webhook ──
    'Webhook registered' => 'Webhook이 등록되었습니다',
    'Webhook removed' => 'Webhook이 제거되었습니다',
    'Test webhook sent' => '테스트 Webhook이 전송되었습니다',
    'Valid URL required' => '유효한 URL을 입력하세요',

    // ── 관리자 ──
    'CSV file required' => 'CSV 파일을 업로드하세요',
    'Invalid action. Allowed: ' => '잘못된 작업입니다. 허용되는 작업: ',

    // ── 시스템 ──
    'Access denied for your region' => '해당 지역에서는 접근할 수 없습니다',
    'Transfer approved' => '도메인 이전이 승인되었습니다',
    'Unknown feature: ' => '알 수 없는 기능: ',
    'Unknown feature: {name}' => '알 수 없는 기능: {name}',
];
