# Informe de complemento de cobertura de pruebas unitarias PHP (2026-08-26)

## Entorno

- PHP 8.3.7 (suite service PHPUnit 10.5.64 / suite admin PHPUnit 11.5.56)
- service/: API de negocio; admin/: panel de administración
- Datos de prueba: SQLite `:memory:` (inicialización con Capsule, siguiendo el patrón de los ReportServiceTest / OrderIdentityTest existentes); los servicios externos (Redis/MySQL/Stripe) están todos degradados o mockeados

## Resultado del inventario: módulo vs cobertura

### service/app (27 módulos)

| Módulo | Tests antes del inventario | Estado de cobertura |
|------|-----------|----------|
| order / payment / user / product / provisioning / supplier / affiliate / notification / webhook / websocket / graphql / grpc / monitor / billing / captcha / domain / ssl / storage / report / ticket / admin / security / confirmation / version / clientplatform | 1-12 archivos de test cada uno | Cubierto |
| **command** (6 comandos) | **ninguno** | **0 cobertura → esta ronda añade ReconcileCommandTest** |
| **cron** (6 tareas) | solo SupplierSettlementTest | Cobertura parcial → esta ronda añade PaymentReconcileTest + ExchangeRateSyncTest |
| controller (Health/Help/Status/Upload) | ninguno | Controladores ligeros (estado estático/comprobación de salud), sin lógica de negocio |
| model (20+ modelos de payment/order etc.) | cubiertos indirectamente a través de la capa de servicio | Cubierto |

### admin/app (controller/common/model/middleware)

| Módulo | Tests antes del inventario | Estado de cobertura |
|------|-----------|----------|
| controller (48 controladores) | AdminControllersTest (reflexión sobre todos los controladores: ensamblaje de modelos/superficie CRUD/rutas de vista GET) + CrudHashidsTest | Cubierto |
| middleware | AccessControlMiddlewareTest | Cubierto |
| common | TreeTest / HashidsTest / BaseJsonTest | Cobertura parcial → esta ronda añade UtilTest + LayuiTest + ExcelExportTest |
| model | sin tests directos | Esta ronda añade DictTest; el resto de modelos son mapeos ligeros |

## Tests añadidos en esta ronda

| Módulo | Archivo nuevo | Casos | Aserciones | Puntos cubiertos |
|------|----------|------|------|--------|
| Cron (conciliación de fondos) | `service/tests/cron/PaymentReconcileTest.php` | 7 | 24 | compare con redondeo half-up a la precisión de la mínima unidad por moneda: residuo de sub-cent verified y diff a cero; diferencia real mismatch; monedas de cero decimales (JPY) con acarreo entero; moneda presente solo en un lado; lado vacío verified; fecha ilegal lanza InvalidArgumentException; run() hace upsert de filas unverified sin canal de informe (solo success cuenta en el resumen local, failed queda excluida, índice único espejo de producción) |
| Cron (sincronización de divisas) | `service/tests/cron/ExchangeRateSyncTest.php` | 2 | 2 | API inalcanzable finaliza en silencio (sin lanzar al planificador); payload válido + Redis no disponible no provoca crash |
| Command (comando de conciliación) | `service/tests/command/ReconcileCommandTest.php` | 2 | 3 | Fecha ilegal → FAILURE + mensaje de error; fecha válida → SUCCESS (tabla de canales vacía) |
| Admin Common | `admin/tests/UtilTest.php` | 17 | 47 | hash/verify de contraseñas ida y vuelta; humanDate con cinco franjas de tiempo relativo; formatBytes; validación de checkTableName/filterAlphaNum/filterNum/filterUrlPath/filterPath (incluida BusinessException); controllerToUrlPath (con @action y entradas ilegales); camel/smCamel; getCommentFirstLine; typeToControl/typeToMethod; getLengthValue (decimal/enum/varchar); getControlProps (select data a lista value/name vs key=>value normal) |
| Admin Model | `admin/tests/DictTest.php` | 5 | 10 | conversión nombre de diccionario↔nombre de opción; validación de formato filterValue; el nombre debe contener letras; cadena completa save/get/delete (SQLite en memoria, semántica de sobrescritura por mismo nombre); devuelve null si falta |
| Admin Common | `admin/tests/ExcelExportTest.php` | 4 | 9 | escritura de cabeceras + negrita; aplanado JSON de campos de array; añadido de número de fila por fila; celdas vacías en columnas ausentes (aserciones en memoria con PhpSpreadsheet, sin escribir en disco) |
| Admin Common | `admin/tests/LayuiTest.php` | 5 | 9 | render input name/value; inputNumber fuerza tipo number; escape HTML de label (contra inyección en atributos); render switch con lay-skin; re-ordenamiento con sangrado de html() |

