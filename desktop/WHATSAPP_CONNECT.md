# EventMenu WhatsApp Connect

## Objetivo

O WhatsApp de cada estabelecimento roda no próprio computador do cliente, usando o EventMenu WhatsApp Connect + Node.js + Baileys. O servidor EventMenu mantém somente configuração, status e a fila de mensagens.

Fluxo:

`EventMenu Cloud -> whatsapp_outbox -> HTTPS autenticado -> EventMenu WhatsApp Connect -> Baileys local -> WhatsApp`

Nenhuma porta precisa ser aberta no roteador do restaurante. O computador sempre inicia as conexões de saída.

## Segurança e isolamento

- O Desktop reutiliza a sessão autenticada do EventMenu Desktop e o mesmo `device.id`.
- A API sempre resolve o `tenant_id` pelo token autenticado; o Desktop não escolhe a empresa por parâmetro.
- As mensagens são reservadas por `claim_token` e `device_hash`, com lease de 2 minutos.
- Um computador não consegue confirmar a mensagem reservada por outro computador.
- O segredo da bridge local e a chave da sessão ficam protegidos por DPAPI em `%LocalAppData%\EventMenu\WhatsApp\<tenantId>\settings.dat`.
- As credenciais do Baileys ficam somente no PC do estabelecimento, em `%LocalAppData%\EventMenu\WhatsApp\<tenantId>\sessions`.
- A bridge local escuta somente em `127.0.0.1`.

## Servidor

Aplicar a migration `067_whatsapp_desktop_connect.sql` correspondente ao driver do banco.

Para implantação exclusivamente pelo Desktop, configurar:

```env
WHATSAPP_BRIDGE_ENABLED=false
```

Isso impede o dispatcher antigo do servidor de disputar a mesma fila. A criação dos eventos em `whatsapp_outbox` continua funcionando normalmente.

Endpoint do agente local:

`/api-whatsapp-desktop.php`

Ações disponíveis: `state`, `heartbeat`, `claim`, `ack` e `fail`.

## Windows

O instalador inclui:

- `EventMenu.Desktop.exe`
- `EventMenu.WhatsAppConnect.exe`
- Node.js 22.18.0 local
- worker Baileys e dependências de produção

O cliente não precisa instalar Node.js nem usar Terminal.

Na instalação existe a opção de iniciar o WhatsApp Connect junto com o Windows. Ao fechar a janela pelo X, ele continua na bandeja do sistema. Para encerrar de verdade, use `Sair` no ícone próximo ao relógio.

## Primeiro uso

1. Entrar normalmente no EventMenu Desktop.
2. Abrir `EventMenu WhatsApp Connect` pelo Menu Iniciar.
3. Clicar em `Conectar / gerar QR`.
4. No celular: WhatsApp -> Aparelhos conectados -> Conectar um aparelho.
5. Escanear o QR.
6. Deixar o computador ligado durante o funcionamento do estabelecimento.

Depois do primeiro pareamento, a sessão é recuperada automaticamente. Se o PC ficar offline, as mensagens permanecem na fila e voltam a ser processadas quando o agente retornar.

## Build

No Windows, `desktop/build-windows.ps1` publica os dois executáveis, baixa o Node.js oficial e instala as dependências do worker no pacote final. O workflow `.github/workflows/desktop-windows.yml` executa esse processo e gera os artifacts do pacote portátil e do instalador Inno Setup.
