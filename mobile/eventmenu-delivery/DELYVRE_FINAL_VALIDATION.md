# DELYVRE — Validação final 5/5

## Fluxo integrado

A cadeia validada pelo código do app é:

`Home → loja/cardápio → produto/adicionais → carrinho persistente → checkout revisável → criação do pedido → pagamento → pedido → atualização/rastreamento → conclusão`

## Cobertura funcional

- inicialização, login, cadastro e sessão;
- Home, categorias, busca, filtros e favoritos de restaurantes;
- imagens remotas e caminhos relativos tratados pela camada de imagem já existente;
- cardápio e produto com adicionais obrigatórios/opcionais, observação e quantidade;
- carrinho persistente e revalidação contra catálogo atual;
- endereço de entrega e cupom validado pelo servidor;
- criação do pedido somente após revisão;
- PIX, Mercado Pago/cartão e dinheiro somente quando retornados pelo servidor;
- bloqueio de tentativa de pagamento duplicada;
- atualização de pagamento/pedido/rastreamento com backoff e sem limite arbitrário;
- pedir novamente usando produto/preço/disponibilidade atuais;
- FCM existente preservado, filtrado e com deduplicação curta;
- toque da notificação direciona ao pedido correspondente;
- perda de rede, timeout e servidor inacessível com mensagens amigáveis;
- retry automático apenas para leituras GET idempotentes. POSTs de pedido/pagamento não são repetidos automaticamente.

## Dependências reais não mascaradas

### Retirada

O restaurante pode informar `pickup_enabled`, porém o contrato atual de `order-create` exige `address_id` e valida área de entrega. O app mantém retirada bloqueada até o servidor oferecer um contrato explícito de fulfillment/pickup. Não é seguro simular retirada como entrega.

### Repetir pedido — adicionais/observações históricas

O endpoint atual de `reorder` devolve de forma confiável produto e quantidade, mas não fornece todos os adicionais/observações históricos para reconstrução segura. O app consulta o catálogo atual, não reaproveita preço antigo e não inventa IDs históricos. Grupos obrigatórios atuais devem ser selecionados/validados antes do checkout.

## Critério de encerramento

A etapa somente é considerada finalizada depois de:

1. testes unitários verdes;
2. Android Lint verde;
3. APK debug gerado;
4. assinatura e metadata verificadas;
5. Release minificada compilada;
6. PR mesclado em `dev/delyvre`;
7. CI pós-merge verde no SHA resultante.