Esta ronda añade 42 casos / 104 aserciones. Todas las aserciones de importes usan `assertSame` con comparación exacta de cadenas (bcmath), sin float.

## Correcciones del entorno de pruebas (código no de negocio)

1. **service/vendor dañado**: `composer.lock` se había actualizado (encryptable v2.0.2→v2.0.3, entre otros paquetes) pero vendor no estaba sincronizado; faltaba guzzle y la suite no arrancaba → `composer install` lo restaura y ambas suites se pueden ejecutar.
2. **Fixture de cifrado de UserModelTest roto**: encryptable v2.0.3 exige clave de 32 bytes (aes-256-gcm por defecto), el fixture antiguo tenía 16 → fallaba. Corrección: el setUp de `service/tests/user/UserModelTest.php` fija clave de 32 bytes + aes-256-gcm, y llama a `Encryption::setFallbackConfig(null)` para reiniciar la caché estática de nivel proceso del paquete — `tests/user/AuthFullChainTest.php` inyecta `service/.env` (cipher=aes-128-ecb, clave de 24 caracteres no base64) en `$_ENV/$_SERVER`, y la caché estática `$resolved` provoca contaminación entre tests: por separado pasa, en ejecución completa falla. Esta corrección da además un entorno coherente a los tests posteriores que dependen de Encryptable.

## Problemas en el código de negocio

Esta ronda no ha encontrado bugs de negocio. Dos semánticas de `PaymentReconcile::compare` fáciles de malinterpretar se verifican según la implementación real y se documentan: el diff es la diferencia bruta de totales (no la diferencia de redondeo por unidad); tras el acarreo de una moneda de cero decimales, el diff del mismatch es la diferencia bruta (p. ej. JPY 1234 vs 1234.5000 → diff -0.5000).

## Resultados completos

| Suite | Casos | Aserciones | Fallos | Errores | Saltados |
|------|------|------|------|------|------|
| service | 672 | 1632 | 0 | 0 | 15 |
| admin | 286 | 962 | 0 | 0 | 1 |

- Comparativa con la línea base: service 661→672 (+11), admin 255→286 (+31); ambas suites 0 failure / 0 error.
- Comprobación de sintaxis: `php -l` pasa en todos los archivos nuevos y modificados.

## Huecos restantes y motivos

| Hueco | Motivo |
|------|------|
| cron/CronRunner, cron/SslCertificateCheck | Contexto de planificación + sondeo real de certificados TLS; coste alto para test unitario |
| command/Migrate*, DbBackupCommand, I18nSyncCommand | Dependen de migraciones MySQL reales/sistema de archivos; requieren entorno de integración |
| admin/common/Auth (getScopeRoleIds/isSuperAdmin) | Dependen de sesión y datos de permisos de BD |
| admin/common/Migration*, Layui::buildTable/buildForm | Dependen de information_schema de BD / estructura completa de tablas |
| controladores ligeros de service/controller (Health/Help/Status/Upload) | Sin lógica de negocio; los valores los da el runtime de webman |
| graphql/GraphqlController | Depende de los helpers `json()`/`config()` de webman y del runtime de Feature Flags; el Schema ya está cubierto por SchemaTest |
| monitor/ResourceMonitor | Depende de Redis + llamadas reales a proveedores; requiere capa de mock o entorno de integración |
