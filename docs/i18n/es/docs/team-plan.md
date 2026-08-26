# Plan de equipo de CloudPlatform

> Versión: 2026-08-17 (v2) | v1 elaborada por el pipeline multiagente (PASS_WITH_FIXES); v2 actualizada por el Lead a partir de los resultados reales de las Fases 0-2
> Base: v1 + todos los commits de las Fases 0-2 (git 111 commits) + registros de revisión doble + línea base de pruebas reales

## 1. Panorama actual (2026-08-17)

### 1.1 Grado de avance por fase

| Fase | Estado | Entregables clave |
|------|------|----------|
| Fase 0 Contención | ✅ 4/4 | Renderizado real de facturas, plantillas de notificación (6 tipos), conciliación explícita unverified, cabecera CSP/plantilla de entorno |
| Fase 1 Corto plazo | ✅ 8/8 | Carrito por cantidad, unificación del estado de reseñas, conciliación real (informes Stripe + por día), validación de condiciones de reembolso (72h/5 días + idempotencia + índice TOCTOU), 7 tipos de webhook de proveedores, cableado de Feature Flags + lado de administración, sincronización de documentación, pruebas reales |
| Fase 2 Medio plazo | ✅ 8/8 | Guardias de fondos (4), deuda de pruebas service/admin, install.sql con 31 tablas, RbacMiddleware montado en 57 rutas, admin en la imagen + nginx 8788 + CI doble, regresión de auditoría + cadena completa de login |
| Fase 3 Largo plazo | ✅ 9/9 | Gateway + límite unificado (P4.1), consistencia multi-moneda de extremo a extremo (P4.2), ingeniería de HarmonyOS + CI (P4.3), aterrizaje de ES (P4.4), absorción de observaciones (P4.5), 4 desviaciones de documentación (P3.1), consolidación de permisos (P3.2), clave de idempotencia de pedidos (P3.3), validación de puntuación de proveedores (P3.4), i18n en 7 idiomas (P3.6); reviewer-gate con verificación independiente, todo aprobado |

### 1.2 Línea base de calidad (real, verificación en serie tras cada commit)

- Suite service: **568 tests / 1279 assertions**, 10 skip (todos por carencia de entorno de BD)
- Suite admin: **255 tests / 887 assertions**, 1 skip (ruta de escritura de BD)
- CI 6 jobs: PHP Syntax / Admin Tests / Service Tests / Flutter Build / HarmonyOS Project Check / (relacionados con docker)
- Fondos/seguridad todo con revisión doble (security-auditor + reviewer con conclusiones independientes coincidentes); git agrupado por tareas, árbol de trabajo limpio
- Pago colateral: ocultación de credenciales en la serialización de 9 modelos Encryptable (barrido completo P1/P2)

## 2. Lista de pendientes y riesgos (revisión 2026-08-17)

### 2.1 Elementos que bloquean el despliegue (prioridad alta)

- **Carencia de entorno DB_PASSWORD**: service/.env con cadena vacía → todos los endpoints de BD dan 500; causa raíz de los 9+1 tests skip. No es problema de código; operaciones debe rellenar el valor (la plantilla ya existe en el .env.example raíz)
- **Falta el andamiaje del proyecto HarmonyOS**: apps/harmonyos solo tiene 3 archivos .ets (LoginPage/AuthManager/ApiClient); faltan todas las configuraciones de proyecto hvigor/DevEco → no se puede compilar; el CI harmonyos-check falla honestamente (exit 1)

### 2.2 Desviaciones documentación-código (4 P1 sin resolver)

- El filtro por status de GET /api/orders no está implementado
- Faltan los eventos de push WebSocket (la documentación de websocket_push lo declara)
- El alcance de disparo de ticket.updated no está claro
- product_attributes es un schema muerto (ningún código lo usa)

### 2.3 Observaciones de fondos/seguridad (registro de revisión doble, nivel bajo)

- **Los pedidos no tienen clave de idempotencia**: reenviar el mismo carrito puede generar pedidos duplicados (medio, se sugiere programarlo)
- La puntuación de proveedores no valida la pertenencia/estado del pedido
- Truncamiento bcmath del fee (quinto decimal, dirección de cobrar de menos <0.0001/operación; coherente con el enrutamiento, sin desviación de conciliación)
- El WAF sigue leyendo el body crudo en multipart grandes (en json lo cubre $input; multipart es una superficie de defensa adicional)
- user_coupons sin restricción única (la semántica permite varios pedidos/líneas por usuario; observación)
- nginx-admin sin CSP (admin es frontend Layui con scripts en línea; se conserva el estado actual)

### 2.4 Incoherencia del modelo de permisos (nuevo hallazgo P2, pendiente de consolidación)

- 6 identificadores de permiso solo en DB / 19 solo en Rbac / diferencias de asignación de roles (support/supplier)
- AdminRoleMiddleware excluye finance, mientras que Rbac.php define el rol finance

