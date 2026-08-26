# Informe de revisión exhaustiva de CloudPlatform

**Fecha:** 2026-08-04  
**Alcance de la revisión:** proyecto completo (calidad de código, seguridad, configuración del ecosistema, despliegue, documentación)  
**Rama:** main  
**Último commit:** e321bcc — los 3 problemas restantes corregidos en esta ronda

---

## 1. Resumen del proyecto

| Dimensión | Estado |
|------|------|
| Tipo de proyecto | PHP 8.2+ / webman, plataforma de comercio de recursos en la nube |
| Tamaño del código | service (15 módulos, 295 tests) + admin (53 controladores, 67 tests) + Flutter + HarmonyOS |
| Base de datos | MySQL 8.0, 46 tablas (7 wa_* + 39 erik_*) |
| Forma de despliegue | Asistente de instalación con un clic / Docker Compose / manual |
| Documentación | 10 documentos + 11 diagramas de arquitectura SVG |

---

## 2. Problemas encontrados

### CRITICAL (graves)

#### C1. El despliegue Docker carece del panel de administración

**Problema:** el Dockerfile solo copia el directorio `service/` y docker-compose solo hace proxy del puerto 8787. El panel de administración (admin panel, puerto 8788) no está dockerizado en absoluto.

```dockerfile
# docker/Dockerfile — actualmente solo maneja service
COPY service/ /app/
```

**Impacto:** los usuarios que despliegan con Docker no pueden usar el panel de administración. No coincide con el "arranque con un clic con Docker Compose" que afirma el README.

**Sugerencia:** añadir un Dockerfile para `admin/` o usar una construcción multi-etapa para desplegar ambos servicios.

---

#### C2. Los puertos de la base de datos Docker se exponen al host

**Problema:** en docker-compose.yml los puertos de MySQL (3306) y Redis (6379) se mapean directamente al host:

```yaml
mysql:
  ports:
    - "3306:3306"    # expuesto a la red pública
redis:
  ports:
    - "6379:6379"    # expuesto a la red pública
```

**Impacto:** si el servidor tiene IP pública, la base de datos queda expuesta. Es una fuente habitual de incidentes de seguridad.

**Sugerencia:** eliminar el mapeo `ports` o al menos vincularlo a `127.0.0.1:3306:3306`. La red interna de Docker ya permite la comunicación.

---

#### C3. Falta el archivo LICENSE

**Problema:** el README declara "Simplificada — Licencia MIT", pero no existe un archivo `LICENSE` en la raíz del proyecto.

**Impacto:** faltan los requisitos legales del código abierto. GitHub no reconocerá el tipo de licencia del proyecto.

**Sugerencia:** crear un archivo `LICENSE` en la raíz con el texto estándar de la licencia MIT.

---

### HIGH (prioridad alta)

#### H1. Archivos SQL duplicados que causan confusión

**Problema:** existen 3 archivos DDL SQL en el proyecto:

| Archivo | Líneas | Tablas | Estado |
|------|------|------|------|
| `install.sql` (raíz) | 739 | 46 | **En uso actual** |
| `admin/install.sql` | 152 | 7 (solo wa_*) | Versión antigua, sin eliminar |
| `docs/database.sql` | 629 | 39 (solo erik_*) | Versión antigua, sin eliminar |

**Impacto:** los mantenedores pueden editar el archivo equivocado y provocar desincronización.

**Sugerencia:** eliminar `admin/install.sql` y `docs/database.sql`, o añadir en la cabecera de ambos un aviso visible de obsolescencia que remita a `install.sql`.

---

#### H2. El asistente de instalación no crea la base de auditoría

**Problema:** `install/index.php` genera `service/.env` incluyendo la configuración de la base de auditoría:
```ini
AUDIT_DB_DATABASE=cloud_platform_audit
```
Pero el asistente de instalación nunca crea esa base de datos. Si la aplicación intenta escribir registros de auditoría tras el arranque, fallará por `Unknown database`.

