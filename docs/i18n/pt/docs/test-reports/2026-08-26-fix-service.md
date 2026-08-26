# Relatório de Correção de Defeitos do service (2026-08-26) (A/C/F)

## Conclusão

- Os 3 defeitos foram todos corrigidos e retestados de ponta a ponta (9/9 PASS)
- Regressão completa do PHPUnit: 672 tests / 1632 assertions / 15 skipped / 0 failures
- Não foram tocados .env, app/grpc/Generated, schema do banco de dados; nenhuma dependência composer adicionada

## Defeito A: chave encryptable sem decode base64 → registro/login/refresh/endereços todos com 500

### Causa raiz (três camadas sobrepostas)

1. `config/encryptable.php` passa `ENCRYPTION_KEY` (base64, 16 bytes após decode, cipher=aes-128-ecb) como chave sem decodificar; a validação de comprimento da chave lança `MissingEncryptionKeyException`.
2. Em tempo de execução, o arquivo realmente lido é `config/plugin/erikwang2013/encryptable/app.php` (apenas `enable`); essa configuração de plugin não tem key alguma.
3. webman não tem helper global `app()`; `Encryption::doResolve()` não alcança o caminho do container e cai no `EnvEncryptableConfig` (lê a string base64 crua do env, sem decode) — mesmo corrigindo a configuração do plugin ainda retornaria 500.

### Correção

| Arquivo | Alteração |
|------|------|
| `service/config/encryptable.php` | `'key' => base64_decode(getenv('ENCRYPTION_KEY'), true) ?: ''` (caminho legado, corrigido junto) |
| `service/config/plugin/erikwang2013/encryptable/app.php` | Complementar `key` (decode base64) / `cipher` / `previous_keys` |
| `service/support/bootstrap.php` | `Encryption::setFallbackConfig(new WebmanPluginEncryptableConfig())`, faz o runtime usar a configuração do plugin (chave já decodificada) |

### Bugs de mesma origem encontrados na cadeia (corrigidos juntos)

Depois que a correção de criptografia entrou em vigor, registro/login/refresh passaram a falhar além do 500:

- **Login 401**: `User::where('email', $login)->orWhere('phone', $login)` com query em texto puro nunca encontra colunas criptografadas. Correção: `where('email', Encryption::php()->encrypt($login))` (criptografia determinística, igualdade de ciphertext é suficiente para acertar).
- **Refresh 401 "Device mismatch"**: problema em duas camadas —
  - `RefreshToken::where('token_hash', hash(...))` com query em texto puro também não acerta; alterado para `encrypt(hash(...))`;
  - o caminho de registro nunca registra a impressão digital do dispositivo (`AuthService::register()` internamente chama `issueTokens(..., '')`), mas o refresh valida a impressão digital → refresh após registro falharia sempre. Correção: `AuthController::register` passa `deviceFingerprint($request)`; `AuthService::register` ganha o parâmetro `$deviceFingerprint`.
- **Validação de unicidade de e-mail/celular no registro**: `User::where('email', ...)->exists()` tem o mesmo bug; alterado para consulta com valor criptografado (`recordFailedLogin` corrigido junto).

## Defeito C: modelo Searchable sem cliente ES → alterar perfil/criar pedido retorna 500

### Decisão: driver do webman-scout alterado para `database` (em vez de `null`)

`config/plugin/erikwang2013/webman-scout/app.php`: `'driver' => 'elasticsearch' → 'database'`.

Motivo: o cliente elasticsearch/elasticsearch não está instalado; o driver elasticsearch lança exceção ao salvar o modelo; o engine `database` faz write como no-op e a busca usa SQL LIKE (busca de produtos permanece utilizável); o engine `null` faz `search()` retornar silenciosamente array vazio, engolindo os resultados de busca por palavras-chave de produtos. Configuração de soft delete mantém o padrão.

## Defeito F: detector dns_rebinding retorna 403 para requisições locais com Host=127.0.0.1

### Decisão: modo dns_rebinding alterado para `log` (em vez de whitelist_ips)

`config/plugin/erikwang2013/security-php/app.php`: `dns_rebinding.mode = 'block' → 'log'`.

