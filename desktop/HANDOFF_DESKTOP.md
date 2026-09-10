# EventMenu Desktop — handoff

## Escopo

Este diretório contém o **programa Windows nativo** do EventMenu.

- Tecnologia: .NET 8 + WPF/XAML.
- Não usa WebView e não deve voltar a usar navegador embutido.
- O APK Android é outro projeto e não deve ser alterado por mudanças exclusivas do Desktop.
- O servidor continua sendo a fonte de verdade para pedidos, permissões, pagamentos, estoque e documentos fiscais.

## Módulos já existentes no Desktop

- Login e sessão segura com refresh token.
- Seleção de unidade e turno.
- Visão geral da operação.
- Nova venda / PDV.
- Pedidos e atualização de status.
- Mesas e comandas.
- Caixa, sangria/suprimento e fechamento.
- Recebimento em dinheiro e Pix.
- Impressão de pedidos e comprovantes.
- Produção / cozinha / expedição.
- Estoque operacional.
- Entregas ao vivo.
- Conexão com celular pelo EventMenu Hub.
- QR Code de pareamento gerado nativamente no WPF.
- Configuração de equipamentos do computador.
- Configuração fiscal, tributação de produtos, certificado A1 e documentos fiscais.
- Estrutura de TEF/PINPad local preparada para conectores homologados.

## Regras de arquitetura

1. Não confiar no Desktop para confirmar sozinho pagamentos ou autorizações fiscais.
2. Permissões de interface devem acompanhar as permissões devolvidas pelo servidor.
3. Credenciais de gateway, certificado e segredos não devem ser gravados em código-fonte.
4. Configuração fiscal é separada da configuração de equipamentos.
5. O usuário comum não deve receber mensagens de infraestrutura, SQL, API ou stack trace.
6. Funcionalidade sem endpoint real não deve ganhar botão fictício.

## QR Code

O pareamento com celular segue este fluxo:

1. Desktop registra heartbeat da unidade.
2. Servidor cria token temporário de uso único.
3. Desktop renderiza `EVENTMENU:HUB:<token>` como QR Code nativo WPF.
4. Se o servidor devolver somente o token, o Desktop monta o payload localmente.
5. O código manual permanece como alternativa.

O Desktop usa QRCoder somente para calcular a matriz do QR; a imagem é desenhada pelo próprio WPF, sem PNG temporário e sem WebView.

## Nota fiscal

A área **Nota fiscal** fica disponível conforme permissão:

- `fiscal_manage`: configura empresa, ambiente, séries, CSC, certificado e tributação dos produtos.
- `fiscal_issue`: acompanha/prepara documentos conforme regras do servidor.

A configuração não significa que transmissão real está homologada. A emissão oficial depende do transmissor SEFAZ/provedor configurado e de homologação válida.

## Compilação local

Pré-requisito: SDK .NET 8 em Windows.

```powershell
dotnet restore desktop/EventMenu.Desktop/EventMenu.Desktop.csproj
dotnet build desktop/EventMenu.Desktop/EventMenu.Desktop.csproj -c Release
dotnet publish desktop/EventMenu.Desktop/EventMenu.Desktop.csproj -c Release -r win-x64 --self-contained false
```

A criação do instalador está documentada no workflow Windows existente do repositório.

## Outra conta / fork

Um repositório público pode ser clonado ou bifurcado por outra conta e compilado normalmente. **Secrets não acompanham forks**. Qualquer segredo de assinatura, certificado, Firebase, gateway ou publicação precisa ser cadastrado novamente na conta/repositório que fará o build.

Para repositório privado, a outra conta precisa receber acesso ao repositório ou receber uma transferência/cópia autorizada.

## Antes de uma nova entrega

- Fazer revisão estática das telas e contratos.
- Confirmar compatibilidade com a versão do servidor implantada.
- Só disparar build depois de autorização explícita do responsável pelo projeto.
- Validar instalador, login, PDV, pedidos, caixa, produção, estoque, entregas, QR e fiscal antes de considerar a versão pronta para produção.
