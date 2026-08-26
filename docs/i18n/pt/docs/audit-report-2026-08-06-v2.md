# Relatório de Auditoria CloudPlatform (segunda rodada, 2026-08-06)

> Escopo: re-inspeção após a correção de todos os problemas da rodada anterior (audit-report-2026-08-06.md).
> Linha de base de testes: PHPUnit **319/319 aprovados (505 assertions)**; `php -l` em 253 arquivos PHP com **0 erros de sintaxe**.

---

## 1. Testes e Verificações Estáticas

| Item | Resultado |
|------|------|
| PHPUnit completo | OK (319 tests, 505 assertions) |
| `php -l` (app/common/config) | 253 arquivos, todos aprovados |
| composer audit | **Sem vulnerabilidades de segurança**; 1 pacote obsoleto doctrine/annotations (dependência direta do hg/apidoc, avaliação de manter) |
| composer.lock | Incluído no controle de versão (staged A) |

---

## 2. Verificação da Configuração do Ecossistema

### 2.1 Uso e definição de env — completo ✓

- Todas as chaves `getenv()` no código (incluindo o padrão dinâmico `{PROVIDER}_OAUTH_*`) estão definidas no `.env.example` ou como opções comentadas (`#HASHIDS_ALPHABET`, `#POSTER_IMAGE_DRIVER`, `#EXCHANGE_RATE_API_URL`, `#COUNTRY_SEASON_DEFAULT`, `#SECURITY_HSTS_VALUE`)
- Itens redundantes no modelo (baixo risco): `MAIL_FROM_NAME` não tem referência `getenv()` no código, mantido apenas no modelo

### 2.2 Travamento de dependências ✓

- `service/composer.lock` foi commitado; `.gitignore` não o exclui mais; `service/.phpunit.cache/` está ignorado

### 2.3 Observações de ambiente

- A porta 8787 desta máquina ainda está ocupada pelo erp-php, impossibilitando iniciar o cloud-php localmente (sem conflito no ambiente de implantação)
- `composer validate` falha com fatal devido a conflito entre o Installer do plugin de vendor `erikwang2013/security-php` e o eval do próprio composer (problema de pacote de terceiros, não do código deste projeto)

---

## 3. Verificação das Defesas de Segurança

### 3.1 Cadeia global de middlewares (11 camadas, cobre todas as rotas) ✓

```
Version → CORS → SecurityHeaders → ClientPlatform → GeoBlock
→ WAF（SQLi/XSS）→ SecurityPlugin（31 种攻击检测）
→ Locale → Metrics → HashidRequest → Maintenance
```

### 3.2 Limite de frequência nas rotas públicas — 1 correção nesta rodada

| Rota | Middleware | Regra de limite |
|------|--------|---------|
| register / login / refresh | RateLimit | register 3/min, login 5/min |
| **forgot-password / reset-password** | **RateLimit (montado nesta rodada)** | password_reset 3/5min |
| oauthRedirect / oauthCallback (GET+POST) | RateLimit | oauth 10/60s |
| login/recovery | RateLimit | login 5/min |
| send-sms | RateLimit | sms 5/h |
| captcha/create | RateLimit | captcha 30/60s |

> **Correção**: nas rotas `forgot-password`/`reset-password`, a regra `password_reset` foi definida na rodada anterior, mas a montagem do middleware foi omitida (superfície de bombardeio de e-mails/força bruta de códigos); nesta rodada o middleware foi montado.

### 3.3 Exposição de arquivos enviados — 1 correção nesta rodada (alto risco)

**Problema**: a configuração do nginx no `deployment.md` (`location /storage/ { alias .../service/storage/; }`) expõe todo o diretório storage:

```
storage/
├── backups/    ← backups do banco de dados (.sql.gz) publicamente baixáveis
├── apple/      ← chave privada AuthKey.p8 publicamente baixável (permite assinar tokens Apple)
├── firebase/   ← credenciais da conta de serviço FCM (incluindo chave privada) publicamente baixáveis
├── geoip/      ← banco de dados GeoLite2
└── uploads/    ← arquivos enviados (exposição esperada)
```

**Correção**: `deployment.md` e `docker/nginx.conf` foram alterados para `location ^~ /storage/uploads/`, expondo apenas o subdiretório uploads.

### 3.4 Outras verificações ✓

- `verify-email`: token aleatório de uso único (limpo após verificação), sem superfície de força bruta/enumeração, sem necessidade de limite
- Upload: lista de permissões de tipos + detecção de conteúdo MIME via finfo (corrigido na rodada anterior); uploads servidos via alias estático do nginx, sem executar PHP
- JWT: HS256 + lista negra Redis (validação por jti no banco); TOTP obrigatório no login + 5 falhas bloqueiam 15 minutos
- OAuth: verificação de assinatura JWKS + iss/aud/exp/nonce + email_verified obrigatório (corrigido na rodada anterior)
- Rotas administrativas: AuthMiddleware + AdminRoleMiddleware + ConfirmationMiddleware

---

## 4. Recomendações Restantes (não bloqueantes)

| Nível | Item | Descrição |
|:---:|------|------|
| P3 | Diretório antigo redundante `service/service/` (28K) | Contém cópias desatualizadas de Supplier/WebSocket, não carregadas pelo PSR-4, não rastreadas e fáceis de alterar por engano; recomenda-se excluir após confirmação manual |
| P3 | `MAIL_FROM_NAME` redundante no modelo | Não usado pelo código; pode ser mantido como configuração reservada do nome do remetente de e-mails |
| P3 | Depreciação do doctrine/annotations | Dependência direta do hg/apidoc; a remoção exige substituição sincronizada da solução de geração de documentação da API |
| P3 | Reforço do diretório de uploads (segunda recomendação) | Colocar `index.html` no diretório uploads, confirmar que a camada de implantação não executa PHP (o alias do nginx já evita isso naturalmente; atenção no cenário de servidor embutido do webman) |

---

## 5. Conclusão

Todas as 15 correções da rodada anterior foram confirmadas como eficazes na re-inspeção, e a linha de base de testes está estável (319/505). Nesta rodada, 3 problemas foram descobertos e corrigidos no local: **rotas forgot/reset sem limite de frequência montado (P1)**, **configuração do nginx no deployment.md expondo backups e chaves privadas (P0)**, **falta de configuração estática do uploads no nginx do docker (P2)**. Após as correções, a suíte completa de testes foi executada novamente e passou.

*Forma de geração do relatório: PHPUnit completo, php -l em 253 arquivos, auditoria estática de rotas/middlewares, auditoria de configuração nginx/docker, comparação de diferenças entre uso e definição de env, composer audit.*
