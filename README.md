# EventMenu Premium

Sistema SaaS multiempresa para **Cardápio Digital + Delivery + Operação de Restaurante + Eventos + Ingressos + Pagamentos**.

Esta reconstrução é mantida diretamente no GitHub e parte dos requisitos da linha **v9.2 HARDENING**, sem depender dos ZIPs antigos.

## Estado atual

O `main` possui CI de aplicação com:

- lint/sintaxe PHP 8.2;
- validação estrita do Composer;
- smoke test real em SQLite;
- smoke test real em MySQL 8;
- teste específico de URLs para instalação em `/1`;
- compilação independente do app Android EventMenu GO;
- geração do artefato de servidor **`EventMenu-Premium-Servidor-SQLite-1`** depois que lint e SQLite passam.

O pacote do servidor não depende do resultado da compilação Android. Assim, uma correção do app móvel não bloqueia uma implantação web/servidor válida.

## Requisitos do servidor

Para a primeira instalação definida para esta fase:

- PHP 8.2+
- SQLite via `pdo_sqlite`
- Extensões PHP: PDO, pdo_sqlite, mbstring, curl, openssl
- HTTPS em produção
- Apache/LiteSpeed ou Nginx com bloqueio de acesso às pastas privadas
- permissão de escrita em `storage/`

O pacote já inclui `vendor/`, portanto **Composer não é necessário no servidor de produção** para a instalação pelo artefato.

## Primeira instalação em servidor totalmente vazio — SQLite

A forma recomendada é usar o artefato **`EventMenu-Premium-Servidor-SQLite-1`** gerado pelo GitHub Actions.

1. Baixe o artefato da execução verde mais recente do workflow **EventMenu CI**.
2. Crie/abra a pasta `/1` no domínio.
3. Envie **todo o conteúdo** do artefato para `/1`.
4. Não crie banco de dados manualmente.
5. Não é obrigatório criar ou renomear `.env`: se ele não existir, o instalador cria automaticamente.
6. Acesse `https://SEU-DOMINIO/1/install.php`.
7. Confira a verificação automática de PHP, PDO SQLite, schema, dependências e permissões.
8. Informe a URL do sistema, empresa inicial e os dados do Super ADM.
9. Clique em **Instalar EventMenu com SQLite**.

O instalador cria automaticamente:

- `.env` com `APP_KEY` e `CRON_SECRET` aleatórios;
- `DB_CONNECTION=sqlite`;
- `DB_SQLITE_PATH=storage/eventmenu.sqlite`;
- `storage/eventmenu.sqlite`;
- schema e todas as migrations SQLite atuais;
- empresa inicial;
- usuário Super ADM;
- `storage/installed.lock`, bloqueando uma segunda instalação.

Depois, acesse `https://SEU-DOMINIO/1/`.

O pacote contém wrappers públicos na raiz de `/1`, dependências de produção (`vendor/`), PWA, assets e regras `.htaccess` para impedir acesso HTTP direto a `app/`, `src/`, `database/`, `storage/`, `vendor/` e `public/` em Apache/LiteSpeed.

Para Nginx, replique esses bloqueios no virtual host.

## Cron obrigatório em produção

Depois da instalação, configure uma tarefa de cron **a cada minuto**. Ela executa expiração de reservas e sessões, limpeza, fila assíncrona, notificações push e agendamento de backup.

No pacote instalado em `/1`, o formato recomendado em cPanel/Linux é:

```cron
* * * * * php /CAMINHO/DO/SITE/1/cron.php >/dev/null 2>&1
```

O modo CLI é preferido porque não precisa colocar `CRON_SECRET` na linha de comando. O endpoint HTTP continua protegido pelo cabeçalho `X-Cron-Secret` para ambientes que realmente precisem de execução remota.

No painel, entre como **Super ADM → Saúde do sistema**. A tela mostra o caminho real do `cron.php` daquela instalação e verifica separadamente **Cron** e **Worker da fila**. Após configurar, os dois devem aparecer como `OK` em até alguns minutos.

## Teste de carga controlado

O repositório inclui `scripts/load-test.php` para medir páginas públicas e endpoints GET em ambiente local ou staging. Ele informa **requisições por segundo, taxa de falha e latências min/média/p50/p95/p99/max**.

Por segurança, o utilitário:

- envia somente `GET` e descarta o corpo da resposta sem armazená-lo;
- limita cada execução a no máximo 5.000 requisições e concorrência 50;
- bloqueia qualquer host que não seja localhost por padrão;
- para alvo remoto, exige ao mesmo tempo `--allow-remote` e `--confirm-host=HOST` com o host exato;
- possui `--dry-run`, que valida toda a configuração sem fazer rede;
- permite reprovar automaticamente o teste por taxa de erro ou p95 com `--fail-error-rate` e `--fail-p95-ms`.

Exemplo local:

```bash
php scripts/load-test.php \
  --url=http://127.0.0.1:8080/1/evento.php?evento=teste \
  --requests=500 \
  --concurrency=20 \
  --expected=200
```

