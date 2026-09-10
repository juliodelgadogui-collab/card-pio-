# EventMenu Desktop — handoff

## Escopo

Este diretório contém o **programa Windows nativo** do EventMenu.

- Tecnologia: .NET 8 + WPF/XAML.
- Não usa WebView e não deve voltar a usar navegador embutido.
- O APK Android é outro projeto e não deve ser alterado por mudanças exclusivas do Desktop.
- O servidor continua sendo a fonte de verdade para pedidos, permissões, pagamentos, estoque, QR, fidelidade e documentos fiscais.
- A branch de trabalho atual é `wip/eventmenu-desktop-hardware-fiscal-code`; não fazer merge em `main` sem aprovação explícita.

## Módulos já existentes no Desktop

### Operação

- Login e sessão segura com refresh token.
- Seleção de unidade e turno.
- Visão geral operacional.
- Nova venda / PDV.
- Pedidos, filtros, detalhes completos, histórico e atualização de status.
- Aceite de pedido conforme permissão.
- Desconto e cancelamento por solicitação/aprovação.
- Fidelidade / uso e remoção de pontos.
- Mesas e comandas com visão de salão.
- Divisão da conta por valor, percentual, pessoa ou produtos.
- Recebimento da comanda por Pix ou dinheiro.
- Caixa, sangria/suprimento e fechamento.
- Produção / cozinha / expedição.
- Estoque operacional.

### Delivery

- Triagem de pedidos por unidade.
- Atribuição de entregador.
- Minhas entregas: retirada, início de rota, chegada e conclusão.
- Pix na entrega somente depois da etapa permitida pelo servidor.
- Recebimento em dinheiro e cálculo de troco.
- Repasse do dinheiro do entregador para o caixa por QR.
- Entregas ao vivo e acompanhamento da posição enviada pelo celular.
- Resumo do turno e comissão de entrega.

### Eventos

- Visão de eventos.
- Ingressos e convidados.
- Check-in.
- Operação de bar.
- Retirada de pedidos do evento por código.
- Resumo operacional do evento conforme permissão.

### QR e identificação

- Leitor único de QR/código para pedido, mesa, comanda, ingresso, convidado, funcionário, entregador, cliente, evento, dispositivo autorizado e repasse de dinheiro.
- `Meu QR` para identificação do funcionário/entregador.
- QR de pareamento com celular.
- QRs são renderizados nativamente pelo WPF a partir da matriz gerada pelo QRCoder.
- `Meu QR` é armazenado localmente com DPAPI/CurrentUser e isolado por empresa, usuário e tipo de QR.

### Comprovantes, impressão e fiscal

- Impressão de pedido operacional.
- Comprovante não fiscal completo do pedido.
- Comprovante não fiscal específico de cada divisão de comanda.
- Impressão pelo sistema de impressão do Windows.
- Configuração de equipamentos do computador.
- Central de Nota Fiscal separada das configurações de hardware.
- Configuração fiscal, tributação de produtos, certificado A1 e documentos fiscais.
- Estrutura de TEF/PINPad local existente, mas NFC/Tap On não deve ser expandido nesta etapa.

### Gestão

- Central gerencial com indicadores e alertas.
- Situação de caixas e entregadores.
- Reabertura controlada de pedidos.
- Transferência de entrega quando autorizada.
- Central de notificações.
- Central de aprovações.
- Meu turno.

## Paridade com EventMenu GO (Android)

O objetivo do Desktop é oferecer em tela grande as funções operacionais do Android que fazem sentido em Windows, usando os mesmos contratos do servidor.

Funções Android que **não devem ser imitadas artificialmente no Desktop**:

- GPS contínuo: a posição real continua sendo enviada pelo celular do entregador; o Windows apenas acompanha.
- Scanner por câmera: no Windows o leitor aceita scanner USB/teclado, colagem ou digitação.
- Biometria Android.
- Permissão/push nativo Android.
- Tap On/NFC do telefone, enquanto esse módulo estiver pausado.

Essas diferenças são intencionais e não representam função faltando no Desktop.

## Regras de arquitetura e segurança

1. Não confiar no Desktop para confirmar sozinho pagamentos, fidelidade, descontos, cancelamentos ou autorizações fiscais.
2. Permissões de interface devem acompanhar as permissões devolvidas pelo servidor.
3. O servidor é a autoridade final para valor, moeda, conta, pedido, tenant, unidade e estado do pagamento.
4. Credenciais de gateway, certificado e segredos não devem ser gravados em código-fonte.
5. Configuração fiscal é separada da configuração de equipamentos.
6. O usuário comum não deve receber mensagens de infraestrutura, SQL, API ou stack trace.
7. Funcionalidade sem endpoint real não deve ganhar botão fictício.
8. Entregador/garçom não devem confirmar pagamentos fora dos fluxos autorizados.
9. QR universal deve continuar validando empresa, usuário, turno, validade e permissão no servidor.
10. Não substituir regras do servidor por validação exclusivamente local.

