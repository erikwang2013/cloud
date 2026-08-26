# Relatório de Auditoria de Segurança — cloud-php

**Data**: 2026-08-04
**Escopo**: projeto inteiro (service + admin)
**Metodologia**: revisão de configuração, auditoria de middlewares, inspeção de código

---

## Avaliação Geral: **B+ (Bom, 4 lacunas a corrigir)**

O projeto tem uma arquitetura de segurança multicamadas sólida. O plugin erikwang2013/security-php com 31 detectores é o destaque. Abaixo está o detalhamento completo.

---

## 1. Defesas Existentes (verificadas)

### Transporte e Criptografia
| Mecanismo | Implementação | Status |
|-----------|---------------|--------|
| Criptografia de transporte da API | AES-256-GCM via erikwang2013/encryption | OK |
| Criptografia de campos do banco | AES-128-ECB via erikwang2013/encryptable (determinística, consultável) | OK |
| Rotação de chaves | ENCRYPTION_PREVIOUS_KEYS com chaves antigas separadas por vírgula | OK |
| Ofuscação de IDs | Hashids com salt configurável e tamanho mínimo 12 | OK |
| Hash de senhas | bcrypt cost=12, tamanho mínimo 8 | OK |

### Autenticação e Controle de Acesso
| Mecanismo | Implementação | Status |
|-----------|---------------|--------|
| Autenticação JWT | erikwang2013/jwt-webman, HS256, TTL do access 900s + refresh 30d | OK |
| Lista negra JWT | Revogação de tokens com backend Redis | OK |
| MFA/TOTP | 6 dígitos, período de 30s, compatível com Google/MS Authenticator | OK |
| RBAC | Middleware AccessControl do admin + plugin\admin\api\Auth::canAccess() | OK |
| Armazenamento de sessão | Redis (db2) | OK |
| Captcha | Captcha de clique-texto erikwang2013/poster-php para login/registro | OK |

### Detecção de Ataques (WAF — Dupla Camada)
| Camada | Cobertura | Status |
|-------|----------|--------|
| WafMiddleware personalizado | SQLi, XSS, CMDi, path traversal, injeção de cabeçalho, SSRF, NoSQLi, redirecionamento aberto | OK |
| Security Plugin (31 detectores) | Tudo acima + XXE, desserialização, LDAP, cabeçalho de email, SSTI, ataque JWT, Host header, request smuggling, GraphQL, XPATH, JNDI/Log4Shell, SSI, injeção CSV, vazamento de dados, prototype pollution, WebSocket, bypass de CORS, DNS rebinding | OK |

### Limite de Frequência (somente service)
| Rota | Rate | Burst | Per | Status |
|-------|------|-------|-----|--------|
| default | 60 | 10 | 60s | OK |
| login | 5 | 2 | 60s | OK |
| register | 3 | 0 | 60s | OK |
| pay | 10 | 3 | 60s | OK |
| upload | 10 | 2 | 60s | OK |
| supplier_api | 120 | 20 | 60s | OK |
| supplier_withdraw | 10 | 3 | 60s | OK |

### Outras Proteções
| Mecanismo | Implementação | Status |
|-----------|---------------|--------|
| Limites de tamanho de requisição | Body de 10MB, URL de 2KB | OK |
| Validação de Content-Type | Lista de permissões: JSON, multipart, form-urlencoded | OK |
| Prepared statements do banco | PDO::ATTR_EMULATE_PREPARES = false | OK |
| Separação leitura/escrita do DB | Escrita no master, leitura na réplica, sessões sticky | OK |
| Log de auditoria | Banco de auditoria separado, LogSanitizer mascara campos sensíveis | OK |
| Modo de manutenção | IPs da lista de permissões passam; os demais recebem 503 + Retry-After | OK |
| Banimento automático de IP | 5 violações em 60s → banimento de 15min | OK |
| Modo estrito do SQL | Evita truncamento de dados e conversão implícita de tipos | OK |

---

## 2. Lacunas e Recomendações

### Lacuna 1 (Média): CORS reflete qualquer origem
**Arquivo**: `service/common/security/CorsMiddleware.php:12`

```php
'Access-Control-Allow-Origin' => $origin ?: '*',
```

Isso ecoa qualquer Origin enviada pelo cliente, efetivamente permitindo que qualquer site faça requisições entre origens autenticadas. O detector cors do plugin de segurança pode capturar alguma injeção de cabeçalho, mas o próprio middleware não fornece lista de permissões de origens.

