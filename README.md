# EventMenu Premium

Sistema SaaS multiempresa para **Cardápio Digital + Delivery + Operação de Restaurante + Eventos + Ingressos + Pagamentos**.

Esta é uma reconstrução nova criada diretamente no GitHub, aproveitando os requisitos definidos para a linha v9.2 HARDENING, sem depender do ZIP anterior.

## Requisitos

- PHP 8.2+
- MySQL 8.0+ ou MariaDB compatível
- Extensões PHP: PDO, pdo_mysql, mbstring, json, openssl
- Apache ou Nginx apontando o DocumentRoot para `public/`
- HTTPS obrigatório em produção

## Instalação

1. Clone o repositório.
2. Copie `.env.example` para `.env`.
3. Crie um banco MySQL vazio e configure `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD`.
4. Gere um `APP_KEY` longo e aleatório.
5. Em produção, use `APP_ENV=production`, `APP_DEBUG=false` e `SESSION_SECURE=true`.
6. Configure o servidor para apontar o domínio para a pasta `public/`.
7. Acesse `/install.php` e crie a primeira empresa e o administrador.
8. Após a instalação, o arquivo `storage/installed.lock` bloqueia a execução do instalador novamente.

## O que já existe nesta reconstrução

- Multiempresa por `tenant_id`.
- Autenticação com `password_hash`/`password_verify`.
- Sessões com HttpOnly e SameSite.
- Proteção CSRF.
- RBAC com perfis: Super ADM, ADM, gerente, caixa, garçom, cozinha, entregador e promotor.
- Garçom e entregador sem permissão de confirmar pagamentos.
- Entregador restrito aos pedidos atribuídos a ele.
- Catálogo, categorias e produtos.
- Cardápio digital público.
- Carrinho e criação de pedido delivery.
- Pedidos e fluxo operacional.
- Clientes/CRM básico.
- Eventos e página pública.
- Estrutura de lotes e ingressos.
- Estrutura de gateways de pagamento.
- Pagamentos com chave de idempotência.
- Bloqueio de nova cobrança para pedido cancelado/finalizado.
- Confirmação de pagamento prevista somente após verificação servidor-a-servidor.
- Estoque e movimentações com idempotência.
- Webhook events com deduplicação.
- Auditoria de ações administrativas.
- Dashboard responsivo com interface Premium.

## Segurança de pagamentos

O sistema **não deve confiar no navegador, PWA ou futuro aplicativo Android informando que um pagamento foi aprovado**. O estado `paid` deve ser gravado apenas após validação do gateway no servidor, verificando no mínimo:

- assinatura/autenticidade do webhook;
- provedor correto;
- ID externo da transação;
- empresa/conta correta;
- pedido correto;
- valor correto;
- moeda correta;
- idempotência do evento e da cobrança.

A classe `src/Services/PaymentService.php` contém o núcleo transacional dessa política. Os adaptadores reais de Stripe, PagBank e Mercado Pago serão conectados sobre essa camada.

## Estrutura

```text
app/                 bootstrap da aplicação
database/            schema MySQL
public/              DocumentRoot, painel e páginas públicas
public/assets/       CSS/JS
src/Core/            banco, autenticação e segurança
src/Services/        serviços de domínio, incluindo pagamentos
storage/             locks e arquivos privados de runtime
```

## Próximos módulos da reconstrução

A base foi preparada para receber sem reinício de arquitetura: webhooks reais Stripe/PagBank/Mercado Pago, PIX/cartão, NFC PagBank, cupons, promotores/afiliados, comissões, QR Code e check-in, lista de convidados, mesas/comandas, cozinha/KDS, delivery avançado, CRM/pontos, relatórios, gateway configuration, dispositivos NFC e PWA.

> Antes de pagamentos reais, todos os adaptadores de gateway e webhooks devem passar por testes automatizados e sandbox.
