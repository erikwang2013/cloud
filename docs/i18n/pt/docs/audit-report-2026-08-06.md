# Relatório Geral de Auditoria CloudPlatform

**Data**: 2026-08-06
**Escopo da auditoria**: service completo (app / common / config / tests) + configuração do ecossistema + defesas de segurança
**Método**: suíte de testes PHPUnit, verificação completa de sintaxe PHP, auditoria de rotas/middlewares, revisão de código das novas funcionalidades OAuth, verificação de consistência de variáveis de ambiente e configuração, auditoria de segurança de dependências, smoke test

---

## 1. Conclusão Geral

| Dimensão | Conclusão |
|------|------|
| Testes | **314 itens, todos aprovados** (494 assertions, após corrigir 2 bugs) |
| Sintaxe | 287 arquivos PHP com 0 erros de sintaxe |
| Segurança de dependências | composer audit sem vulnerabilidades conhecidas; 1 pacote obsoleto (doctrine/annotations) |
| Arquitetura de segurança | Defesas em múltiplas camadas completas (WAF de dois motores, lista de permissões CORS, criptografia de transporte, criptografia de campos, bcrypt cost=12, lista negra JWT, logs de auditoria) |
| Problemas críticos | **1 P0 (id_token da Apple sem verificação de assinatura → possibilidade de takeover de contas), 4 P1** |
| Configuração do ecossistema | **.env.example com 31 variáveis em uso ausentes**, incluindo todas as credenciais OAuth; canais de notificação são implementação de placeholder |

---

## 2. Resultados dos Testes

```
OK (314 tests, 494 assertions)
```

### Os 2 bugs corrigidos nesta rodada

| ID | Arquivo | Problema | Correção |
|----|------|------|------|
| B1 | `service/common/captcha/CaptchaService.php:31` | Lê `$result['extra']['targets']`, mas a biblioteca retorna `extra.texts` → `target_count` sempre 0 | Alterado para `extra.texts` |
| B2 | `vendor/erikwang2013/poster-php/src/Captcha/ClickCaptcha.php:17` | A biblioteca usa `targetCount = 5` por padrão, contradizendo o contrato do próprio README da biblioteca (medium=3 alvos) → 3 testes de Captcha falham | Valor padrão 5 → 3 |

> B2 é um bug da biblioteca vendored (vendor/ é rastreado pelo git, a correção é persistente). Recomenda-se também enviar a correção ao repositório upstream.

---

## 3. Problemas Críticos de Segurança (P0 / P1)

### P0-1. `id_token` da Apple sem verificação de assinatura — takeover direto de conta
**Arquivo**: `service/app/user/service/OAuthService.php:180-192` (`appleProfile()`)

```php
$parts  = explode('.', $tokenData['id_token']);
$claims = json_decode(base64_decode($parts[1]), true);   // 仅 base64 解码，无签名/iss/aud/exp 校验
```

Um atacante pode construir seu próprio `id_token` para forjar qualquer email e concluir o login OAuth. `resolveUser()` faz a correspondência por email com usuários existentes e emite o token diretamente → **takeover de qualquer conta**.

**Correção**: usar o JWKS da Apple (`https://appleid.apple.com/auth/keys`) + `Firebase\JWT\JWT::decode($idToken, $keys, ['ES256'])` para verificar a assinatura, e validar `iss=appleid.apple.com`, `aud=client_id`, `exp` e `nonce`.

### P1-1. Login OAuth sem validar `email_verified`
**Arquivo**: `OAuthService.php:163-178, 282-303`

Google/Facebook/Microsoft/LinkedIn retornam o campo `email_verified`, mas o código o ignora completamente. Um usuário com email não verificado no provedor pode usar esse email para vincular/tomar conta de um usuário já registrado. O caminho do GitHub já valida `verified` (correto); os demais provedores precisam de validação unificada.

### P1-2. Middleware de limite de frequência existe mas nunca foi montado — documentação divergente da implementação
**Arquivos**: `common/Security/RateLimitMiddleware.php` + `config/security.php` + `config/route.php`

- `security.php` já configura regras como login=5/min, register=3/min
- O `RateLimitMiddleware` **não é referenciado por nenhuma rota** (grep no repositório inteiro só encontra a própria classe)
- `docs/features.md` afirma que o login tem "rate limit 5 req/min" e o registro "rate limit 3 req/min" — na prática, não existe
- Relatório de auditoria anterior (`security-audit-2026-08-04.md`) marcou esse item como OK, verificando apenas a configuração e não a montagem; corrigido nesta rodada

