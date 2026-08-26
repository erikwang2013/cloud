# Relatório de Complemento de Cobertura de Testes Unitários PHP (2026-08-26)

## Ambiente

- PHP 8.3.7 (suíte service PHPUnit 10.5.64 / suíte admin PHPUnit 11.5.56)
- service/: API de negócio; admin/: painel administrativo
- Dados de teste: SQLite `:memory:` (inicialização com Capsule, seguindo o padrão dos ReportServiceTest / OrderIdentityTest existentes); serviços externos (Redis/MySQL/Stripe) todos degradados ou com mock

## Conclusão do Levantamento: Módulos vs Cobertura

### service/app (27 módulos)

| Módulo | Testes antes do levantamento | Estado da cobertura |
|------|-----------|----------|
| order / payment / user / product / provisioning / supplier / affiliate / notification / webhook / websocket / graphql / grpc / monitor / billing / captcha / domain / ssl / storage / report / ticket / admin / security / confirmation / version / clientplatform | 1-12 arquivos de teste cada | Coberto |
| **command** (6 comandos) | **nenhum** | **0 de cobertura → ReconcileCommandTest complementado nesta rodada** |
| **cron** (6 tarefas) | apenas SupplierSettlementTest | Cobertura parcial → PaymentReconcileTest + ExchangeRateSyncTest complementados nesta rodada |
| controller (Health/Help/Status/Upload) | nenhum | Controladores finos (estado estático/health check), sem lógica de negócio |
| model (payment/order etc., 20+ modelos) | cobertura indireta via camada de serviço | Coberto |

### admin/app (controller/common/model/middleware)

| Módulo | Testes antes do levantamento | Estado da cobertura |
|------|-----------|----------|
| controller (48 controladores) | AdminControllersTest (reflexão de todos os controladores: montagem de modelos/faces CRUD/caminhos de views GET) + CrudHashidsTest | Coberto |
| middleware | AccessControlMiddlewareTest | Coberto |
| common | TreeTest / HashidsTest / BaseJsonTest | Cobertura parcial → UtilTest + LayuiTest + ExcelExportTest complementados nesta rodada |
| model | nenhum teste direto | DictTest complementado nesta rodada; demais modelos são mapeamentos finos |

## Testes Novos nesta Rodada

| Módulo | Arquivos novos | Casos | Asserções | Pontos cobertos |
|------|----------|------|------|--------|
| Cron (conciliação de fundos) | `service/tests/cron/PaymentReconcileTest.php` | 7 | 24 | compare arredonda half-up com precisão de menor unidade da moeda: resto sub-cêntimo verified com diff zerada; diferença real mismatch; moeda sem decimais (JPY) com arredondamento inteiro; moeda presente em apenas um lado; lado vazio verified; data inválida lança InvalidArgumentException; run() faz upsert de linha unverified para canais sem relatório (somente success entra no total local, failed excluído, índice único espelha produção) |
| Cron (sincronização de câmbio) | `service/tests/cron/ExchangeRateSyncTest.php` | 2 | 2 | API inalcançável conclui silenciosamente (não lança para o agendador); payload válido + Redis indisponível não quebra |
| Command (comando de conciliação) | `service/tests/command/ReconcileCommandTest.php` | 2 | 3 | data inválida → FAILURE + mensagem de erro; data válida → SUCCESS (tabela de canais vazia) |
| Admin Common | `admin/tests/UtilTest.php` | 17 | 47 | ida e volta de hash/verify de senha; humanDate cinco faixas de tempo relativo; formatBytes; validação checkTableName/filterAlphaNum/filterNum/filterUrlPath/filterPath (incluindo BusinessException); controllerToUrlPath (incluindo @action e entrada inválida); camel/smCamel; getCommentFirstLine; typeToControl/typeToMethod; getLengthValue (decimal/enum/varchar); getControlProps (select data convertido em lista value/name vs key=>value comum) |
| Admin Model | `admin/tests/DictTest.php` | 5 | 10 | conversão nome de dicionário↔nome de option; validação de formato de filterValue; nome deve conter letra; cadeia completa save/get/delete (SQLite em memória, semântica de sobrescrita por mesmo nome); ausente retorna null |
| Admin Common | `admin/tests/ExcelExportTest.php` | 4 | 9 | escrita de cabeçalho + negrito; achatamento JSON de campo array; número de linha acrescentado linha a linha; coluna ausente vira célula vazia (asserções em memória com PhpSpreadsheet, sem gravar arquivo) |
| Admin Common | `admin/tests/LayuiTest.php` | 5 | 9 | renderização de input name/value; inputNumber força tipo number; escape HTML de label (previne injeção em atributos); switch renderiza lay-skin; html() reindenta |

