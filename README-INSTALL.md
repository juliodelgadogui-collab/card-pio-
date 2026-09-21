# EventMenu Server 1.0.0 — instalação limpa

Este pacote foi preparado para instalação nova sem banco, clientes, pedidos, senhas, tokens ou sessões de produção.

## Requisitos

- Linux com Bash para `install.sh` (ou use `install.php` pelo navegador em hospedagem sem shell).
- PHP 8.2+.
- Extensões PHP: PDO, pdo_sqlite, mbstring, curl e openssl.
- Apache/LiteSpeed com `.htaccess` ou regras equivalentes no Nginx.
- HTTPS em produção.
- Node.js 22+ somente se a WhatsApp Bridge Beta for instalada.

## Instalação via shell

1. Extraia `eventmenu-server-1.0.0.zip` na pasta destinada ao backend, normalmente `/1`.
2. Torne os scripts executáveis se necessário: `chmod +x install.sh update.sh`.
3. Execute `./install.sh`.
4. Informe URL, empresa inicial, nome/e-mail/senha do Super ADM.
5. O instalador cria `.env`, chaves aleatórias, `storage/eventmenu.sqlite`, schema, migrations e `storage/installed.lock`.
6. Configure o cron exibido ao final para executar a cada minuto.

Para instalação não interativa, defina antes:

- `EVENTMENU_INSTALL_URL`
- `EVENTMENU_INSTALL_TENANT`
- `EVENTMENU_INSTALL_ADMIN_NAME`
- `EVENTMENU_INSTALL_ADMIN_EMAIL`
- `EVENTMENU_INSTALL_ADMIN_PASSWORD`

Nenhuma dessas credenciais faz parte do pacote.

## Instalação pelo navegador

Em hospedagem sem Bash, envie o pacote, abra `/1/install.php` e siga o instalador web. Ele usa o mesmo banco SQLite/migrations e bloqueia uma segunda instalação depois de criar o Super ADM.

## WhatsApp Bridge Beta

A engine atual é Baileys/WhatsApp Web e não exige Chrome/Chromium. Para instalar dependências junto com a primeira instalação use `INSTALL_WHATSAPP_BRIDGE=1 ./install.sh`, ou siga `integrations/whatsapp-worker/README.md`.

Configure um segredo longo e igual nos dois lados:

- PHP: `WHATSAPP_BRIDGE_SECRET`
- Node: `EVENTMENU_WHATSAPP_BRIDGE_SECRET`

As sessões devem ficar fora de `public/`, preferencialmente em `storage/private/whatsapp-sessions` ou diretório privado equivalente.

## Atualização

Use `./update.sh`. O script cria backup do SQLite, preserva `.env` e `storage`, atualiza dependências quando possível e executa somente as migrations pendentes.

Nunca substitua um banco de produção por um banco vazio durante atualização.

## Portal e downloads

O portal público fica em `portal-root/` e foi projetado para `https://go.gestao2.store/`. O backend continua em `https://go.gestao2.store/1/`.

A publicação de APKs exige `EVENTMENU_APK_ADMIN_TOKEN` configurado no ambiente. Não coloque esse segredo em arquivos públicos.