**Impacto**: endpoints públicos como login/registro/forgot-password/reset-password/recovery codes/captcha podem ser atacados por força bruta sem limite (o login só tem bloqueio per-account, que não impede credential stuffing nem abuso em nível de IP).

**Correção**: montar o `RateLimitMiddleware` nas rotas públicas `/api/auth/*`, `/api/captcha/*` etc. (pode ser montado no grupo global `''`, diferenciando pelo parâmetro `route`).

### P1-3. TOTP 2FA não é obrigatório no fluxo de login
**Arquivos**: `AuthService.php:64-97` (`login()`) + `AuthController.php` + `config/features.php`

`user->totp_enabled` só é verificado em `totpVerify/totpDisable/totpRecoveryCodes`; **`login()` nunca o valida**. Usuários com 2FA ativado ainda obtêm um access token válido apenas com a senha — o 2FA é inútil (`FEATURE_TOTP` ativado por padrão).

**Correção**: no login, se `totp_enabled`, emitir um token temporário e exigir a validação do TOTP para emitir o token definitivo (ou exigir o parâmetro do código TOTP).

### P1-4. Canais de notificação são implementação de placeholder — verificação de email/reset de senha indisponíveis em produção
**Arquivos**: `app/Notification/Queue/EmailSender.php`, `SmsSender.php`, `PushSender.php`

Os três consumidores apenas simulam o envio com `error_log()` e registram `send_status` como `sent`. Consequências:
- **Fluxo de forgot-password quebrado**: `AuthController::forgotPassword()` gera o código e "envia" o email, mas o email nunca chega → o usuário não consegue redefinir a senha sozinho
- Verificação de email no registro e alertas de login de novo IP igualmente inoperantes
- As 7 variáveis `SMTP_*`/`MAIL_FROM_*` do `.env.example` não são lidas por nenhum código (configuração morta)

**Correção**: integrar envio real de email (PHPMailer/SendGrid SDK), remover a marcação enganosa de `sent`; ou marcar explicitamente como funcionalidade não concluída e remover as promessas relacionadas da documentação.

---

## 4. Problemas de Segurança (P2)

| ID | Arquivo | Problema |
|----|------|------|
| P2-1 | `app/Controller/UploadController.php:23` | O parâmetro `type` é concatenado ao caminho `uploads/{$type}/...` sem validação por lista de permissões → **path traversal** pode escrever fora do diretório de uploads (nomes de arquivo aleatórios, impossível sobrescrever, mas pode poluir o sistema de arquivos); recomenda-se restringir type a uma lista de permissões enumerada e adicionar proteção `index.php`/`.htaccess` no diretório de armazenamento |
| P2-2 | Idem | Valida apenas a extensão, sem detecção de conteúdo MIME (arquivos polyglot podem ser explorados via cache/forward); recomenda-se validar o MIME real com `finfo` |
| P2-3 | `AuthController.php:131-158` | Código de 6 dígitos para reset de senha válido por 600s, sem limite de tentativas → é possível enumerar 1 milhão de combinações por força bruta em 10 minutos; `forgotPassword` sem limite de frequência → bombardeio de emails |
| P2-4 | `AuthController.php:333-348` | Gerar/visualizar recovery codes do TOTP exige apenas login, sem confirmação de senha; deveria usar `ConfirmationMiddleware` |
| P2-5 | `common/Auth/Middleware/AuthMiddleware.php:31` | A verificação manual da lista negra usa a chave `jwt_blacklist:{sha256(token)}`, incompatível com o formato `jwt_blacklist:{jti}` da biblioteca → código morto (a proteção real é feita pelo `decode()` da biblioteca, eficaz mas redundante); recomenda-se remover ou usar a interface da biblioteca |
| P2-6 | `OAuthService.php:67-94` | O parâmetro `redirect` do `authorizeUrl` é salvo no state mas nunca usado (parâmetro morto); state não vinculado ao provider; fluxo OAuth inteiro sem nonce (provedores OIDC, defesa em profundidade ausente, corrigir junto com P0-1) |
| P2-7 | `OAuthService.php:31-37, 236-238` | A API v2 do X (Twitter) `userinfo` não retorna email → o login do X falha sempre com "Email not provided"; defeito funcional, requer nota na documentação ou integração com o endpoint `/2/email` |
| P2-8 | `AuthController.php:436-442` | `deviceFingerprint` usa `strrpos($ip, '.')` para truncar o segmento IPv4; clientes IPv6 degeneram para string vazia → fingerprint fraco; recomenda-se usar os primeiros 64 bits ou hash do IP inteiro |