**Impacto:** la función de auditoría no está disponible; se ve afectado el cumplimiento normativo.

**Sugerencia:** en la ejecución del paso 4 de instalación, añadir `CREATE DATABASE IF NOT EXISTS cloud_platform_audit`.

---

#### H3. Docker carece del servicio Elasticsearch

**Problema:** docker-compose.yml solo tiene tres servicios: app + mysql + redis. La pila tecnológica del README lista explícitamente Elasticsearch 8.x como componente necesario.

**Impacto:** la búsqueda de texto completo (productos, usuarios, pedidos, tickets) no funciona en absoluto en el despliegue Docker.

**Sugerencia:** añadir un servicio Elasticsearch a docker-compose.yml.

---

#### H4. Al Dockerfile le faltan extensiones de PHP

**Problema:** las extensiones PHP que instala el Dockerfile son: `gd pdo_mysql zip bcmath redis`. Pero la comprobación del entorno exige 9 extensiones; faltan:
- `intl` (internacionalización de PHP)
- `xml` (parseo XML)
- `fileinfo` (detección de tipo de archivo)

**Impacto:** algunas funciones pueden fallar silenciosamente en el entorno Docker.

**Sugerencia:** añadir las extensiones que faltan: `docker-php-ext-install intl xml fileinfo`

---

### MEDIUM (prioridad media)

#### M1. La configuración de admin/.env.example es poco detallada

**Problema:** service/.env.example (146 líneas) frente a admin/.env.example (64 líneas); el segundo tiene claramente menos comentarios y opciones.

**Sugerencia:** completar los comentarios de admin/.env.example y al menos indicar qué campos deben coincidir con los de service.

---

#### M2. HASHIDS_SALT codificado en .env.example

**Problema:** ambos archivos `.env.example` contienen:
```ini
HASHIDS_SALT=cloud-platform-hashids
```
Si el operador hace `cp .env.example .env` sin modificar este valor, todas las instancias compartirán la misma sal.

**Sugerencia:** usar un marcador de posición en `.env.example` y destacar en los comentarios que "se debe generar un valor aleatorio único".

---

#### M3. Enlace no válido en la página de éxito del asistente de instalación

**Problema:** el enlace de la página de instalación completada usa `href="#"`, sin URL real clicable.

**Sugerencia:** al menos mostrar la URL/puertos concretos, junto con el comando de arranque.

---

#### M4. Docker no incluye el asistente de instalación

**Problema:** el Dockerfile no copia `install.php` ni el directorio `install/`. Los usuarios de Docker no pueden usar el asistente de instalación con un clic.

**Sugerencia:** documentar claramente que en el despliegue Docker la configuración debe ser manual, o integrar el asistente en la imagen.

---

#### M5. Variables de entorno incompletas en Docker Compose

**Problema:** el bloque `environment` de docker-compose.yml carece de varias configuraciones necesarias: clave JWT, sal de Hashids, clave de cifrado, SMTP, Stripe, etc.

**Sugerencia:** completar la lista de variables de entorno o referenciar el archivo `.env`.

---

### LOW (prioridad baja)

#### L1. Sección Docker pobre en la documentación

El despliegue Docker en el README ocupa solo unas líneas y no explica cómo configurar variables de entorno, inicializar la base de datos ni acceder al panel de administración.

**Sugerencia:** completar la documentación de despliegue Docker.

---

#### L2. Falta .editorconfig

**Problema:** el proyecto no tiene archivo `.editorconfig`. En un proyecto con múltiples colaboradores, la configuración uniforme de indentación y saltos de línea es importante.

**Sugerencia:** añadir un `.editorconfig` estándar que fije indentación de 4 espacios para PHP, UTF-8 y saltos LF.

---

#### L3. Los valores por defecto codificados en el código se pueden centralizar

