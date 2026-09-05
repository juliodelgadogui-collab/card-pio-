# EventMenu Premium

Sistema SaaS multiempresa para **Cardápio Digital + Delivery + Operação de Restaurante + Eventos + Ingressos + Pagamentos**.

Esta reconstrução é mantida diretamente no GitHub e parte dos requisitos da linha **v9.2 HARDENING**, sem depender dos ZIPs antigos.

## Estado atual

O `main` possui CI obrigatório de aplicação com:

- lint/sintaxe PHP 8.2;
- validação estrita do Composer;
- smoke test real em MySQL 8;
- smoke test real em SQLite;
- teste específico de URLs para instalação em `/1`;
- geração do artefato limpo `EventMenu-Premium-1` somente depois que os gates anteriores passam.

## Requisitos

- PHP 8.2+
- MySQL 8/MariaDB **ou SQLite**
- Extensões PHP: PDO, pdo_mysql e/ou pdo_sqlite, mbstring, curl, openssl
- HTTPS em produção
- Apache/LiteSpeed ou Nginx com bloqueio de acesso às pastas privadas

## Instalação direta em `/1`

A forma recomendada para hospedagem compartilhada é usar o artefato **`EventMenu-Premium-1`** gerado pelo GitHub Actions.

1. Baixe o artefato da execução verde mais recente do workflow **EventMenu CI**.
2. Envie todo o conteúdo do artefato para a pasta `/1` do domínio.
3. Renomeie `.env.example` para `.env`.
4. Configure `APP_URL` apenas com a origem do domínio, por exemplo `https://exemplo.com.br`.
5. Mantenha `APP_BASE_PATH=/1`.
6. Gere uma `APP_KEY` longa e aleatória.
7. Escolha `DB_CONNECTION=mysql` ou `DB_CONNECTION=sqlite` e configure os campos correspondentes.
8. Em HTTPS, use `SESSION_SECURE=true`.
9. Garanta permissão de escrita em `storage/`.
10. Acesse `https://SEU-DOMINIO/1/install.php` e crie a primeira empresa e o administrador.

O pacote contém wrappers públicos na raiz de `/1`, dependências de produção (`vendor/`), PWA, assets e regras `.htaccess` para impedir acesso HTTP direto a `app/`, `src/`, `database/`, `storage/`, `vendor/` e `public/` em Apache/LiteSpeed.

Para Nginx, replique esses bloqueios no virtual host.

## Desenvolvimento com DocumentRoot em `public/`

Também é possível clonar o repositório normalmente, executar `composer install`, copiar `.env.example` para `.env` e apontar o DocumentRoot para `public/`. Nesse caso, ajuste `APP_BASE_PATH` conforme o caminho publicado ou deixe-o vazio para a raiz.

## Banco de dados

### MySQL/MariaDB

Use:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=eventmenu
DB_USERNAME=...
DB_PASSWORD=...
```

### SQLite

Use:

```env
DB_CONNECTION=sqlite
DB_SQLITE_PATH=storage/eventmenu.sqlite
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

O sistema não confia no navegador, PWA ou futuro aplicativo Android para declarar um pagamento como aprovado. O estado `paid` só pode ser confirmado após validação servidor-a-servidor, conferindo:

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
.github/workflows/   CI MySQL/SQLite/lint e geração do pacote
app/                 bootstrap, helpers e rotas administrativas
database/            schema/migrações MySQL e schema SQLite
public/              frontends públicos, painel, PWA e assets
scripts/             smoke tests e gerador do pacote /1
src/Core/            banco, autenticação e segurança
src/Services/        pagamentos, gateways, ingressos e domínio
```

## Regra de produção

Nenhuma mudança deve ser tratada como pronta apenas porque compilou. O HEAD precisa fechar os gates de CI e o artefato deve ser gerado pela mesma execução verde que será usada para implantação.