## QR Code

### Pareamento do celular

1. Desktop registra heartbeat da unidade.
2. Servidor cria token temporário de uso único.
3. Desktop renderiza `EVENTMENU:HUB:<token>` como QR nativo.
4. Se o servidor devolver somente o token, o Desktop monta o payload localmente.
5. O código manual permanece como alternativa.

### QR universal

O servidor emite `EVENTMENU:QR:<token>` para entidades autorizadas. O Desktop resolve o QR online e exibe apenas os dados/ações permitidos para o usuário atual.

O servidor armazena somente o hash do token. Por isso, no `Meu QR`, o payload necessário para reexibição é mantido no computador usando DPAPI do usuário Windows. Gerar um novo QR revoga/substitui o anterior; revogar invalida o código no servidor.

## Pagamentos e comandas

A conta dividida suporta:

- por valor;
- por percentual;
- por pessoa;
- por produtos inteiros da comanda.

Cada cobrança usa o estado real da conta e não permite iniciar outra enquanto existir cobrança incompatível em andamento. Pix só é considerado pago depois da confirmação do fluxo financeiro do servidor.

Após pagamento confirmado, o Desktop pode abrir o comprovante daquela parte da conta. Comprovantes são **não fiscais** e não substituem NFC-e/NF-e/NFS-e.

## Nota fiscal

A área **Nota fiscal** fica disponível conforme permissão:

- `fiscal_manage`: configura empresa, ambiente, séries, CSC, certificado e tributação dos produtos.
- `fiscal_issue`: acompanha/prepara documentos conforme regras do servidor.

A existência da tela não significa que transmissão real esteja homologada. A emissão oficial depende do transmissor SEFAZ/provedor configurado, credenciais válidas e homologação aplicável.

## Compatibilidade com servidor

Alguns módulos novos possuem contratos mais recentes do que o servidor originalmente implantado. O Desktop já contém fallbacks conhecidos para produção, estoque e monitor de entregas quando possível.

Antes de publicar nova versão, conferir se o servidor utilizado possui os endpoints necessários para:

- QR universal;
- eventos avançados;
- painel gerencial;
- aprovações;
- notificações;
- fidelidade;
- conta dividida;
- comprovante de divisão;
- fluxo financeiro do Delivery.

Não modificar o servidor a partir do trabalho exclusivo do Desktop sem autorização específica.

## Compilação local

Pré-requisito: SDK .NET 8 em Windows.

```powershell
dotnet restore desktop/EventMenu.Desktop/EventMenu.Desktop.csproj
dotnet build desktop/EventMenu.Desktop/EventMenu.Desktop.csproj -c Release
dotnet publish desktop/EventMenu.Desktop/EventMenu.Desktop.csproj -c Release -r win-x64 --self-contained false
```

A criação do instalador está documentada no workflow Windows existente do repositório.

**Regra atual do projeto:** não disparar build, publish, GitHub Actions ou instalador sem autorização explícita antes da compilação.

## Outra conta / transferência

Um repositório público pode ser clonado ou bifurcado por outra conta e compilado normalmente. **Secrets não acompanham forks**. Chaves de assinatura, certificados, tokens de publicação e outros segredos precisam ser configurados novamente na conta/repositório responsável pelo build.

Para repositório privado, a outra conta precisa receber acesso ou receber uma transferência/cópia autorizada.

Ao transferir o desenvolvimento, informar claramente:

- branch em uso;
- último commit validado;
- quais APIs estão implantadas no ambiente real;
- quais recursos dependem de hardware/homologação;
- que Android e Windows são projetos separados;
- que o Desktop é WPF nativo e não WebView.

## Antes de uma nova entrega

- Fazer revisão estática de XAML, code-behind, modelos JSON, permissões e estados.
- Confirmar compatibilidade com a versão do servidor implantada.
- Só disparar build depois de autorização explícita do responsável pelo projeto.
- Após o build, testar instalação e runtime; compilação bem-sucedida sozinha não significa validação funcional.
- Validar login, sessão/refresh, turnos, PDV, pedidos, caixa, mesas, divisão de conta, produção, estoque, Delivery, eventos, QR, impressão, notificações, aprovações e fiscal.