---

## 5. Completude da Configuração do Ecossistema

### 5.1 Variáveis ausentes no .env.example (referenciadas via `getenv()` no código mas não definidas) — 31

| Categoria | Variáveis |
|------|------|
| **Credenciais OAuth (novo recurso, completamente sem documentação)** | `GOOGLE/APPLE/FACEBOOK/X/MICROSOFT/LINKEDIN/GITHUB_OAUTH_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT_URI` (21) |
| **Específicas da Apple** | `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH` |
| **Funcionalidade crítica** | `APP_URL` (o link do email de verificação depende dela; ausente → links de email incorretos), `APP_ENV`, `APP_VERSION` |
| **Segurança** | `INTERNAL_MONITOR_TOKEN` (proteção dos endpoints `/health/*`), `MAINTENANCE_MODE`, `MAINTENANCE_ALLOWED_IPS`, `WEBHOOK_SECRET`, `JWT_LEEWAY` |
| **Nuvem/armazenamento** | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, `DB_READ_HOST` |
| **Feature flags (8)** | `FEATURE_SSL_PRODUCT`, `FEATURE_OBJECT_STORAGE`, `FEATURE_USAGE_BILLING`, `FEATURE_PROMETHEUS`, `FEATURE_CDN_PRODUCT`, `FEATURE_SUPPLIER_RATING`, `FEATURE_AFFILIATE`, `FEATURE_GRAPHQL` |
| **Outros** | `METRICS_PORT`, `WS_PORT`, `GEOIP_DB_PATH` (apenas comentado no .env.example), `SSL_STAGING`, `HASHIDS_ALPHABET`, `POSTER_IMAGE_DRIVER`, `EXCHANGE_RATE_API_URL`, `COUNTRY_SEASON_DEFAULT` |

### 5.2 Definidas no .env.example mas não usadas no código — 7