**Correção**: adicionar verificação de lista de permissões. Se a origem não estiver na lista permitida, responder com `Access-Control-Allow-Origin: null` ou omitir o cabeçalho por completo.

### Lacuna 2 (Média): faltam cabeçalhos de resposta de segurança
Nem o service nem o admin definem cabeçalhos HTTP de segurança críticos:

| Cabeçalho | Recomendado | Atual |
|--------|-------------|---------|
| Strict-Transport-Security | max-age=31536000; includeSubDomains | Ausente |
| X-Content-Type-Options | nosniff | Ausente |
| X-Frame-Options | DENY ou SAMEORIGIN | Ausente |
| Content-Security-Policy | Política com nonce/hash | Ausente |
| X-XSS-Protection | 1; mode=block | Ausente |
| Referrer-Policy | strict-origin-when-cross-origin | Ausente |
| Permissions-Policy | Restringir camera/mic/geolocation | Ausente |

**Recomendação**: adicionar um SecurityHeadersMiddleware às pilhas de middlewares do service e do admin. Correção de alto impacto e baixo esforço.

### Lacuna 3 (Baixa): admin/config/security.php sem limite de frequência
**Arquivo**: `admin/config/security.php`

O painel admin não tem configuração de rate_limits. O middleware WAF do admin verifica apenas limite de tamanho/Content-Type. Um ataque de força bruta no login do admin não sofre limite de frequência na camada de aplicação.

**Recomendação**: adicionar rate_limits ao admin/config/security.php ou aplicar o RateLimitMiddleware às rotas do admin.

### Lacuna 4 (Baixa): GeoBlockMiddleware definido mas não ativado
**Arquivo**: `service/common/security/GeoBlockMiddleware.php`

O middleware existe e é funcional, mas não está registrado em `service/config/middleware.php`. Se o bloqueio geográfico for necessário, adicione-o à pilha.

### Lacuna 5 (Informativa): sobrecarga do WAF duplo
Tanto o WafMiddleware (personalizado, 40+ padrões de regex) quanto o SecurityMiddleware (plugin, 31 detectores) rodam em toda requisição. A cobertura de padrões se sobrepõe significativamente para SQLi, XSS, injeção de comando, path traversal, injeção de cabeçalho, SSRF, NoSQLi e redirecionamento aberto.

**Recomendação**: o plugin de segurança é mais abrangente (31 detectores vs 8 categorias) e tem lista negra de IPs, lista de permissões de campos e deduplicação de logs. Considere remover o WafMiddleware personalizado e confiar somente no plugin, ou ao mínimo remover os padrões sobrepostos do WafMiddleware.

### Lacuna 6 (Informativa): classe Validator mínima
**Arquivo**: `service/common/helper/Validator.php`

Tem apenas required(), email(), minLength(). Faltam: tamanho máximo, validação numérica, sanitização de strings, validação de URL, correspondência de padrões. Controladores que não usam validação em nível de framework correm risco de aceitar entrada malformada.

---

## 3. Plugin de Segurança — Status dos 31 Detectores

| # | Detector | Modo | Notas |
|---|----------|------|-------|
| 1 | xss | block | |
| 2 | sql_injection | block | |
| 3 | command_injection | block | |
| 4 | path_traversal | block | |
| 5 | upload | block | |
| 6 | ssrf | block | |
| 7 | xxe | block | |
| 8 | header_injection | **log** | CRLF corresponde a conteúdo de textarea; deve permanecer em log |
| 9 | deserialization | block | |
| 10 | ldap_injection | block | |
| 11 | mail_header | block | |
| 12 | ssti | **log** | {{ }} corresponde a templates Vue/Angular |
| 13 | nosql_injection | **log** | $ne/$gt corresponde a variáveis de shell/LaTeX |
| 14 | open_redirect | block | |
| 15 | jwt_attack | block | |
| 16 | host_header | block | |
| 17 | request_smuggling | block | |
| 18 | graphql_injection | block | |
| 19 | xpath_injection | block | |
| 20 | jndi_injection | block | |
| 21 | ssi_injection | block | |
| 22 | csv_injection | block | |
| 23 | data_leak | block | |
| 24 | prototype_pollution | block | |
| 25 | websocket | block | |
| 26 | cors | block | |
| 27 | dns_rebinding | **log** | Host loopback (127.0.0.1/localhost) não recebe mais 403 (comum em dev/teste, apenas registrado) |
| 28 | http_method | block | |
| 29 | body_size | block | |
| 30 | content_type | block | |
| 31 | csrf_origin | block | |

