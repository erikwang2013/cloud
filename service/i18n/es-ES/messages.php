<?php
return [
    'ok' => 'Éxito',
    'Unauthorized' => 'Autenticación requerida',
    'Forbidden' => 'Acceso prohibido',
    'Too Many Requests' => 'Demasiadas solicitudes, intente más tarde',
    'Authentication required' => 'Inicie sesión',
    'auth.login_success' => 'Inicio de sesión exitoso',
    'auth.login_failed' => 'Correo o contraseña inválidos',
    'auth.register_success' => 'Registro exitoso',
    'auth.token_expired' => 'Token expirado',
    'Email required' => 'Correo electrónico requerido',
    'Invalid email format' => 'Formato de correo inválido',
    'Invalid credentials' => 'Credenciales inválidas',
    'Account temporarily locked' => 'Cuenta bloqueada temporalmente',
    'Password has been reset successfully' => 'Contraseña restablecida',
    'Email verified successfully' => 'Correo verificado',
    'Verification code sent' => 'Código de verificación enviado',
    'Session revoked' => 'Sesión revocada',
    'Invalid TOTP code' => 'Código TOTP inválido',
    'Two-factor authentication has been enabled' => 'Autenticación de dos factores activada',
    'Captcha verification failed' => 'Verificación captcha fallida',
    'Product created' => 'Producto creado',
    'Product deleted' => 'Producto eliminado',
    'Search query required' => 'Término de búsqueda requerido',
    'Added to cart' => 'Añadido al carrito',
    'Removed from cart' => 'Eliminado del carrito',
    'Order created' => 'Pedido creado',
    'Order cannot be paid' => 'Este pedido no se puede pagar',
    'Refund request submitted' => 'Solicitud de reembolso enviada',
    'Coupon not found' => 'Cupón no encontrado',
    'Invoice generated' => 'Factura generada',
    'No file uploaded' => 'No se cargó ningún archivo',
    'KYC approved' => 'Verificación de identidad aprobada',
    'KYC rejected' => 'Verificación de identidad rechazada',
    'Ticket created' => 'Ticket creado',
    'Reply sent' => 'Respuesta enviada',
    'Ticket closed' => 'Ticket cerrado',
    'Supplier approved' => 'Proveedor aprobado',
    'Settlement generated' => 'Liquidación generada',
    'Withdrawal requested' => 'Retiro solicitado',
    'error.server_error' => 'Error interno del servidor',
    'order.paid' => 'Pago realizado',
    'resource.created' => 'Recurso aprovisionado',
    'resource.destroyed' => 'Recurso eliminado',
    'validation.required' => ':field es obligatorio',

    // ── General ──
    'Forbidden: admin access required' => 'Acceso prohibido: se requieren privilegios de administrador',
    'Forbidden: insufficient permissions' => 'Acceso prohibido: permisos insuficientes',
    'Request blocked by WAF' => 'Solicitud bloqueada por el firewall',
    'Request body too large' => 'Cuerpo de la solicitud demasiado grande (máx. 10 MB)',
    'URI too long' => 'URI de la solicitud demasiado larga',
    'Unsupported Content-Type' => 'Content-Type no compatible',
    'Password confirmation required for this operation' => 'Esta operación requiere confirmación de contraseña',
    'Password verification failed' => 'La verificación de la contraseña falló',
    'Too many confirmation attempts, try again later' => 'Demasiados intentos de confirmación, intente de nuevo en 15 minutos',

    // ── Autenticación ──
    'Login and password required' => 'Ingrese su correo/teléfono y contraseña',
    'Email or phone required, and password required' => 'Ingrese su correo o teléfono y establezca una contraseña',
    'Password must be at least 8 characters' => 'La contraseña debe tener al menos 8 caracteres',
    'Phone number required' => 'Se requiere número de teléfono',
    'Invalid or expired reset code' => 'Código de restablecimiento inválido o caducado',
    'If the email exists, a reset code has been sent' => 'Si el correo existe, se ha enviado un código de restablecimiento',
    'Email, code, and password are required' => 'Ingrese su correo, el código y una nueva contraseña',
    'Email already verified' => 'Correo ya verificado',
    'Invalid or expired verification token' => 'Enlace de verificación inválido o caducado',
    'Verification token required' => 'Se requiere token de verificación',
    'Verification email sent' => 'Correo de verificación enviado',
    'Please wait before requesting another code' => 'Espere antes de solicitar otro código',
    'Too many SMS requests' => 'Demasiadas solicitudes SMS',
    'Account deleted. Data will be retained per our retention policy.' => 'Cuenta eliminada. Los datos se conservarán según nuestra política de retención',
    'Password verification required' => 'Se requiere verificación de contraseña',
    'Password verification required to disable 2FA' => 'Se requiere verificación de contraseña para desactivar la 2FA',

    // ── OAuth ──
    'Authorization code required' => 'Se requiere código de autorización',
    'Invalid state' => 'Parámetro de estado inválido',
    'Failed to obtain access token' => 'No se pudo obtener el token de acceso',
    'Failed to obtain ID token' => 'No se pudo obtener el token de identidad',
    'Email not provided by Apple. User may need to re-authorize.' => 'Apple no proporcionó un correo. Es posible que deba reautorizar',
    'Registration failed' => 'El registro falló',

    // ── TOTP ──
    'TOTP code required' => 'Se requiere código TOTP',
    'No pending TOTP setup found. Call totp/setup first.' => 'No hay configuración TOTP pendiente. Llame a totp/setup primero',
    'Two-factor authentication has been disabled' => 'Autenticación de dos factores desactivada',
    'Store these codes securely. Each code can only be used once.' => 'Guarde estos códigos de forma segura. Cada código solo puede usarse una vez',
    'No recovery codes available. TOTP may not be enabled.' => 'No hay códigos de recuperación disponibles. TOTP puede no estar habilitado',
    'Invalid recovery code' => 'Código de recuperación inválido',
    'Login, password, and recovery code required' => 'Ingrese correo/teléfono, contraseña y código de recuperación',
    'TOTP is not enabled' => 'TOTP no está habilitado',

    // ── CAPTCHA ──
    'Captcha generation failed' => 'La generación del captcha falló',

    // ── Productos ──
    'SKU created' => 'SKU creado',
    'Region price set' => 'Precio regional establecido',

    // ── Pedidos ──
    'Order cannot be refunded' => 'Esta orden no puede reembolsarse',

    // ── Cupones ──
    'Coupon code required' => 'Se requiere código de cupón',
    'Coupon is expired or has reached usage limit' => 'El cupón caducó o alcanzó su límite de uso',

    // ── Facturas ──
    'Invoice already exists' => 'La factura ya existe',
    'Invoice can only be generated for paid/completed orders' => 'La factura solo puede generarse para órdenes pagadas o completadas',

    // ── Pago ──
    'Unsupported payment channel' => 'Canal de pago no compatible',

    // ── Recursos ──
    'Resource IDs required' => 'Se requieren IDs de recurso',

    // ── Carga de archivos ──
    'File type not allowed: ' => 'Tipo de archivo no permitido: ',
    'File too large. Max: ' => 'Archivo demasiado grande. Máximo: ',
    'filename' => 'nombre de archivo',
    'errors' => 'errores',
    'imported' => 'importado',

    // ── KYC ──
    'KYC submitted for review' => 'Verificación de identidad enviada para revisión',
    'KYC already submitted or approved' => 'Verificación de identidad ya enviada o aprobada',

    // ── Tickets ──
    'Ticket assigned' => 'Ticket asignado',

    // ── DNS ──
    'Invalid priority' => 'Prioridad inválida',

    // ── Notificaciones ──
    'Notification preferences updated' => 'Preferencias de notificación actualizadas',
    'Invalid preferences format' => 'Formato de preferencias inválido',

    // ── Proveedor ──
    'All fields are required' => 'Todos los campos obligatorios deben completarse',
    'You already have a supplier application' => 'Ya ha enviado una solicitud de proveedor',
    'Product already assigned to this supplier' => 'Este producto ya está asignado a este proveedor',
    'Withdrawal approved' => 'Retiro aprobado',
    'Insufficient withdrawable balance' => 'Saldo retirable insuficiente',
    'API key created. Store it securely.' => 'Clave API creada. Guárdela de forma segura (se muestra una sola vez)',
    'api_key' => 'Clave API',
    'prefix' => 'prefijo',
    'Missing or invalid API key format' => 'Formato de clave API inválido',
    'Invalid or revoked API key' => 'Clave API inválida o revocada',
    'Invalid token' => 'Token inválido',
    'Invalid token type' => 'Tipo de token inválido',
    'Token revoked' => 'Token revocado',

    // ── Webhook ──
    'Webhook registered' => 'Webhook registrado',
    'Webhook removed' => 'Webhook eliminado',
    'Test webhook sent' => 'Webhook de prueba enviado',
    'Valid URL required' => 'Se requiere una URL válida',

    // ── Administración ──
    'Config updated' => 'Configuración actualizada',
    'CSV file required' => 'Sube un archivo CSV',
    'Invalid action. Allowed: ' => 'Acción inválida. Permitidas: ',

    // ── Sistema ──
    'Access denied for your region' => 'Acceso denegado para su región',
    'Transfer approved' => 'Transferencia de dominio aprobada',
    'Unknown feature: ' => 'Función desconocida: ',
    'Unknown feature: {name}' => 'Función desconocida: {name}',
    'order.cancelled' => 'Orden cancelada',
];
