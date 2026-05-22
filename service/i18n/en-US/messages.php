<?php
return [
    // ── General ──
    'ok'                                                => 'ok',
    'Unauthorized'                                      => 'Unauthorized',
    'Forbidden'                                         => 'Forbidden',
    'Forbidden: admin access required'                  => 'Forbidden: admin access required',
    'Forbidden: insufficient permissions'               => 'Forbidden: insufficient permissions',
    'Too Many Requests'                                 => 'Too many requests, please try again later',
    'Request blocked by WAF'                            => 'Request blocked by WAF',
    'Request body too large'                            => 'Request body too large (max 10MB)',
    'URI too long'                                      => 'URI too long',
    'Unsupported Content-Type'                          => 'Unsupported Content-Type',
    'Authentication required'                           => 'Authentication required',
    'Password confirmation required for this operation' => 'Password confirmation required for this operation',
    'Password verification failed'                      => 'Password verification failed',
    'Too many confirmation attempts, try again later'   => 'Too many confirmation attempts, try again in 15 minutes',

    // ── Authentication ──
    'auth.login_success'                                => 'Login successful',
    'auth.login_failed'                                 => 'Invalid email or password',
    'auth.register_success'                             => 'Registration successful',
    'auth.token_expired'                                => 'Token expired, please login again',
    'Login and password required'                       => 'Login and password are required',
    'Email or phone required, and password required'    => 'Email or phone and password are required',
    'Password must be at least 8 characters'            => 'Password must be at least 8 characters',
    'Email required'                                    => 'Email is required',
    'Phone number required'                             => 'Phone number is required',
    'Invalid email format'                              => 'Invalid email format',
    'Invalid credentials'                               => 'Invalid credentials',
    'Account temporarily locked'                        => 'Account temporarily locked, try again in 15 minutes',
    'Invalid or expired reset code'                     => 'Invalid or expired reset code',
    'Password has been reset successfully'              => 'Password has been reset successfully',
    'If the email exists, a reset code has been sent'   => 'If the email exists, a reset code has been sent',
    'Email, code, and password are required'            => 'Email, code, and new password are required',
    'Email verified successfully'                       => 'Email verified successfully',
    'Email already verified'                            => 'Email already verified',
    'Invalid or expired verification token'             => 'Invalid or expired verification token',
    'Verification token required'                       => 'Verification token required',
    'Verification code sent'                            => 'Verification code sent',
    'Verification email sent'                           => 'Verification email sent',
    'Please wait before requesting another code'        => 'Please wait before requesting another code',
    'Too many SMS requests'                             => 'Too many SMS requests',
    'Session revoked'                                   => 'Session revoked',
    'Account deleted. Data will be retained per our retention policy.' => 'Account deleted. Data will be retained per our retention policy.',
    'Password verification required'                    => 'Password verification required',
    'Password verification required to disable 2FA'     => 'Password verification required to disable 2FA',

    // ── OAuth ──
    'Authorization code required'                       => 'Authorization code required',
    'Invalid state'                                     => 'Invalid state',
    'Failed to obtain access token'                     => 'Failed to obtain access token',
    'Failed to obtain ID token'                         => 'Failed to obtain ID token',
    'Email not provided by Apple. User may need to re-authorize.' => 'Email not provided by Apple. You may need to re-authorize.',
    'Registration failed'                               => 'Registration failed',

    // ── TOTP 2FA ──
    'Invalid TOTP code'                                 => 'Invalid TOTP code',
    'TOTP code required'                                => 'TOTP code required',
    'No pending TOTP setup found. Call totp/setup first.' => 'No pending TOTP setup found. Call totp/setup first.',
    'Two-factor authentication has been enabled'        => 'Two-factor authentication has been enabled',
    'Two-factor authentication has been disabled'       => 'Two-factor authentication has been disabled',
    'Store these codes securely. Each code can only be used once.' => 'Store these codes securely. Each code can only be used once.',
    'No recovery codes available. TOTP may not be enabled.' => 'No recovery codes available. TOTP may not be enabled.',
    'Invalid recovery code'                             => 'Invalid recovery code',
    'Login, password, and recovery code required'       => 'Login, password, and recovery code required',
    'TOTP is not enabled'                               => 'TOTP is not enabled',

    // ── CAPTCHA ──
    'Captcha verification failed'                       => 'Captcha verification failed',
    'Captcha generation failed'                         => 'Captcha generation failed',

    // ── Products ──
    'Product created'                                   => 'Product created',
    'Product deleted'                                   => 'Product deleted',
    'Search query required'                             => 'Search query required',
    'SKU created'                                       => 'SKU created',
    'Region price set'                                  => 'Region price set',

    // ── Cart & Orders ──
    'Added to cart'                                     => 'Added to cart',
    'Removed from cart'                                 => 'Removed from cart',
    'Order created'                                     => 'Order created',
    'Order cannot be paid'                              => 'Order cannot be paid',
    'Order cannot be refunded'                          => 'Order cannot be refunded',
    'Refund request submitted'                          => 'Refund request submitted',

    // ── Coupons ──
    'Coupon code required'                              => 'Coupon code required',
    'Coupon not found'                                  => 'Coupon not found',
    'Coupon is expired or has reached usage limit'      => 'Coupon is expired or has reached usage limit',

    // ── Invoices ──
    'Invoice already exists'                            => 'Invoice already exists',
    'Invoice can only be generated for paid/completed orders' => 'Invoice can only be generated for paid or completed orders',
    'Invoice generated'                                 => 'Invoice generated',

    // ── Payment ──
    'Unsupported payment channel'                       => 'Unsupported payment channel',

    // ── Resources ──
    'Resource IDs required'                             => 'Resource IDs required',

    // ── File Upload ──
    'No file uploaded'                                  => 'No file uploaded',
    'File type not allowed: '                           => 'File type not allowed: ',
    'File too large. Max: '                             => 'File too large. Max: ',
    'filename'                                          => 'filename',
    'errors'                                            => 'errors',
    'imported'                                          => 'imported',

    // ── KYC ──
    'KYC submitted for review'                          => 'KYC submitted for review',
    'KYC already submitted or approved'                 => 'KYC already submitted or approved',
    'KYC approved'                                      => 'KYC approved',
    'KYC rejected'                                      => 'KYC rejected',

    // ── Tickets ──
    'Ticket created'                                    => 'Ticket created',
    'Reply sent'                                        => 'Reply sent',
    'Ticket assigned'                                   => 'Ticket assigned',
    'Ticket closed'                                     => 'Ticket closed',

    // ── DNS ──
    'Invalid priority'                                  => 'Invalid priority',

    // ── Notifications ──
    'Notification preferences updated'                  => 'Notification preferences updated',
    'Invalid preferences format'                        => 'Invalid preferences format',

    // ── Supplier ──
    'Supplier approved'                                 => 'Supplier approved',
    'All fields are required'                           => 'All fields are required',
    'You already have a supplier application'           => 'You already have a supplier application',
    'Product already assigned to this supplier'         => 'Product already assigned to this supplier',
    'Settlement generated'                              => 'Settlement generated',
    'Withdrawal requested'                              => 'Withdrawal requested',
    'Withdrawal approved'                               => 'Withdrawal approved',
    'Insufficient withdrawable balance'                 => 'Insufficient withdrawable balance',
    'API key created. Store it securely.'               => 'API key created. Store it securely.',
    'api_key'                                           => 'API Key',
    'prefix'                                            => 'Prefix',
    'Missing or invalid API key format'                 => 'Missing or invalid API key format',
    'Invalid or revoked API key'                        => 'Invalid or revoked API key',
    'Invalid token'                                     => 'Invalid token',
    'Invalid token type'                                => 'Invalid token type',
    'Token revoked'                                     => 'Token revoked',

    // ── Webhook ──
    'Webhook registered'                                => 'Webhook registered',
    'Webhook removed'                                   => 'Webhook removed',
    'Test webhook sent'                                 => 'Test webhook sent',
    'Valid URL required'                                => 'Valid URL required',

    // ── Admin ──
    'Config updated'                                    => 'Config updated',
    'CSV file required'                                 => 'CSV file required',
    'Invalid action. Allowed: '                         => 'Invalid action. Allowed: ',

    // ── System ──
    'Access denied for your region'                     => 'Access denied for your region',
    'Transfer approved'                                 => 'Transfer approved',
    'Unknown feature: '                                 => 'Unknown feature: ',
    'Unknown feature: {name}'                           => 'Unknown feature: {name}',
    'error.server_error'                                => 'Internal server error',
    'order.paid'                                        => 'Order paid successfully',
    'order.cancelled'                                   => 'Order cancelled',
    'resource.created'                                  => 'Resource provisioned successfully',
    'resource.destroyed'                                => 'Resource destroyed',
    'validation.required'                               => ':field is required',
];
