# EventMenu Desktop 0.2.0

Cliente nativo para Windows conectado ao mesmo servidor do EventMenu Web e do EventMenu GO.

## Arquitetura

```text
EventMenu Desktop (Windows)
          |
          | HTTPS / API
          v
EventMenu Web / servidor
          |
          v
Banco central da empresa
```

O Desktop **não substitui o servidor Web** e não cria um segundo banco principal. Permissões, estoque, pedidos, pagamentos e consistência continuam sendo validados pelo backend.

## Funcionalidades implementadas

### Segurança e sessão

- aplicativo WPF nativo em .NET 8, sem WebView;
- login usando a API real do EventMenu;
- `device_id` persistente por computador;
- access token + refresh token;
- renovação automática da sessão;
- tokens protegidos no Windows com DPAPI (`CurrentUser`);
- logout com revogação no servidor;
- URL do servidor somente por ambiente de build/execução e obrigatoriamente HTTPS;
- permissões efetivas fornecidas pelo servidor controlam as funções visíveis.

### Operação

- seleção de unidade operacional;
- início e encerramento de turno conforme a função do usuário;
- modos Operação, Delivery, Eventos e Pay conforme permissões;
- atualização automática de pedidos e mesas;
- indicador de conexão com o servidor.

### PDV

- pesquisa de produtos por nome ou SKU;
- carrinho com quantidade, remoção e total;
- validação local de estoque para melhor experiência e validação definitiva no servidor;
- pedidos de balcão, retirada, delivery e mesa;
- cliente, telefone, endereço e observações;
- criação do pedido diretamente no servidor;
- pedidos de mesa exigem comanda aberta;
- atualização de catálogo e estoque após a venda.

### Pedidos

- lista operacional conforme usuário, unidade e turno;
- mudança de status por permissões do servidor;
- fluxo de preparo, pronto e conclusão;
- impressão do pedido pela fila de impressoras do Windows.

### Mesas e comandas

- listagem de mesas;
- abertura de comanda;
- identificação da comanda;
- fechamento de comanda somente quando o servidor permitir;
- totais e valores em aberto.

### Caixa

- abertura de caixa;
- valor inicial;
- suprimento;
- sangria;
- ajuste com justificativa;
- valor esperado calculado no servidor;
- fechamento com valor contado e diferença apurada pelo backend.

### Pagamentos

- consulta de total pago e saldo restante do pedido;
- recebimento total ou parcial em dinheiro;
- chave de idempotência por intenção de recebimento;
- registro do recebimento no caixa pelo servidor;
- geração de PIX PagBank usando a integração já configurada pela empresa;
- PIX copia e cola;
- QR Code fornecido pelo provedor quando disponível;
- consulta automática da confirmação do PIX;
- pedido cancelado ou concluído não recebe nova cobrança;
- o Desktop nunca considera um pagamento aprovado apenas por informação local.

## Servidor

Por padrão:

```text
https://go.gestao2.store/1/
```

Para staging/migração, defina antes de iniciar ou compilar:

```powershell
$env:EVENTMENU_DESKTOP_API_BASE_URL = "https://staging.exemplo.com/1/"
```

A URL precisa usar HTTPS.

## Executar em desenvolvimento

Requer Windows com .NET 8 SDK:

```powershell
dotnet run --project desktop/EventMenu.Desktop/EventMenu.Desktop.csproj
```

## Publicação Windows

O GitHub Actions gera duas entregas:

1. `EventMenu-Desktop-win-x64.zip` — versão portátil self-contained contendo `EventMenu.Desktop.exe`;
2. `EventMenu-Desktop-Setup.exe` — instalador para Windows x64 com menu Iniciar e opção de atalho na área de trabalho.

O executável publicado é self-contained; o computador do restaurante não precisa ter o .NET 8 instalado separadamente.

Também é possível publicar manualmente com:

```powershell
./desktop/build-windows.ps1
```

## Próximas evoluções

- fluxo específico de cozinha/produção com impressão automática por estação;
- QR Code universal e check-in de eventos no Desktop;
- suporte a periféricos adicionais conforme hardware homologado;
- contingência local segura para leituras e fila controlada apenas das operações que possam ser repetidas com segurança;
- atualização automática assinada do programa;
- assinatura digital do executável/instalador para distribuição pública.

Operações financeiras e dados sensíveis continuam sendo confirmados e autorizados pelo servidor EventMenu.
