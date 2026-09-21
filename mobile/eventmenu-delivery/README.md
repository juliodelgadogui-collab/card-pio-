# EventMenu Delivery

Aplicativo Android nativo para consumidor final integrado ao EventMenu.

## Arquitetura

O app não possui regra financeira própria e não opera pedidos fora do EventMenu Server.

Fluxo:

`Consumidor -> EventMenu Delivery -> api-marketplace.php -> orders -> Desktop/GO/KDS -> Entregador/GPS -> Consumidor`

O marketplace usa o mesmo pedido canônico do EventMenu. `channel=delivery` define a operação e `order_source=EVENTMENU_DELIVERY` identifica a origem comercial.

## Contrato atual

- descoberta de empresas participantes;
- busca de lojas;
- catálogo por unidade;
- categorias;
- produtos e adicionais;
- disponibilidade validada no Server;
- carrinho nativo;
- checkout com nome, telefone e endereço;
- sessão de checkout assinada, curta e de uso único;
- criação do pedido no Server;
- acompanhamento por status amigável;
- GPS público temporário/revogável quando a rota estiver ativa;
- restauração do pedido ativo pelo `public_token` salvo no armazenamento privado do app.

## Segurança

O consumidor não envia `order_source`, `tenant_id` ou `unit_id` livremente no checkout. A origem e a unidade são obtidas de uma sessão assinada emitida pelo Server para uma loja participante. Tokens reutilizados ou adulterados devem ser rejeitados.

O app não recebe comissão, regra financeira, credenciais de gateway, IDs de entregador ou configurações internas da empresa.

## Pagamentos

O pagamento do consumidor ainda não foi ligado ao app nesta etapa. O Pix atual do EventMenu depende de contexto operacional autenticado e não deve ser reutilizado simulando uma sessão de funcionário. A integração pública deverá ser feita por uma entrada segura no motor central de pagamentos, preservando webhook, idempotência e confirmação pelo provedor.

## Build

Base tecnológica alinhada ao EventMenu GO:

- Android nativo;
- Kotlin;
- Jetpack Compose;
- minSdk 23;
- targetSdk 36;
- Java 17;
- Gradle 9.3.1 nos scripts auxiliares.

O endereço do Server vem de `EVENTMENU_DELIVERY_API_BASE_URL`; o padrão atual é o endpoint oficial EventMenu.

> Estado deste branch nesta rodada: IMPLEMENTADO no código. Build, Lint e testes não foram executados porque a execução foi explicitamente bloqueada até nova autorização.
