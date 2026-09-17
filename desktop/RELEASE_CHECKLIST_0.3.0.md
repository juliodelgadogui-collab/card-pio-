# EventMenu Desktop 0.3.0 — Checklist de validação

Estado desta branch: código preparado para validação final. Este documento **não** afirma que a versão foi compilada, instalada ou homologada.

## 1. Validação de código

- [x] Versão do projeto alinhada em 0.3.0.
- [x] Script de build alinhado em 0.3.0.
- [x] Instalador alinhado em 0.3.0.
- [x] Ícone EventMenu configurado no executável e no instalador.
- [x] Menu organizado em Operação, Delivery, Eventos, Gestão e Ferramentas.
- [x] Destaque de navegação aplicado também aos módulos adicionados dinamicamente.
- [x] Atualização de permissões limitada para evitar consultas excessivas.
- [x] Hub com cadência reduzida e backoff progressivo.
- [x] Falha de Hub/produção/hardware não interrompe PDV, caixa ou pedidos.
- [x] Detecção de módulos opcionais não implantados no servidor.
- [x] Módulos incompatíveis deixam de ser consultados repetidamente na sessão.
- [x] Configurações comuns simplificadas; campos técnicos ficam em áreas avançadas.
- [x] Camada central de mensagens amigáveis adicionada aos fluxos compartilhados.

## 2. Build — exige autorização

Executar somente após autorização explícita:

1. `dotnet restore`
2. `dotnet build -c Release`
3. `dotnet publish -c Release -r win-x64 --self-contained true`
4. validar saída em `artifacts/EventMenu-Desktop-win-x64`
5. gerar instalador Inno Setup
6. validar versão e ícone do EXE/Setup

## 3. Testes funcionais obrigatórios

Testar com contas reais de teste e permissões diferentes:

- Administrador
- Gerente
- Caixa
- Atendente/Garçom
- Cozinha/Produção
- Entregador

Fluxos mínimos:

- login, renovação de sessão e logout;
- seleção de unidade e abertura/fechamento de turno;
- nova venda, estoque e bloqueio de item sem saldo;
- pedidos e alteração de status;
- mesas/comandas e divisão de conta;
- Pix e dinheiro;
- caixa e movimentos;
- produção/expedição;
- delivery e acompanhamento operacional;
- eventos, QR e Meu QR;
- aprovações e notificações;
- comprovantes e impressão não fiscal;
- configuração de equipamentos;
- Hub e pareamento com celular;
- fiscal em homologação.

## 4. Hardware e fiscal — homologação real

Estas áreas só podem ser consideradas prontas para produção depois de teste físico/credenciado:

- impressora térmica real;
- abertura de gaveta;
- leitor de código de barras;
- balança, quando utilizada;
- tela do cliente;
- PINPad/TEF com provedor contratado;
- certificado digital A1/A3;
- NFC-e/NF-e em homologação e depois produção;
- contingência, cancelamento, consulta e inutilização fiscal.

## 5. Critério para marcar 0.3.0 como produção

A versão só deve ser marcada como produção depois que:

- build Release terminar sem erros;
- instalador instalar e desinstalar corretamente em Windows limpo;
- atualização sobre uma instalação anterior preservar sessão/configurações quando aplicável;
- todos os perfis de usuário acima forem testados;
- equipamentos contratados forem testados fisicamente;
- fluxo fiscal estiver homologado com credenciais reais.
