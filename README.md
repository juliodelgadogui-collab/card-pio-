# EventMenu Premium

SaaS multiempresa para **Cardápio Digital + Delivery + Restaurante + Eventos + Ingressos + Pagamentos**.

Esta é uma reconstrução nova criada diretamente no GitHub a partir dos requisitos da linha **v9.2 HARDENING**, sem depender do ZIP anterior.

## Estado atual

A base web/PWA já é instalável e possui CI no GitHub validando PHP 8.2, dependências e migrações MySQL 8. O núcleo operacional e financeiro está implementado. Antes de receber dinheiro real, cada gateway deve passar por **sandbox/homologação com credenciais da empresa**.

A estratégia continua sendo: **estabilizar web/PWA primeiro; aplicativo nativo e NFC real depois**.

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
4. Configure banco e `APP_URL`.
5. Gere uma `APP_KEY` forte, por exemplo:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

6. Coloque o resultado em `APP_KEY`.
7. Em produção mantenha `APP_ENV=production`, `APP_DEBUG=false` e `SESSION_SECURE=true`.
8. Aponte o domínio para `public/`.
9. Acesse `/install.php` e crie a primeira empresa/administrador.
10. O instalador cria `storage/installed.lock`; depois disso use `/update.php` para futuras migrações.

O instalador recusa `APP_KEY` fraca/padrão, bloqueia duas instalações simultâneas e não recria o sistema sobre um banco que já possua usuários EventMenu.

## Atualização de uma instalação existente

1. Faça backup do banco e do `.env`.
2. Atualize os arquivos do sistema.
3. Execute `composer install --no-dev --optimize-autoloader`.
4. Entre com um administrador.
5. Acesse `/update.php` para aplicar somente as migrações ainda não executadas.
6. Confirme a execução do cron.

O bloqueio de tentativas de login foi implementado de forma compatível com atualização: antes da migração correspondente ser aplicada, o login continua funcionando para que o administrador consiga executar `/update.php`.

## Super ADM

O Super ADM não é criado por rota pública. No servidor:

```bash
php bin/create-super-admin.php "Nome do Super ADM" email@dominio.com "senha-com-12-ou-mais"
```

O painel global permite criar empresas e administradores, suspender/reativar empresas e entrar no contexto de uma empresa sem alterar a identidade do Super ADM.

## Segurança e permissões

- Multiempresa por `tenant_id`.
- Perfis: admin, gerente, caixa, garçom, cozinha, entregador e promotor.
- Garçom e entregador não confirmam pagamentos.
- Entregador visualiza somente pedidos atribuídos a ele.
- Somente perfis autorizados administram gateways e NFC.
- NFC pode ficar vinculado apenas a admin, gerente ou caixa.
- Bloqueio de usuário ou remoção de função financeira revoga automaticamente seus dispositivos NFC.
- Pareamento NFC limitado a cinco tentativas por identificador.
- Identificador bruto de aparelho não é persistido; somente SHA-256.
- Sessão com HttpOnly, SameSite, strict mode e regeneração após login.
- CSRF em operações autenticadas.
- Rate limit de login: cinco falhas na janela de 15 minutos bloqueiam a combinação de conta/IP por 15 minutos.
- Cabeçalhos de segurança, HSTS em HTTPS/produção e `no-store` para páginas PHP dinâmicas.
- Credenciais de gateway criptografadas em AES-256-GCM usando `APP_KEY`.
- Auditoria administrativa.
- Migrações versionadas.

## Cardápio, delivery e restaurante

- Categorias e produtos com edição, SKU, preço e estoque.
- Cardápio público responsivo.
- Carrinho isolado por empresa.
- Preço e estoque revalidados no servidor no checkout.
- Delivery com cliente, endereço e cupom.
- Mesas com QR próprio.
- Comandas por mesa.
- Pedido público pelo QR da mesa.
- KDS/cozinha com fluxo `confirmed → preparing → ready`.
- Máquina de estados para pedido.
- Pedido não pago não pode ser finalizado.
- Pedido pago não pode ser cancelado diretamente; exige fluxo financeiro de reembolso.

## Estoque

- Movimentação idempotente por pedido/produto.
- Pedido de mesa compromete estoque antes de entrar em preparo.
- Pagamento posterior não duplica a baixa.
- Cancelamento válido de pedido não pago devolve estoque uma única vez.
- Pagamento pré-pago compromete estoque dentro da mesma transação de confirmação.
- Reembolso integral pode devolver estoque quando o operador escolher explicitamente essa opção.

## CRM, cupons e afiliados

- Clientes e pontos.
- Pontos ganhos somente após pagamento confirmado.
- Reversão proporcional de pontos em reembolso.
- Cupons percentuais/fixos, validade, mínimo e limite de usos.
- Reserva de cupom durante checkout para evitar concorrência no último uso.
- Reserva abandonada é liberada pelo cron.
- Promotores/afiliados com código e percentual de comissão.
- Comissão criada por idempotência após pagamento.
- Reembolso parcial recalcula comissão ainda não paga; integral cancela comissão ainda não paga.
- Reembolso é bloqueado quando a comissão daquele pedido já foi marcada como paga, exigindo regularização administrativa antes.

## Eventos e ingressos

- Eventos públicos.
- Lotes com período de venda e disponibilidade.
- Reserva temporária antes de iniciar pagamento.
- Ao abrir checkout, ingresso e cupom passam a usar a mesma validade do checkout do provedor.
- Lista de convidados.
- Ingresso digital e QR.
- Check-in idempotente; somente ingresso pago entra.
- Duplicidade de check-in registrada e bloqueada.
- Ingresso que já realizou check-in não pode ser reembolsado.
- Reembolso integral de ingresso ainda não utilizado invalida o ingresso e devolve a quantidade vendida ao lote.

