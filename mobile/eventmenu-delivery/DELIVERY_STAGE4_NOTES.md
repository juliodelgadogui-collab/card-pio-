# DELYVRE — Etapa 4/5

## Repetir pedido

O app já refaz a consulta do cardápio atual antes de montar o carrinho de um pedido anterior. Assim, produto indisponível e preço histórico não são tratados como fonte de verdade.

Limitação atual do contrato do servidor: o endpoint `reorder` devolve produto e quantidade, mas não entrega de forma confiável observações e adicionais históricos. Por isso o app não deve reconstruir esses dados inventando IDs ou preços antigos. Grupos obrigatórios continuam sendo validados pelo catálogo atual antes do checkout.

## Favoritos

O backend atual suporta favoritos de restaurantes. Não existe contrato de favorito de produto no DELYVRE; nenhum armazenamento paralelo foi criado.

## Push

A implementação existente de FCM foi mantida. Esta etapa adiciona filtro de eventos de ciclo de pedido e deduplicação de notificações repetidas, preservando o deep link para o pedido correto.
