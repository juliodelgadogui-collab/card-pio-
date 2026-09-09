# EventMenu Desktop 0.2.0

Cliente nativo para Windows conectado ao mesmo servidor do EventMenu Web e do EventMenu GO.

## Arquitetura

```text
EventMenu GO (Android) ── HTTPS ──┐
                                 │
EventMenu Desktop ───── HTTPS ───┼── EventMenu Web / servidor ── banco central
       │                         │
       └── hardware local        │
           impressora            │
           gaveta                │
           PINPad/TEF            │
           tela do cliente       │
                                 │
Cardápio / operação Web ─────────┘
```

O Desktop **não substitui o servidor Web** e não cria um segundo banco principal. Permissões, estoque, pedidos, pagamentos e consistência continuam sendo validados pelo backend.

## Segurança e sessão

- WPF nativo em .NET 8, sem WebView;
- login usando a API real do EventMenu;
- `device_id` persistente por computador;
- access token + refresh token e renovação automática;
- tokens protegidos no Windows com DPAPI (`CurrentUser`);
- URL do servidor obrigatoriamente HTTPS;
- permissões efetivas do servidor controlam as funções disponíveis;
- segredos de gateways, TEF, webhook e certificado não são exibidos na operação comum.

## Operação / PDV

- seleção de unidade e turno;
- modos Operação, Delivery, Eventos e Pay conforme permissão;
- pesquisa de produtos e carrinho;
- pedidos de balcão, retirada, delivery e mesa;
- mesas/comandas;
- atualização de pedidos e estoque pelo servidor;
- fluxo de preparo, pronto e conclusão;
- caixa com abertura, suprimento, sangria, ajuste e fechamento;
- impressão pela infraestrutura do Windows.

## Pagamentos

- consulta do saldo restante do pedido;
- recebimento total ou parcial em dinheiro;
- PIX PagBank com confirmação consultada no servidor;
- idempotência nas intenções de cobrança;
- pedidos cancelados/finalizados não recebem nova cobrança;
- aprovação informada por aplicativo, Desktop ou PINPad nunca liquida o pedido sozinha.

## EventMenu Hub

O Hub conecta o EventMenu GO a um computador EventMenu Desktop da mesma empresa/unidade.

- pareamento por QR Code temporário e de uso único;
- vínculo por dispositivo e usuário;
- indicador de computador online/offline por heartbeat;
- comandos com fila, expiração e idempotência;
- impressão de pedido e recibo pelo celular;
- abertura de gaveta conforme permissão;
- aviso/alerta no computador;
- tela do cliente;
- solicitação de cobrança no PINPad;
- revogação do vínculo sem precisar reinstalar o aplicativo.

O celular solicita a ação; o Desktop executa somente os comandos permitidos para aquele vínculo e o servidor registra o estado.

## TEF / PINPad

O Desktop possui uma ponte para um **conector homologado executando no próprio Windows**. A URL aceita para o conector local é limitada a `localhost`, `127.0.0.1` ou `::1`.

Provedores previstos na configuração:

- PagBank / PlugPag ou TEF;
- Stone TEF;
- SiTef;
- conector TEF genérico.

O fluxo financeiro usa uma intenção `tef` própria no servidor. Um resultado como `approved_local` significa apenas que o equipamento local reportou aprovação. A liquidação do pedido depende da etapa de verificação do provedor/servidor.

A integração física de cada adquirente depende do SDK/conector e da homologação fornecidos por ela. O EventMenu não simula essa aprovação.

## NFC-e / NF-e

A camada fiscal já prepara a operação antes da transmissão:

- perfil fiscal por unidade;
- homologação/produção;
- CNPJ, IE, regime tributário e endereço completo do emitente;
- séries e numeração de NFC-e/NF-e;
- CSC protegido;
- certificado A1 no servidor, Windows ou modo híbrido;
- referência para certificado A3 local;
- certificado local A1 protegido por DPAPI;
- NCM, CEST, CFOP, unidade, origem, GTIN, CST/CSOSN, PIS, COFINS, IPI e benefício fiscal por produto;
- editor tributário de produtos no Desktop;
- diagnóstico de prontidão de perfil, certificado e produtos;
- NF-e exige destinatário fiscal completo;
- documento só entra na fila depois do pedido estar pago e dos dados obrigatórios estarem completos;
- snapshot fiscal criptografado e com hash antes da transmissão, preservando exatamente os dados usados naquela emissão.

### Contrato do transmissor SEFAZ

`FiscalTransmitterInterface` define o ponto de integração com o transmissor homologado. A máquina de estados interna usa:

```text
queued -> processing -> authorized
                    -> rejected
                    -> error
```

Para aceitar `authorized`, o transmissor precisa retornar uma resposta previamente verificada contendo, no mínimo:

- `verified=true`;
- chave de acesso com 44 dígitos;
- protocolo;
- XML fiscal válido;
- chave de acesso compatível com o XML.

Sem um transmissor real configurado, `UnavailableFiscalTransmitter` falha de forma segura. O EventMenu **não cria protocolo, chave ou autorização fictícios**.

Rejeições verificadas ficam como `rejected`. Falhas técnicas ficam como `error` e podem voltar à fila para nova tentativa sem alterar o snapshot fiscal. Uma rejeição fiscal não é transformada automaticamente em nova nota.

## Servidor

Por padrão:

```text
https://go.gestao2.store/1/
```

Para staging/migração:

```powershell
$env:EVENTMENU_DESKTOP_API_BASE_URL = "https://staging.exemplo.com/1/"
```

A URL precisa usar HTTPS.

## Banco de dados

As estruturas novas possuem migrações para MySQL e SQLite, incluindo:

- hardware do Desktop;
- intenções TEF;
- EventMenu Hub;
- provedor financeiro `tef`;
- dados fiscais do emitente, destinatário e produtos;
- snapshot fiscal criptografado.

As migrações devem ser aplicadas pelo processo normal de instalação/atualização do EventMenu. Não devem ser executadas manualmente fora do controle de versão em produção.

## Publicação Windows

A estrutura de publicação existente prevê:

1. `EventMenu-Desktop-win-x64.zip` — versão portátil self-contained;
2. `EventMenu-Desktop-Setup.exe` — instalador Windows x64.

Nesta etapa de desenvolvimento de hardware/fiscal, os arquivos foram alterados em código sem executar compilação ou publicação.

## Pendências externas antes de produção fiscal/TEF

- instalar e homologar o SDK/conector da adquirente escolhida;
- implementar o adaptador real que verifica a transação TEF no provedor quando exigido;
- implementar/configurar o transmissor SEFAZ real para o estado/ambiente utilizado;
- validar certificado, CSC e credenciamento do emitente em homologação;
- validar NCM/CFOP/CST/CSOSN e demais regras com o responsável fiscal/contador;
- testar impressora, gaveta, PINPad e certificados no hardware real;
- somente depois liberar produção.

Operações financeiras e fiscais sensíveis continuam sendo confirmadas pelo servidor EventMenu e pelos provedores oficiais correspondentes.
