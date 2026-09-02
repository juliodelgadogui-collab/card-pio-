# EventMenu Premium

SaaS multiempresa para **Cardápio Digital + Delivery + Restaurante + Eventos + Ingressos + Pagamentos**.

Esta versão é uma reconstrução nova criada diretamente no GitHub a partir dos requisitos da linha **v9.2 HARDENING**, sem depender do ZIP anterior.

## Estado atual

A base já é instalável e possui CI no GitHub validando PHP, dependências e migrações MySQL. O núcleo operacional está implementado, mas gateways reais ainda devem ser validados em **sandbox/homologação** antes de receber dinheiro em produção.

## Requisitos

- PHP 8.2+
- MySQL 8.0+ ou MariaDB compatível
- Composer 2
- Extensões PHP: curl, json, mbstring, openssl, PDO e pdo_mysql
- Apache ou Nginx com DocumentRoot apontando para `public/`
- HTTPS obrigatório em produção

## Instalação

1. Clone o repositório.
2. Execute `composer install --no-dev --optimize-autoloader`.
3. Copie `.env.example` para `.env`.
4. Configure banco, domínio e uma `APP_KEY` longa e aleatória.
5. Em produção use `APP_ENV=production`, `APP_DEBUG=false` e `SESSION_SECURE=true`.
6. Aponte o domínio para a pasta `public/`.
7. Acesse `/install.php` e crie a primeira empresa/administrador.
8. O instalador cria `storage/installed.lock`; depois disso use `/update.php` para migrações futuras.

## Super ADM

O Super ADM não é criado por uma rota pública. No servidor, execute:

```bash
php bin/create-super-admin.php "Nome do Super ADM" email@dominio.com "senha-com-12-ou-mais"
```

O Super ADM possui painel global para criar empresas e administradores, suspender/reativar empresas e selecionar com segurança o contexto de uma empresa sem alterar sua identidade administrativa.

## Módulos implementados

### Plataforma e segurança

- Multiempresa por `tenant_id`.
- Super ADM global.
- Perfis: admin, gerente, caixa, garçom, cozinha, entregador e promotor.
- Sessões HttpOnly/SameSite, regeneração de sessão e CSRF.
- Usuários bloqueados perdem a sessão.
- Auditoria administrativa.
- Credenciais de gateway criptografadas usando `APP_KEY`.
- Migrações versionadas e reaplicáveis.

### Cardápio, delivery e restaurante

- Categorias e produtos com edição, imagem, SKU, preço e estoque.
- Cardápio público responsivo.
- Carrinho isolado por empresa.
- Revalidação de preço e estoque no servidor no checkout.
- Delivery com cliente, endereço e cupom.
- Mesas com QR próprio.
- Comandas abertas por mesa.
- Pedido público pelo QR da mesa.
- KDS/cozinha com fila `confirmed → preparing → ready`.
- Máquina de estados para pedidos.
- Entregador visualiza somente pedidos atribuídos a ele.
- Entregador atualiza somente o próprio fluxo de entrega.
- Pedido não pago não pode ser finalizado.
- Pedido pago não pode ser cancelado diretamente sem fluxo de reembolso.

### Estoque

- Movimentação idempotente por pedido/produto.
- Pedido de mesa compromete estoque antes de entrar em preparo.
- Pagamento posterior não duplica a baixa.
- Cancelamento de pedido não pago devolve estoque comprometido uma única vez.
- Pagamentos pré-pagos comprometem estoque dentro da transação de confirmação.

### CRM, cupons e afiliados

- Clientes e pontos.
- Cupons percentuais/fixos, validade, mínimo e limite de usos.
- Reserva temporária de cupom durante checkout.
- Cupom expirado/abandonado é liberado automaticamente.
- Promotores/afiliados com código e percentual de comissão.
- Comissão criada com idempotência após pagamento confirmado.

### Eventos e ingressos

- Eventos públicos.
- Lotes, período de venda e disponibilidade.
- Reserva de ingresso por 15 minutos.
- Lista de convidados.
- Ingresso digital e QR.
- Check-in idempotente; somente ingresso pago entra.
- Duplicidade de check-in é registrada e bloqueada.
- Reservas expiradas não são liberadas enquanto houver cobrança ativa no gateway.

### Pagamentos

- Stripe Checkout.
- Mercado Pago Checkout Pro.
- PagBank Checkout com PIX/cartão no ambiente do provedor.
- Pagamento manual restrito a perfis autorizados.
- Idempotência de cobrança e webhook.
- Confirmação `paid` somente após validação servidor-a-servidor.
- Verificação de empresa, provedor, conta recebedora, ID externo, valor e moeda.
- Uma transação externa não pode ser vinculada a duas cobranças.
- Webhooks isolados por empresa.

O frontend, PWA ou futuro aplicativo Android **não têm autoridade para marcar pagamento como aprovado**.

### PWA

- `manifest.webmanifest`.
- Ícone próprio.
- Service Worker.
- Fallback offline.
- O Service Worker não armazena painel autenticado, pedidos ou páginas dinâmicas em cache; apenas arquivos estáticos.

## Rotina agendada

Configure o servidor para executar periodicamente:

```bash
php /caminho/do/eventmenu/bin/cron.php
```

Sugestão de cron a cada minuto:

```cron
* * * * * /usr/bin/php /caminho/do/eventmenu/bin/cron.php >> /caminho/do/eventmenu/storage/cron.log 2>&1
```

A rotina libera reservas abandonadas de ingresso/cupom e pedidos públicos expirados. Ela **não** libera reservas de pedidos que ainda possuem cobrança `created`, `pending` ou `authorized`.

## GitHub Actions / CI

`.github/workflows/ci.yml` executa:

- validação do `composer.json`;
- instalação das dependências;
- lint de todos os arquivos PHP;
- smoke tests;
- MySQL 8 temporário;
- schema base + todas as migrações;
- verificação de tabelas críticas.

## Estrutura

```text
app/                  roteador, helpers e telas administrativas
bin/                  comandos CLI e cron
database/             schema e migrações
public/               DocumentRoot e páginas públicas
src/Core/             autenticação, banco, criptografia e segurança
src/Services/         regras de negócio e integrações
tests/                smoke tests
.github/workflows/     CI
storage/               runtime privado
```

## Antes de produção com pagamentos reais

- Configurar HTTPS e `SESSION_SECURE=true`.
- Usar uma `APP_KEY` forte e protegida.
- Configurar credenciais de **sandbox** por empresa.
- Testar checkout, webhook atrasado, webhook repetido, valor divergente e pagamento recusado de cada provedor.
- Confirmar URLs de webhook no painel do provedor.
- Testar estoque concorrente, reservas expiradas, cupons e check-in.
- Configurar backups de banco.
- Configurar o cron.
- Somente depois trocar credenciais de sandbox por produção.

## Próximas etapas

A prioridade restante é homologação prática dos gateways, fluxo de reembolso por provedor, testes de concorrência mais profundos e, somente após estabilizar o web/PWA, desenvolvimento do aplicativo nativo com pagamento por aproximação PagBank/NFC.