Nesta rodada: 42 casos / 104 asserções novos. Todas as asserções de valores monetários são comparações exatas de string `assertSame` (bcmath), sem ponto flutuante.

## Correções no Ambiente de Teste (não é código de negócio)

1. **service/vendor corrompido**: `composer.lock` foi atualizado (encryptable v2.0.2→v2.0.3 entre outros pacotes) mas o vendor não foi sincronizado; a falta de guzzle impedia a suíte de iniciar → `composer install` restaurou, as duas suítes rodam.
2. **Fixture de criptografia do UserModelTest invalidada**: encryptable v2.0.3 exige chave de 32 bytes (padrão aes-256-gcm); a fixture antiga tinha 16 bytes → falha. Correção: o setUp de `service/tests/user/UserModelTest.php` fixa chave de 32 bytes + aes-256-gcm e chama `Encryption::setFallbackConfig(null)` para resetar o cache estático no nível de processo do pacote — `tests/user/AuthFullChainTest.php` injeta `service/.env` (cipher=aes-128-ecb, chave de 24 caracteres não base64) em `$_ENV/$_SERVER`, e o cache estático `$resolved` causava contaminação entre testes: rodando isolado passava, rodando a suíte completa falhava. Essa correção também dá aos testes subsequentes que dependem de Encryptable um ambiente consistente.

## Problemas no Código de Negócio

Nenhum bug de negócio encontrado nesta rodada. Dois significados do `PaymentReconcile::compare` fáceis de interpretar errado são asseridos conforme a implementação real e comentados: diff é a diferença bruta dos totais (não a diferença do arredondamento por unidade); após o arredondamento inteiro em moeda sem decimais, o diff do mismatch é a diferença bruta (ex.: JPY 1234 vs 1234.5000 → diff -0.5000).

## Resultado Completo

| Suíte | Casos | Asserções | Falhas | Erros | Pulados |
|------|------|------|------|------|------|
| service | 672 | 1632 | 0 | 0 | 15 |
| admin | 286 | 962 | 0 | 0 | 1 |

- Comparação com a linha de base: service 661→672 (+11), admin 255→286 (+31); ambas as suítes com 0 failure / 0 error.
- Verificação de sintaxe: todos os arquivos novos e modificados passaram em `php -l`.

## Lacunas Remanescentes e Motivos

| Lacuna | Motivo |
|------|------|
| cron/CronRunner, cron/SslCertificateCheck | Contexto de agendamento + sondagem real de certificado TLS, custo alto para teste unitário |
| command/Migrate*, DbBackupCommand, I18nSyncCommand | Dependem de migração MySQL real/sistema de arquivos, exigem ambiente de integração |
| admin/common/Auth (getScopeRoleIds/isSuperAdmin) | Dependem de sessão e dados de permissão do banco |
| admin/common/Migration*, Layui::buildTable/buildForm | Dependem de information_schema do banco / estrutura completa de tabelas |
| service/controller controladores finos (Health/Help/Status/Upload) | Sem lógica de negócio; os valores de retorno são fornecidos pelo runtime do webman |
| graphql/GraphqlController | Depende dos helpers `json()`/`config()` do webman e do runtime de FeatureFlags; o Schema já é coberto pelo SchemaTest |
| monitor/ResourceMonitor | Depende de Redis + chamadas reais de provider, exige camada de mock ou ambiente de integração |