**Problema:** `install/index.php` tiene varios valores por defecto codificados (host de la base de datos, puerto, nombre de la base, usuario administrador); al modificarlos es fácil olvidar alguno.

**Sugerencia:** extraerlos como definiciones de constantes en la cabecera del archivo.

---

## 3. Evaluación de la integridad de la configuración del ecosistema

### Cobertura de variables .env

| Dominio de configuración | service | admin | .env.example |
|--------|:---:|:---:|:---:|
| Conexión a la base de datos | ✓ | ✓ | ✓ |
| Base de auditoría | ✓ | N/A | ✓ |
| Redis | ✓ | ✓ | ✓ |
| Autenticación JWT | ✓ | N/A | ✓ |
| Hashids | ✓ | ✓ | ✓ |
| Snowflake | ✓ | ✓ | ✓ |
| Cifrado de transporte (AES-256-GCM) | ✓ | ✓ | ✓ |
| Cifrado de campos (AES-128-ECB) | ✓ | ✓ | ✓ |
| Correo SMTP | ✓ | N/A | ✓ |
| Pago Stripe | ✓ | N/A | ✓ |
| Elasticsearch | ✓ | ✓ | ✓ |
| SMS Twilio | ✓ | N/A | ✓ |
| Push Firebase | ✓ | N/A | ✓ |
| Captcha de clic | ✓ | N/A | ✓ |
| Monitorización Sentry | ✓ | N/A | ✓ |
| Feature Flags | ✓ | N/A | ✓ |
| Rotación de claves | ✓ | N/A | ✓ |
| **Evaluación** | **Completa** | **Completa** | **Completa** |

### Coherencia de las claves compartidas generadas por el asistente de instalación

| Clave | service | admin | Coherente |
|------|:---:|:---:|:---:|
| ENCRYPTION_KEY | ✓ | ✓ | ✓ |
| ENCRYPTION_MASTER_KEY | ✓ | ✓ | ✓ |
| HASHIDS_SALT | ✓ | ✓ | ✓ |
| **Evaluación** | **Aprobado** | **Aprobado** | **Aprobado** |

---

## 4. Evaluación de seguridad

| Punto de control | Estado | Descripción |
|--------|:--:|------|
| Protección CSRF | ✓ | Generación de token + verificación hash_equals |
| Seguridad de sesión | ✓ | HttpOnly + SameSite=Strict + strict_mode |
| Validación de entrada | ✓ | Validación con regex de nombres de BD, comprobación de rango de puertos |
| Fortaleza de contraseña | ✓ | Mínimo 8 caracteres + letras + números/caracteres especiales |
| Hash de contraseñas | ✓ | password_hash(PASSWORD_DEFAULT) |
| Generación de claves | ✓ | openssl rand o random_bytes |
| Protección contra inyección SQL | ✓ | Sentencias preparadas PDO |
| Desinfección de errores | ✓ | Los errores detallados solo van a error_log; el usuario ve mensajes genéricos |
| Protección XSS | ✓ | Escape de salida con htmlspecialchars() |
| Protección contra reinstalación | ✓ | Detección de tablas existentes + archivo .env |
| Forzado de pasos | ✓ | session max_step impide saltarse pasos |
| Transacciones | ✓ | beginTransaction/commit/rollBack |
| Exposición de puertos Docker | ✗ | MySQL:3306 / Redis:6379 mapeados al host |
| Creación de la base de auditoría | ✗ | El asistente no crea la base _audit |
| **Puntuación global** | **A-** | Medidas de seguridad nucleares correctas; la configuración Docker necesita mejoras |

---

## 5. Integridad de SQL

