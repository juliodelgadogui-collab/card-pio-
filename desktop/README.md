# EventMenu Desktop 0.3.0

Programa **Windows nativo** do EventMenu para operação de restaurante, delivery e eventos.

Tecnologia: **.NET 8 + WPF/XAML**, sem WebView e sem navegador embutido.

O Desktop usa os mesmos dados e regras centrais do EventMenu. O Windows cuida da experiência de operação e dos equipamentos locais; validações sensíveis continuam centralizadas no sistema.

## O que o Desktop cobre

### Operação do restaurante

- login com renovação de sessão;
- seleção de unidade e turno;
- visão geral operacional;
- nova venda / PDV;
- pedidos com busca, filtros, detalhes e histórico;
- aceite e mudança de etapa conforme permissão;
- desconto e cancelamento com solicitação/aprovação;
- fidelidade / pontos;
- mesas e comandas;
- conta dividida por valor, percentual, pessoas ou produtos;
- recebimento por Pix ou dinheiro;
- caixa, suprimento, sangria e fechamento;
- produção, cozinha e expedição;
- estoque.

### Delivery

- triagem por unidade;
- atribuição e transferência de entregador;
- retirada, início de rota, chegada e conclusão;
- pagamento Pix na entrega após a etapa permitida;
- recebimento em dinheiro e troco;
- QR de repasse do dinheiro ao caixa;
- acompanhamento das entregas em rota;
- resumo do turno e comissão.

A posição GPS real é enviada pelo celular do entregador. O Windows acompanha essa posição; não cria localização artificial.

### Eventos

- eventos disponíveis para o turno;
- ingressos e convidados;
- check-in;
- venda no bar;
- retirada de pedido do bar por código;
- acompanhamento operacional conforme permissão.

### QR e identificação

O leitor `Ler QR / código` concentra os fluxos compatíveis do EventMenu, incluindo:

- pedido;
- mesa;
- comanda;
- ingresso;
- convidado;
- funcionário;
- entregador;
- cliente;
- evento;
- dispositivo autorizado;
- repasse de dinheiro do Delivery.

A tela `Meu QR` permite ao funcionário/entregador gerar, exibir, copiar, substituir e revogar sua identificação. O payload local é protegido com DPAPI do Windows e separado por empresa/usuário.

Os QRs exibidos pelo Desktop são renderizados nativamente no WPF a partir da matriz do QRCoder.

### Comprovantes e impressão

- impressão operacional do pedido;
- comprovante não fiscal completo do pedido;
- comprovante não fiscal de uma divisão da comanda;
- impressão pelo sistema do Windows;
- configuração de impressora/equipamentos locais.

Comprovante de venda **não é nota fiscal**. A emissão fiscal fica em módulo separado.

### Nota fiscal

A área `Nota fiscal` foi separada das configurações gerais e pode conter, conforme permissão:

- perfil fiscal por unidade;
- ambiente de homologação/produção;
- CNPJ, IE, regime tributário e endereço;
- séries e numeração;
- CSC;
- certificado A1 e referência de certificado local;
- tributação dos produtos (NCM, CEST, CFOP, CST/CSOSN, PIS, COFINS, IPI etc.);
- diagnóstico de prontidão;
- acompanhamento dos documentos fiscais.

A interface estar disponível não significa que a emissão oficial já esteja homologada. Transmissão real depende de credenciamento, certificado e transmissor fiscal válido para o ambiente utilizado.

## Segurança

- tokens locais protegidos pelo Windows;
- comunicação configurada somente em HTTPS;
- permissões recebidas da conta controlam o que aparece e o que pode ser executado;
- pagamentos não são confirmados apenas porque a interface informou sucesso;
- Pix só é baixado após confirmação do fluxo financeiro;
- desconto/cancelamento/fidelidade seguem regras da conta;
- dados de outra empresa não devem ser reutilizados no cache local;
- QR universal é validado online antes de liberar dados ou ações;
- mensagens de SQL/stack trace não devem aparecer ao operador.

## Diferenças intencionais para o Android

O objetivo é paridade **operacional**, não copiar recursos físicos do telefone.

Ficam no celular:

- GPS contínuo;
- câmera para escanear QR/código;
- biometria Android;
- push/permissões Android;
- Tap On/NFC do telefone enquanto esse módulo estiver pausado.

No Windows, códigos podem ser lidos por scanner USB/teclado, colados ou digitados.

## Integrações locais

O projeto possui infraestrutura para:

- impressoras;
- gaveta;
- tela do cliente;
- conexão com celular autorizado;
- fila de impressão da produção;
- conectores TEF/PINPad.

A integração física de TEF/PINPad e fiscal depende de SDK, credenciais e homologação do fornecedor. O EventMenu não deve simular aprovação financeira ou fiscal.

## Servidor

Base padrão atual do cliente:

```text
https://go.gestao2.store/1/
```

Para outro ambiente:

```powershell
$env:EVENTMENU_DESKTOP_API_BASE_URL = "https://staging.exemplo.com/1/"
```

A URL precisa usar HTTPS.

Alguns módulos novos dependem de endpoints mais recentes. Antes de publicar o Desktop em outro ambiente, conferir compatibilidade do servidor com QR universal, eventos, gerência, aprovações, notificações, fidelidade, conta dividida, comprovantes e fluxo financeiro do Delivery.

## Compilação local

Pré-requisito: SDK .NET 8 em Windows.

```powershell
dotnet restore desktop/EventMenu.Desktop/EventMenu.Desktop.csproj
dotnet build desktop/EventMenu.Desktop/EventMenu.Desktop.csproj -c Release
dotnet publish desktop/EventMenu.Desktop/EventMenu.Desktop.csproj -c Release -r win-x64 --self-contained false
```

O instalador Windows é produzido pelo fluxo já existente no repositório.

**Regra atual deste desenvolvimento:** não executar build, publish, GitHub Actions ou gerar instalador sem autorização explícita antes da compilação.

## Transferência para outra conta/equipe

Consulte também `desktop/HANDOFF_DESKTOP.md`.

Ao transferir o trabalho, informar:

- branch atual;
- commit de referência;
- ambiente do servidor usado;
- recursos ainda dependentes de hardware/homologação;
- que Android, Desktop e servidor possuem responsabilidades diferentes;
- que o Desktop é WPF nativo e não WebView.

Se o repositório for público, outra conta pode clonar/forkar e compilar. Secrets não acompanham fork. Em repositório privado, a nova conta precisa receber acesso ou uma cópia/transferência autorizada.

## Antes de considerar uma versão pronta

1. revisão estática de XAML, code-behind, contratos JSON, permissões e estados;
2. autorização explícita para compilar;
3. build/publish do Windows;
4. teste real de instalação e atualização;
5. teste de login/sessão, turnos, PDV, pedidos, caixa, mesas, divisão de conta, produção, estoque, Delivery, eventos, QR, impressão, notificações, aprovações e fiscal;
6. somente depois classificar a versão como pronta para produção.