`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (envio de email não implementado, ver P1-4)

### 5.3 Cobertura i18n inconsistente

| Idioma | messages.php | billing | health | storage |
|------|:---:|:---:|:---:|:---:|
| en-US / zh-CN | 129 / 129 | 10 / 16 | 8 / 16 | 9 / 16 |
| ja-JP | 63 | 10 | 8 | 9 |
| ko-KR | 51 | 10 | 8 | 9 |
| fr-FR / de-DE / es-ES | 44 | 10 | 8 | 9 |

- Idiomas não chineses/ingleses têm mais da metade das chaves de tradução ausentes; o zh-CN tem 6-8 chaves a mais que o en-US em billing/health/storage (direção de sincronização invertida)
- **Todas as chaves de tradução relacionadas a OAuth estão ausentes** (mensagens de erro em inglês hardcoded)

### 5.4 Outros problemas do ecossistema

| ID | Problema |
|----|------|
| E1 | `service/composer.lock` está ignorado pelo `.gitignore` e não foi commitado — dependências da aplicação sem versões travadas, implantação não reproduzível (risco de implantação) |
| E2 | `service/.phpunit.cache/` aparece no git status (não ignorado) |
| E3 | Porta 8787 em conflito com outro projeto local (erp-php); cloud-php não inicia localmente (confirmado que 8787 está ocupada pelo WorkerMan do erp-php) |
| E4 | Funcionalidades de rate limit/email declaradas no `docs/features.md` não correspondem à realidade (ver P1-2 / P1-4); documentação precisa ser corrigida |
| E5 | A dependência `doctrine/annotations` está obsoleta (aviso do composer audit); recomenda-se avaliar a remoção |

---

## 6. Recomendações de Otimização (não bloqueantes)

1. **Criação de serviços via DI**: o construtor do `AuthController` faz `new AuthService()/OAuthService()` diretamente; recomenda-se integrar ao container (suporte nativo do webman), facilitando testes e substituição.
2. **Reforço do diretório de uploads**: colocar `index.html` no diretório e desabilitar a execução de PHP (nginx `location ~ \.php { deny all; }`).
3. **Convergência de regex do WAF**: `sqli_patterns` do `security.php` contém padrões amplos como `\b(select|update|delete|...)\b`; com limite global, essas palavras em tickets/avaliações de usuários podem gerar 403 por engano; recomenda-se aplicar apenas a parâmetros sensíveis ou restringir os regex.
4. **Logs de auditoria**: `AuditLogger::record('user_registered', ['user_id' => null])` não registra o ID do novo usuário; recomenda-se registrar o ID real.
5. **Cobertura de testes OAuth**: `OAuthServiceTest` cobre a construção de URL e a troca de code, mas `resolveUser()` (caminho do DB) e o caminho de verificação de assinatura da Apple não têm testes; após a correção do P0, é obrigatório adicionar casos de teste para falha de verificação de assinatura.
6. **Integração com CI**: o projeto tem o diretório `.github`; recomenda-se adicionar GitHub Actions: `composer install && phpunit` + `composer audit`, prevenindo regressões.
7. **Restrição de métodos HTTP**: registrar GET/POST para o callback OAuth é razoável (a Apple exige); as demais operações de escrita públicas já são explicitamente POST, OK.

---

## 7. Lista de Prioridades de Correção

| Prioridade | Item | Esforço |
|:---:|------|:---:|
| P0 | Verificação de assinatura do id_token da Apple (JWKS + iss/aud/exp/nonce) | Médio |
| P1 | Validar `email_verified` em todos os provedores OAuth | Pequeno |
| P1 | Montar RateLimitMiddleware nas rotas públicas | Pequeno |
| P1 | Forçar TOTP no fluxo de login | Médio |
| P1 | Implementar envio real de email (ou marcar como não concluído) | Médio |
| P1 | Completar as 31 variáveis ausentes no .env.example + documentação de configuração OAuth | Pequeno |
| P2 | Lista de permissões de type no upload + validação MIME | Pequeno |
| P2 | Rate limit no reset code/forgot-password | Pequeno |
| P2 | Confirmação de senha no endpoint de recovery codes | Pequeno |
| P2 | Commit do composer.lock, gitignore do .phpunit.cache | Mínimo |
| P3 | Limpeza do código morto da lista negra, convergência de regex do WAF, complemento do i18n | Médio |

---

## 8. Status das Correções (2026-08-06)

| Prioridade | Item | Status |
|:---:|------|:---:|
| P0 | Verificação de assinatura do id_token da Apple (JWKS + iss/aud/exp/nonce) | ✅ Corrigido |
| P1 | Validar `email_verified` em todos os provedores OAuth (X com fallback de `/2/email`) | ✅ Corrigido |
| P1 | Montar RateLimitMiddleware (rotas auth/oauth/password/sms/captcha + 4 novas regras) | ✅ Corrigido |
| P1 | Forçar TOTP no fluxo de login (5 erros bloqueiam 15 minutos, contagem independente contra DoS) | ✅ Corrigido |
| P1 | Envio real de email (symfony/mailer SMTP; estado dev-stub quando não configurado) | ✅ Corrigido |
| P1 | Completar as 31 variáveis ausentes no .env.example + documentação de configuração OAuth | ✅ Corrigido |
| P2 | Lista de permissões de type no upload + detecção de conteúdo MIME com finfo | ✅ Corrigido |
| P2 | Rate limit no reset code/forgot-password (5 erros → 429 por 10 minutos) | ✅ Corrigido |
| P2 | Confirmação de senha no endpoint de recovery codes | ✅ Corrigido |
| P2 | composer.lock designorado e staged, gitignore do .phpunit.cache | ✅ Corrigido |
| P3 | Limpeza do código morto da lista negra, convergência de regex do WAF (3 estruturais), complemento do i18n (conteúdo incorreto de zh-CN em billing/health/storage reescrito, `trans()` com implementação de fallback_locale) | ✅ Corrigido |
| E3 | Porta 8787 ocupada pelo erp-php, impossível iniciar localmente | ⚠️ Problema de ambiente, sem conflito no ambiente de implantação |
| E5 | doctrine/annotations obsoleto | ⚠️ Mantido após avaliação (dependência direta do hg/apidoc; remover quebraria a geração da documentação da API) |

Testes complementares: OAuth com 12 itens (incluindo parâmetro nonce, verificação de assinatura, rejeição por email_verified, fallback de email do X), WAF com 2 itens após o ajuste. Linha de base completa: **319/319 aprovados (505 assertions)**.

*Forma de geração do relatório: PHPUnit completo, `php -l` em 287 arquivos, auditoria estática de rotas/middlewares, comparação de diferenças entre uso e definição de env, composer audit, sondagem de portas e processos. Linha de base de testes: 314/314 aprovados.*