| Punto de control | Resultado |
|--------|------|
| Total de tablas | 46 (7 wa_* + 39 erik_*) ✓ |
| Motor | Todo InnoDB ✓ |
| Conjunto de caracteres | Todo utf8mb4 ✓ |
| Tipo de clave primaria | BIGINT UNSIGNED (no autoincremental) ✓ |
| CREATE IF NOT EXISTS | Usado en todos ✓ |
| Sentencias destructivas | Ninguna (sin DROP TABLE) ✓ |
| Archivos SQL antiguos | Siguen existiendo 2 archivos antiguos; hay que limpiarlos ⚠ |

---

## 6. Evaluación de cobertura de pruebas

| Suite de pruebas | Framework | N.º de tests | Assertions |
|----------|------|:---:|:---:|
| admin/tests/ | PHPUnit 11 | 67 | ~67 |
| service/tests/ | PHPUnit 10 | 295 | 455 |
| CI/CD | GitHub Actions | 3 jobs | PHP 8.2 + 8.3 |

**Evaluación:** número de pruebas suficiente (362 tests), el CI/CD cubre la comprobación de sintaxis en dos versiones de PHP + pruebas unitarias en ambos extremos.

---

## 7. Integridad de la documentación

| Documento | Contenido | Estado |
|------|------|:--:|
| README.md | Resumen del proyecto, arquitectura, inicio rápido, visión general de la API | ✓ |
| README_EN.md | README en inglés | ✓ |
| docs/architecture.md | Diseño de la arquitectura del sistema | ✓ |
| docs/features.md | Diseño funcional de 12 módulos | ✓ |
| docs/api-reference.md | Referencia de más de 135 endpoints | ✓ |
| docs/admin-design.md | Diseño del panel de administración | ✓ |
| docs/supplier-api.md | API de proveedores | ✓ |
| docs/deployment.md | Lista de verificación de despliegue | ✓ |
| docs/editions.md | Comparativa de versiones | ✓ |
| docs/diagrams/ (11 SVG) | Arquitectura/seguridad/flujos de negocio | ✓ |
| Archivo LICENSE | **Falta** | ✗ |

---

## 8. Resumen de sugerencias de corrección

### Primera prioridad (corregir antes de la próxima publicación)

| # | Problema | Nivel |
|---|------|:--:|
| 1 | Crear el archivo LICENSE (MIT) | CRITICAL |
| 2 | Eliminar los archivos SQL antiguos (admin/install.sql, docs/database.sql) | HIGH |
| 3 | No exponer los puertos Docker de MySQL/Redis al host | CRITICAL |
| 4 | El asistente de instalación crea la base de auditoría `_audit` | HIGH |

### Segunda prioridad (corregir a corto plazo)

| # | Problema | Nivel |
|---|------|:--:|
| 5 | Soporte Docker para el panel de administración (admin panel) | CRITICAL |
| 6 | Añadir el servicio Elasticsearch a Docker Compose | HIGH |
| 7 | Completar las extensiones PHP del Dockerfile (intl, xml, fileinfo) | HIGH |
| 8 | Usar marcador de posición para HASHIDS_SALT en .env.example | MEDIUM |

### Tercera prioridad (mejora continua)

| # | Problema | Nivel |
|---|------|:--:|
| 9 | Completar la documentación de despliegue Docker | LOW |
| 10 | Añadir .editorconfig | LOW |
| 11 | Limpiar los valores por defecto codificados en el código | LOW |
| 12 | Unificar las opciones de la función de generación de .env | LOW |

---

## 9. Conclusión

La calidad general del proyecto es buena; tras la ronda anterior de auditoría, todos los problemas de seguridad del asistente de instalación principal quedaron corregidos. La organización del código es clara, con alto grado de modularidad y documentación completa. Los problemas principales se concentran en la **configuración incompleta del despliegue Docker** — faltan el panel de administración, el servicio de búsqueda y extensiones de PHP, y existe el riesgo de seguridad de exponer los puertos de la base de datos.

**Calificación global: B+** — funcionalidad completa y núcleo de seguridad correcto; la configuración del ecosistema Docker necesita completarse.