## Pagamentos

### Checkout

- Stripe Checkout.
- Mercado Pago Checkout Pro.
- PagBank Checkout com PIX e cartão no ambiente do próprio PagBank.
- Pagamento manual restrito a perfis autorizados.

A criação de checkout usa chave de idempotência por tentativa. Se houver timeout, uma repetição antes da validade terminar reutiliza a mesma tentativa/chave, evitando cobrança paralela.

O EventMenu envia a validade do checkout ao próprio provedor:

- Stripe: `expires_at`;
- Mercado Pago: `expires`, `expiration_date_from` e `expiration_date_to`;
- PagBank: `expiration_date`.

A validade também é gravada em `payments.checkout_expires_at` e usada para estender a reserva interna. Dessa forma o link do provedor não deve continuar pagável depois que o EventMenu liberar ingresso/cupom.

### Confirmação

O frontend, PWA ou futuro aplicativo Android **não têm autoridade para marcar pagamento como aprovado**.

A confirmação exige, no servidor:

- assinatura/autenticidade do webhook;
- consulta servidor-a-servidor ao provedor;
- empresa correta;
- provedor correto;
- conta recebedora correta;
- pedido correto;
- valor correto;
- moeda correta;
- ID externo único;
- idempotência do pagamento e do webhook.

A transação de confirmação atualiza de forma coordenada pagamento, pedido, estoque, ingressos, cupom, pontos e comissão.

### Conciliação de checkout expirado

Antes de liberar uma reserva ligada a checkout expirado, o cron consulta o provedor novamente:

- Stripe: consulta a Checkout Session e, se necessário, o PaymentIntent;
- Mercado Pago: pesquisa pagamentos pela `external_reference` e consulta a preferência;
- PagBank: consulta o checkout e os pagamentos/cobranças associados.

Se encontrar pagamento aprovado, o EventMenu confirma o pedido normalmente. Se o provedor confirmar que o checkout expirou sem pagamento, a tentativa local é cancelada e a rotina de manutenção pode liberar a reserva.

Caso excepcional em que uma chamada tenha sofrido timeout antes de o EventMenu receber qualquer ID externo é tratado de forma conservadora: a cobrança fica para revisão/retry com a mesma idempotência, em vez de liberar estoque/ingresso correndo o risco de existir um pagamento desconhecido.

### Reembolsos

- Ledger próprio em `refunds`.
- Idempotência local e no provedor.
- Valores em `processing` já reservam saldo, evitando dois operadores reembolsarem acima do valor pago.
- Stripe total/parcial.
- Mercado Pago total/parcial.
- PagBank cancelamento/estorno por valor.
- Reembolso manual.
- Cron reconcilia reembolsos que permanecerem em processamento.
- Timeout sem ID externo pode ser repetido com a mesma idempotência.
- Pagamento passa para `partially_refunded` ou `refunded` conforme o saldo devolvido.
- Ingressos aceitam somente reembolso integral nesta versão e não podem estar com check-in realizado.

## Webhooks

- Eventos deduplicados.
- Isolamento por empresa.
- Stripe valida `Stripe-Signature`.
- PagBank valida `x-authenticity-token`.
- Mercado Pago valida a assinatura e depois consulta o recurso na API.
- Webhook repetido não duplica estoque, pontos, cupom, ingresso ou comissão.

## PWA

- `manifest.webmanifest`.
- Ícones.
- Service Worker.
- Fallback offline.
- O Service Worker guarda somente arquivos estáticos.
- Painel, pedido, ingresso, carrinho e demais páginas PHP dinâmicas usam `no-store`.

## Rotina agendada

Execute:

```bash
php /caminho/do/eventmenu/bin/cron.php
```

Sugestão a cada minuto:

```cron
* * * * * /usr/bin/php /caminho/do/eventmenu/bin/cron.php >> /caminho/do/eventmenu/storage/cron.log 2>&1
```

A ordem do cron é deliberada:

1. conciliar checkouts expirados nos gateways;
2. liberar reservas realmente abandonadas;
3. conciliar reembolsos em processamento;
4. limpar registros antigos de bloqueio de login.

## GitHub Actions / CI

`.github/workflows/ci.yml` executa:

- `composer validate`;
- instalação das dependências;
- lint de todos os PHP;
- smoke tests;
- MySQL 8 temporário;
- schema base + todas as migrações;
- verificação de tabelas críticas;
- verificação da coluna de expiração do checkout.

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

## Antes de produção com dinheiro real

- HTTPS válido e `SESSION_SECURE=true`.
- `APP_KEY` forte, exclusiva e com backup seguro.
- Banco com usuário dedicado e privilégio mínimo necessário.
- Backup automático do banco.
- Cron ativo.
- Credenciais de sandbox configuradas por empresa.
- Testar pagamento aprovado, recusado, atrasado, webhook repetido e webhook fora de ordem.
- Testar timeout durante criação do checkout e retry da mesma tentativa.
- Testar checkout expirado + conciliação.
- Testar reembolso total/parcial e timeout durante reembolso.
- Testar valor, moeda, conta e pedido divergentes.
- Testar concorrência de estoque, cupom e lote de ingresso.
- Homologar PagBank antes de produção.
- Somente depois trocar credenciais de sandbox por produção.

## Próxima fase

O web/PWA deve passar por homologação prática com credenciais sandbox. Depois dessa estabilização começa o aplicativo nativo. O pagamento por aproximação/NFC real do PagBank será conectado no app nativo, mantendo a regra já existente: **o servidor nunca aceitará apenas a palavra do aplicativo de que o pagamento foi aprovado**.
