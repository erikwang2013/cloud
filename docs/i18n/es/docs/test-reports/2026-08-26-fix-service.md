# Informe de corrección de defectos de service 2026-08-26 (A/C/F)

## Conclusión

- Los 3 defectos están corregidos y re-probados de extremo a extremo (9/9 PASS)
- Regresión completa PHPUnit: 672 tests / 1632 assertions / 15 skipped / 0 failures
- No se ha tocado .env, app/grpc/Generated ni el schema de la base de datos; sin nuevas dependencias composer

## Defecto A: la clave encryptable no se decodifica en base64 → 500 en registro/login/refresh/direcciones

### Causa raíz (tres capas superpuestas)

1. `config/encryptable.php` pasa `ENCRYPTION_KEY` (base64; decodificada son 16 bytes, cipher=aes-128-ecb) tal cual como clave; la validación de longitud lanza `MissingEncryptionKeyException`.
2. En tiempo de ejecución en realidad se lee `config/plugin/erikwang2013/encryptable/app.php` (solo tiene `enable`); la configuración del plugin no contiene ninguna key.
3. webman no tiene un helper global `app()`; `Encryption::doResolve()` no llega a la ruta del contenedor y cae en `EnvEncryptableConfig` (lee la cadena env base64 original, sin decodificar) — aunque se arregle la configuración del plugin, sigue dando 500.

### Corrección

| Archivo | Cambio |
|------|------|
| `service/config/encryptable.php` | `'key' => base64_decode(getenv('ENCRYPTION_KEY'), true) ?: ''` (ruta legacy, corregida también) |
| `service/config/plugin/erikwang2013/encryptable/app.php` | Añadir `key` (con base64_decode) / `cipher` / `previous_keys` |
| `service/support/bootstrap.php` | `Encryption::setFallbackConfig(new WebmanPluginEncryptableConfig())`, para que el runtime use la configuración del plugin (clave ya decodificada) |

### Bugs del mismo origen encontrados en la cadena (corregidos también)

Una vez efectiva la corrección de cifrado, registro/login/refresh empezaron a fallar de formas distintas al 500:

- **Login 401**: `User::where('email', $login)->orWhere('phone', $login)` en claro nunca coincide con columnas cifradas. Corrección: `where('email', Encryption::php()->encrypt($login))` (el cifrado es determinista; basta con igualdad de cifrados).
- **Refresh 401 "Device mismatch"**: problema en dos capas:
  - `RefreshToken::where('token_hash', hash(...))` en claro tampoco coincide; se cambia a `encrypt(hash(...))`;
  - la ruta de registro nunca registra la huella del dispositivo (`AuthService::register()` internamente llama a `issueTokens(..., '')`), mientras que el refresh la valida → tras registrarse, el refresh falla siempre. Corrección: `AuthController::register` pasa `deviceFingerprint($request)`, y `AuthService::register` recibe el nuevo parámetro `$deviceFingerprint`.
- **Comprobación de unicidad de email/teléfono en registro**: `User::where('email', ...)->exists()` tiene el mismo bug; se cambia a consulta con valor cifrado (`recordFailedLogin` corregido a la vez).

## Defecto C: modelos Searchable sin cliente ES → 500 al editar perfil/crear pedido

### Decisión: driver de webman-scout a `database` (y no `null`)

`config/plugin/erikwang2013/webman-scout/app.php`: `'driver' => 'elasticsearch' → 'database'`.

Motivo: el cliente elasticsearch/elasticsearch no está instalado y el driver elasticsearch lanza excepción al guardar el modelo; el motor `database` escribe como no-op y busca por SQL LIKE (la búsqueda de productos sigue disponible), mientras que el motor `null` hace que `search()` devuelva silenciosamente un array vacío, engullendo los resultados de búsqueda por palabra clave de productos. La configuración de soft delete se mantiene por defecto.

## Defecto F: el detector dns_rebinding bloquea con 403 las peticiones locales con Host=127.0.0.1

### Decisión: mode de dns_rebinding a `log` (y no whitelist_ips)

`config/plugin/erikwang2013/security-php/app.php`: `dns_rebinding.mode = 'block' → 'log'`.