### 2.5 Otros

- Los archivos de idioma nuevos de i18n son el texto original en inglés (T6); los 7 idiomas no están completos
- La comprobación estructural de HarmonyOS en CI pasará a una compilación hvigor real cuando se complete el andamiaje

## 3. Hoja de ruta

Principio de prioridades (sin cambios): **fondos/seguridad > fiabilidad de entrega > cierre del bucle de negocio principal > experiencia y expansión**.

### Fase 3 — Cierre de residuos (1 mes)

**Objetivo**: cerrar todas las desviaciones y observaciones; despliegue reproducible (pruebas de la cadena completa de BD en verde real).

| Tarea | Implica | Rol | Dependencias |
|------|------|------|------|
| Cierre de las 4 desviaciones documentación-código (implementación del filtro de status de orders / cableado del push WebSocket / corrección de ticket.updated / eliminar o implementar product_attributes) | Order, WebSocket, Ticket, Product, docs | coder + researcher | Ninguna |
| Consolidación del modelo de permisos (alinear diferencias DB/Rbac + sembrado de roles + revisión de AdminRoleMiddleware) | Rbac, install.sql, admin | coder + security-auditor | Ninguna |
| Clave de idempotencia de pedidos (cart→order contra pedidos duplicados) | OrderService | coder | Ninguna (revisión doble por ser clase de fondos) |
| Validación de la pertenencia/estado del pedido en la puntuación de proveedores | Supplier, Review | coder | Ninguna |
| Conexión operativa de DB_PASSWORD + ejecución real de los 10 tests skip | Operaciones, tests | security-auditor | Colaboración de operaciones |
| Completar la traducción de los 7 idiomas de i18n | Archivos i18n | coder | Ninguna |

**Aceptación**: las 4 desviaciones cerradas; matriz de permisos DB/código coherente; test de clave de idempotencia; pruebas de la cadena completa de BD en verde real; i18n al menos chino/inglés usable.

### Fase 4 — Evolución de arquitectura (1-3 meses)

**Objetivo**: arquitectura de cuatro capas consolidada, que soporte el crecimiento multi-extremo y multi-moneda.

| Tarea | Implica | Rol | Dependencias |
|------|------|------|------|
| Gateway de API independiente + montaje del límite unificado (incluida la carencia de graphql) | gateway, route | architect + coder | P3 |
| Consistencia multi-moneda de extremo a extremo (incluida la estrategia de redondeo del fee) | Payment, Billing | architect + performance-engineer | Ídem |
| Ingeniería de HarmonyOS: andamiaje + compilación CI real + login funcionando | apps/harmonyos | mobile-dev | Ninguna |
| Aterrizaje de la auditoría ES, sustituyendo la solución alternativa | docker, búsqueda Product | coder | Ninguna |
| Absorción por lotes de observaciones (WAF multipart / restricción user_coupons / webhook de proveedores de extremo a extremo) | Security, Order, Supplier | coder + tester | Ninguna |

**Aceptación**: k6 verifica el límite en todas las rutas; contabilidad multi-moneda con cero errores; HarmonyOS genera paquete y pasa CI; búsqueda ES realmente usable.

## 4. Reparto del equipo

Núcleo fijo: Lead(planner) / architect / coder / tester / reviewer / researcher
Bajo demanda: mobile-dev / security-architect / security-auditor / performance-engineer

| Fase | Roles convocados | Descripción |
|------|----------|------|
| P3 | coder (principal), researcher, security-auditor | Cierre sobre todo; revisión doble de permisos/idempotencia |
| P4 | architect, coder, mobile-dev, performance-engineer | Evolución de arquitectura; security-architect como asesor permanente |

El modo de colaboración no cambia: pipeline de CLAUDE.md (architect→coder→tester→reviewer), las tareas internas de P3/P4 con fan-out en paralelo; **las tareas de fondos/seguridad exigen revisión doble obligatoria**; al terminar cada fase se actualiza este documento (esta v2 la redactó el Lead directamente, sin pasar por el pipeline; se puede revisar).

## 5. Forma de seguimiento de riesgos

- Esta lista se actualiza de forma continua al terminar cada fase; los nuevos hallazgos (como la incoherencia del modelo de permisos en P2 o la idempotencia de pedidos) se incorporan al momento
- Los de baja prioridad conocidos (webhook de proveedores de extremo a extremo, body multipart) ya están en el lote de absorción de P4; no se extienden fuera de la lista

## 6. Principales fuentes de evidencia

- Commits: git log (111 commits, Fases 0-2 agrupados por tarea)
- Línea base de pruebas: salida real de las suites service/admin
- Registros de revisión: mensajes de revisión doble de P1/P2 (guardias de fondos, logout/WAF, RBAC, regresión de auditoría)
- Documentación: v1 (historial de docs/team-plan.md), docs/audit-report-2026-08-06-v3.md, docs/api-reference.md