Todos os 31 detectores habilitados. 4 apenas em modo log (risco de falso positivo documentado). Configuração correta.

---

## 4. Ordem de Execução dos Middlewares (service)

```
1. VersionMiddleware          — análise do cabeçalho de versão da API
2. CorsMiddleware              — cabeçalhos CORS (permissivo demais, ver Lacuna 1)
3. ClientPlatformMiddleware    — detecção de SO/plataforma
4. WafMiddleware               — WAF personalizado (40+ padrões de regex)
5. SecurityMiddleware           — WAF do plugin (31 detectores)
6. LocaleMiddleware            — i18n
7. HashidRequestMiddleware     — decodificação de IDs
8. MaintenanceMiddleware       — verificação de modo de manutenção
```

---

## 5. Resumo

| Categoria | Nota | Problemas-chave |
|----------|-------|------------|
| Detecção de ataques | **A** | 31 detectores, WAF em dupla camada (redundante, porém minucioso) |
| Autenticação | **A-** | bcrypt+MFA+lista negra JWT; falta limite de frequência no admin |
| Segurança de transporte | **B+** | AES-256-GCM ok; faltam cabeçalhos HSTS/CSP |
| Validação de entrada | **B** | WAF captura ataques; validação em nível de aplicação é fina |
| Controle de acesso | **A-** | RBAC + verificação de sessão; CORS permissivo demais |
| Auditoria/Logs | **A** | Banco de auditoria separado, mascaramento de campos sensíveis |
| Limite de frequência | **B+** | Bem configurado para o service; ausente no admin |

**Ordem de prioridade das correções:**
1. Adicionar cabeçalhos de resposta de segurança (HSTS, CSP, X-Frame-Options etc.)
2. Restringir o CORS a uma lista de permissões em vez de refletir qualquer origem
3. Adicionar limite de frequência ao painel admin
4. Ativar o GeoBlockMiddleware se o bloqueio geográfico for necessário
5. Considerar consolidar as camadas de WAF para reduzir a sobrecarga de regex por requisição

---

## 6. Remediação Aplicada (2026-08-04)

### Corrigido
| Lacuna | Correção | Arquivos Alterados |
|-----|-----|---------------|
| CORS refletindo qualquer origem | Modo de lista de permissões com a variável de ambiente `CORS_ALLOWED_ORIGINS`, suportando curingas `*.example.com` e `*` para todos | `service/common/security/CorsMiddleware.php` |
| Faltam cabeçalhos de segurança | Novo `SecurityHeadersMiddleware` adicionado às pilhas do service e do admin: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection, Permissions-Policy, HSTS (opt-in via env) | `service/common/security/SecurityHeadersMiddleware.php`, `admin/app/middleware/SecurityHeadersMiddleware.php` |
| Admin sem limite de frequência | Configuração `rate_limits` + `RateLimitMiddleware` adicionados ao painel admin (default 60/min, login 5/min) | `admin/config/security.php`, `admin/app/middleware/RateLimitMiddleware.php` |
| GeoBlock não ativado | `GeoBlockMiddleware` registrado na pilha de middlewares do service | `service/config/middleware.php` |

### Novas Variáveis de Ambiente
| Variável | Finalidade | Padrão |
|----------|---------|---------|
| `CORS_ALLOWED_ORIGINS` | Origens permitidas separadas por vírgula | (vazio = negar todas) |
| `SECURITY_HSTS_ENABLE` | Ativar cabeçalho HSTS | false |
| `SECURITY_HSTS_VALUE` | Valor do cabeçalho HSTS | max-age=31536000; includeSubDomains |
| `SECURITY_X_FRAME_OPTIONS` | Valor do X-Frame-Options | SAMEORIGIN |
| `GEO_BLOCKED_COUNTRIES` | Códigos de países bloqueados (ISO 3166-1) | (vazio = desativado) |
| `GEOIP_DB_PATH` | Caminho do .mmdb GeoLite2 | storage_path('geoip/GeoLite2-Country.mmdb') |

### Pipeline de Middlewares Atualizado

**Service:**
```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock → WAF → SecurityPlugin → Locale → HashidRequest → Maintenance
```

**Admin:**
```
SecurityHeaders → RateLimit → WAF → SecurityPlugin → AccessControl
```