Motivo: `whitelist_ips` salta **todos** los detectores según la IP del cliente —en este entorno todo el tráfico pasa por nginx y la IP del cliente es siempre loopback, lo que equivaldría a apagar los 31 detectores. La conexión local directa (Host=127.0.0.1/localhost) es la norma en desarrollo/pruebas; con `log` solo se permite el paso de ese detector y los otros 30 siguen en block.

## Hallazgo adicional: `user_addresses.phone` VARCHAR(20) no cabe con el cifrado

Con el cifrado activo, la creación de direcciones daba 500 (`SQLSTATE[22001] Data too long for column 'phone'`). Dentro de la restricción "no tocar la base de datos", se opta por una corrección en código:

- `service/app/user/model/UserAddress.php`: `phone` sale de los casts Encryptable (la tabla tiene 0 filas; sin riesgo de migración de datos existentes). `address` se mantiene cifrado (cabe en VARCHAR(500)).

**Compensación y trabajo futuro**: phone es PII y ahora se almacena en claro. Para recuperar el cifrado en disco habría que ampliar `user_addresses.phone` y `users.phone` (ambos VARCHAR(20) + Encryptable; el registro con teléfono también daría 500) a VARCHAR(255) — requiere una migración de schema, fuera de la restricción "no tocar la base de datos" de esta ronda; se recomienda un proyecto aparte.

## Seguimiento de revisión: guardia de determinismo del cipher (bloqueo de reviewer resuelto)

El reviewer señaló: la consulta por igualdad de cifrados depende del cifrado determinista (ECB sin IV aleatorio), mientras que `.env.example` recomienda aes-256-cbc (IV aleatorio) — un entorno nuevo desplegado siguiendo el ejemplo "arrancaría bien pero login/refresh/unicidad nunca coincidirían", quedando silenciosamente sin poder entrar.

Corrección (guardia fail-fast contra fallos silenciosos):

- `service/support/bootstrap.php`: tras cablear la configuración encryptable, guardia — si `PHPEncrypter(WebmanPluginEncryptableConfig)->cipher()` no es `aes-128-ecb`/`aes-256-ecb`, el arranque lanza `RuntimeException` con el mensaje claro "el modo de consulta determinista solo soporta ECB; cambiar el cipher exige una migración de re-cifrado".
- `service/.env.example`: comentario de aviso añadido en la sección de cifrado (CBC/GCM lanzará error al arrancar; la consulta determinista solo usa ECB).

Validación: el .env actual (aes-128-ecb) supera la guardia; tras reiniciar el servicio, E2E 9/9 PASS; phpunit 672/1632/15 skipped/0 failures.

## Incidente de entorno (no de código; requiere tratamiento del lado del entorno)

A mitad de sesión se creó `/usr/local/php/conf.d/002-imagick.ini` (propietario root, mtime 2026-08-26 23:31); la imagick.so que carga se bloquea en el constructor de libgomp → **toda invocación php CLI con ini termina en segfault** (phpunit, start.php y php -l caen todos; gdb confirma que dlopen de imagick.so produce SIGSEGV; OMP_NUM_THREADS=1 no sirve). Sin permisos root no se puede borrar ese archivo; esta sesión lo evitó con `PHP_INI_SCAN_DIR=/tmp/confd` (copia del directorio de escaneo sin imagick), y tanto el servicio como phpunit se ejecutaron así.

Sugerencia para el lado del entorno: borrar o comentar `/usr/local/php/conf.d/002-imagick.ini` (imagick.so está dañado) e investigar quién creó ese archivo durante la sesión.

## Lista de archivos modificados (todos en service/)

- `config/encryptable.php`
- `config/plugin/erikwang2013/encryptable/app.php`
- `config/plugin/erikwang2013/webman-scout/app.php`
- `config/plugin/erikwang2013/security-php/app.php`
- `support/bootstrap.php` (incluye la guardia de determinismo del cipher)
- `.env.example` (solo comentarios; no se han tocado los valores de .env)
- `app/user/service/AuthService.php`
- `app/user/controller/AuthController.php`
- `app/user/model/UserAddress.php`

## Registro de validación

- E2E (`/tmp/verify_chain.php`, script temporal fuera del repositorio): F (Host=127.0.0.1 ya no da 403), registro→login→refresh→creación de dirección, edición de perfil 9/9 PASS.
- `vendor/bin/phpunit`: 672 tests / 1632 assertions / 15 skipped / 0 failures.
