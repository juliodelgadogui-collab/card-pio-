# EventMenu Desktop

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

O Desktop **não substitui o servidor Web** e não cria um segundo banco principal.

## Primeira etapa implementada

- aplicativo WPF nativo em .NET 8;
- login usando `api.php?action=login`;
- `device_id` persistente por computador;
- token de acesso + refresh token;
- renovação automática da sessão;
- tokens protegidos no Windows com DPAPI (`CurrentUser`);
- identificação da empresa e função do usuário;
- leitura de pedidos conforme permissões do servidor;
- leitura do catálogo para usuários autorizados a criar pedidos;
- leitura da sessão de caixa para quem possui permissão de caixa;
- logout com revogação no servidor;
- endereço do servidor apenas no ambiente de build/execução, sem campo editável para funcionário.

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

## Publicar EXE

```powershell
dotnet publish desktop/EventMenu.Desktop/EventMenu.Desktop.csproj `
  -c Release `
  -r win-x64 `
  --self-contained true `
  -p:PublishSingleFile=true `
  -p:IncludeNativeLibrariesForSelfExtract=true
```

Também existe workflow do GitHub Actions para gerar o pacote do Windows sem exigir compilação no computador local.

## Próximas etapas

1. PDV com carrinho e criação de pedido.
2. Mesas e comandas.
3. Abertura, movimentação e fechamento do caixa.
4. Impressão térmica e fila de impressão.
5. Tela de cozinha/produção.
6. QR Code e check-in para eventos.
7. Contingência local segura para leituras e fila controlada de operações permitidas.
8. Atualizador e instalador do Windows.

Operações financeiras continuam sendo confirmadas pelo servidor; o Desktop não deve marcar pagamento como aprovado apenas com informação local.