Motivo: `whitelist_ips` pula **todos** os detectores com base no IP do cliente — neste ambiente todo o tráfego passa pelo nginx e o IP do cliente é sempre loopback, o que equivaleria a desligar os 31 detectores. Conexão direta local (Host=127.0.0.1/localhost) é o normal em desenvolvimento/teste; mudar para log libera apenas esse detector, mantendo os outros 30 em block.

## Descoberta adicional: user_addresses.phone VARCHAR(20) não comporta ciphertext criptografado

Com a criptografia ativa, criação de endereço retorna 500 (`SQLSTATE[22001] Data too long for column 'phone'`). Restrição "não alterar banco de dados" respeitada, correção no lado do código:

- `service/app/user/model/UserAddress.php`: `phone` removido dos casts Encryptable (tabela com 0 linhas, sem risco de migração de dados existentes). `address` mantém criptografia (VARCHAR(500) comporta).

**Trade-off e follow-up**: phone é PII e agora fica em texto puro no banco. Para restaurar a criptografia em disco, seria necessário expandir `user_addresses.phone` e `users.phone` (ambos VARCHAR(20) + Encryptable, registro por celular também daria 500) para VARCHAR(255) — exige uma migration de schema, fora da restrição "não alterar banco de dados" desta rodada; sugere-se projeto separado.

## Follow-up de revisão: guarda de determinismo do cipher (blocker do reviewer resolvido)

O reviewer apontou: consultas por igualdade de ciphertext dependem de criptografia determinística (ECB sem IV aleatório), mas `.env.example` sugere aes-256-cbc (IV aleatório) — um novo ambiente seguindo o exemplo "iniciaria com sucesso mas login/refresh/validação de unicidade nunca acertariam", ficando silenciosamente sem login.

Correção (guarda fail-fast, evita falha silenciosa):

- `service/support/bootstrap.php`: após a fiação da configuração encryptable, guarda — se `PHPEncrypter(WebmanPluginEncryptableConfig)->cipher()` não for `aes-128-ecb`/`aes-256-ecb`, lança `RuntimeException` na inicialização, deixando claro que "o modo de consulta determinística só suporta ECB; trocar de cipher exige migração com re-criptografia".
- `service/.env.example`: seção de comentários da criptografia ganha aviso (CBC/GCM lançaria erro na inicialização; consulta determinística somente ECB).

Verificação: o .env atual (aes-128-ecb) passa na guarda; após reinício do serviço, E2E 9/9 PASS; phpunit 672/1632/15 skipped/0 failures.

## Incidente de ambiente (não é código, requer tratamento no ambiente)

No meio da sessão, `/usr/local/php/conf.d/002-imagick.ini` (proprietário root, mtime 2026-08-26 23:31) foi criado; o imagick.so que ele carrega trava no construtor do libgomp → **todas as chamadas php CLI com ini sofrem segmentation fault** (phpunit, start.php, php -l todos quebram; gdb confirma que dlopen de imagick.so já causa SIGSEGV, OMP_NUM_THREADS=1 não resolve). Sem permissão de root não é possível excluir o arquivo; esta sessão contornou com `PHP_INI_SCAN_DIR=/tmp/confd` (cópia do diretório de varredura sem imagick); serviço e phpunit rodaram por esse caminho.

Sugestão ao ambiente: excluir ou comentar `/usr/local/php/conf.d/002-imagick.ini` (o imagick.so em si está corrompido) e investigar quem criou esse arquivo durante a sessão.

## Lista de arquivos alterados (todos em service/)

- `config/encryptable.php`
- `config/plugin/erikwang2013/encryptable/app.php`
- `config/plugin/erikwang2013/webman-scout/app.php`
- `config/plugin/erikwang2013/security-php/app.php`
- `support/bootstrap.php` (inclui a guarda de determinismo do cipher)
- `.env.example` (somente comentários, valores do .env intocados)
- `app/user/service/AuthService.php`
- `app/user/controller/AuthController.php`
- `app/user/model/UserAddress.php`

## Registro de verificação

- E2E (`/tmp/verify_chain.php`, script temporário fora do repositório): F (Host=127.0.0.1 sem 403), registro→login→refresh→criação de endereço, alteração de perfil 9/9 PASS.
- `vendor/bin/phpunit`: 672 tests / 1632 assertions / 15 skipped / 0 failures.