Exemplo para staging autorizado:

```bash
php scripts/load-test.php \
  --url=https://staging.exemplo.com/1/evento.php?evento=teste \
  --requests=1000 \
  --concurrency=25 \
  --allow-remote \
  --confirm-host=staging.exemplo.com \
  --fail-error-rate=1 \
  --fail-p95-ms=1500
```

Não use esse utilitário para webhooks, checkout, Pix, cartão, NFC ou qualquer endpoint que altere dados. O arquivo `scripts/load-test.php` fica no repositório de desenvolvimento e **não é incluído no pacote de produção** gerado para o servidor.

## Atualizações do servidor

Ao atualizar uma instalação existente:

1. preserve o arquivo `.env`;
2. preserve **toda a pasta `storage/`**;
3. envie os novos arquivos do pacote;
4. nunca substitua `storage/eventmenu.sqlite` por um arquivo vazio;
5. entre com usuário administrativo autorizado;
6. acesse `/1/update.php` para executar somente as migrations ainda não aplicadas.

O pacote inclui `SHA256SUMS.txt` para conferência de integridade dos arquivos gerados no CI.

## Desenvolvimento com DocumentRoot em `public/`

Também é possível clonar o repositório normalmente, executar `composer install`, copiar `.env.example` para `.env` e apontar o DocumentRoot para `public/`. Nesse caso, ajuste `APP_BASE_PATH` conforme o caminho publicado ou deixe-o vazio para a raiz.

## Banco de dados

### SQLite — padrão da primeira instalação

```env
DB_CONNECTION=sqlite
DB_SQLITE_PATH=storage/eventmenu.sqlite
```

A conexão ativa automaticamente:

- `PRAGMA foreign_keys = ON`;
- `PRAGMA busy_timeout = 5000`;
- modo WAL para o banco persistente.

### MySQL/MariaDB — opção futura

O sistema continua mantendo suporte a MySQL/MariaDB para uma migração futura:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eventmenu
DB_USERNAME=...
DB_PASSWORD=...
```

A camada `Database` seleciona schema e estratégia de transação por driver. Locks usados em pagamentos, ingressos, cupons, pontos, mesas/comandas, convidados e NFC possuem comportamento portátil para os dois bancos.

## Módulos disponíveis

- Multiempresa por `tenant_id`.
- Autenticação com `password_hash`/`password_verify`.
- Sessões HttpOnly, SameSite e caminho de cookie compatível com `/1`.
- Proteção CSRF.
- RBAC com Super ADM, ADM, gerente, caixa, garçom, cozinha, entregador e promotor.
- Garçom e entregador sem permissão de confirmar pagamentos.
- Entregador restrito aos pedidos atribuídos a ele.
- Catálogo, categorias, produtos e estoque.
- Cardápio digital público.
- Delivery e retirada/fluxo operacional de pedidos.
- QR de mesa com pedidos vinculados a `table_id` e à comanda aberta quando existente.
- Mesas e comandas.
- Clientes, CRM básico e pontos.
- Cupons e reservas de cupom.
- Eventos e página pública.
- Lotes, reservas de ingressos, QR e check-in.
- Lista de convidados e check-in.
- Promotores/afiliados e comissões.
- Stripe, PagBank e Mercado Pago.
- Webhooks validados e deduplicados.
- Idempotência de pagamentos e efeitos financeiros/operacionais.
- NFC PagBank com pareamento limitado e revogação de dispositivo.
- Auditoria administrativa.
- Relatórios.
- PWA com manifest e service worker que não armazena páginas autenticadas nem respostas de pagamento em cache.

## Segurança de pagamentos

O sistema não confia no navegador, PWA ou aplicativo Android para declarar um pagamento como aprovado. O estado `paid` só pode ser confirmado após validação servidor-a-servidor, conferindo:

- assinatura/autenticidade do webhook;
- provedor;
- ID externo da transação;
- empresa e conta recebedora;
- pedido;
- valor;
- moeda;
- idempotência do evento e da cobrança.

O núcleo está em `src/Services/PaymentService.php`, enquanto `GatewayService.php` valida eventos recebidos e `CheckoutService.php` cria cobranças/retornos com URLs compatíveis com o caminho `/1`.

## Estrutura do repositório

```text
.github/workflows/   CI SQLite/MySQL/lint, Android e geração independente do pacote do servidor
app/                 bootstrap, helpers e rotas administrativas
database/            schema/migrations MySQL e SQLite
public/              frontends públicos, painel, instalador, APIs, PWA e assets
scripts/             smoke tests, teste de carga controlado e gerador do pacote do servidor
src/Core/            banco, autenticação e segurança
src/Services/        pagamentos, gateways, ingressos e domínio
```

## Regra de produção

Nenhuma mudança deve ser tratada como pronta apenas porque compilou. Para a implantação inicial SQLite, o pacote de servidor deve ser gerado por uma execução em que **lint + SQLite estejam verdes**. O app Android mantém sua própria validação independente.
