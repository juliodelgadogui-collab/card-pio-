# EventMenu WhatsApp Bridge — Beta

Serviço Node local que isola o EventMenu da engine não oficial do WhatsApp. O painel PHP nunca recebe credenciais da sessão nem acessa os arquivos de autenticação diretamente.

A engine atual é o **Baileys**, escolhida para funcionar em hospedagens cPanel compartilhadas sem depender de Chrome/Chromium ou Puppeteer.

## Requisitos

- Node.js 22+
- pasta de sessão fora de diretório público, com acesso restrito ao usuário do serviço
- segredo aleatório com pelo menos 32 caracteres
- acesso de saída HTTPS/WebSocket liberado pela hospedagem

Não é necessário Chrome/Chromium.

## Instalação

```bash
cd integrations/whatsapp-worker
npm install --omit=dev
```

Configure no serviço/process manager:

```text
HOST=127.0.0.1
PORT=21466
EVENTMENU_WHATSAPP_BRIDGE_SECRET=troque-por-um-segredo-longo-e-aleatorio
WHATSAPP_SESSION_ROOT=/var/lib/eventmenu/whatsapp-sessions
```

Em cPanel/Node Selector, normalmente a própria hospedagem injeta `PORT`. Nesse caso, não defina `PORT` manualmente, a menos que o provedor exija.

Inicie com `npm start`.

No `.env` do EventMenu use o mesmo segredo:

```text
WHATSAPP_BRIDGE_ENABLED=true
WHATSAPP_BRIDGE_URL=http://127.0.0.1:21466
WHATSAPP_BRIDGE_SECRET=troque-por-um-segredo-longo-e-aleatorio
```

Se a Bridge estiver publicada por uma URL de aplicação do cPanel, use essa URL HTTPS em `WHATSAPP_BRIDGE_URL`. O segredo continua obrigatório em todas as chamadas.

## Compatibilidade com o EventMenu

As rotas da Bridge foram mantidas para que o PHP não precise ser reescrito:

- `GET /health`
- `GET /v1/sessions/<session_key>`
- `POST /v1/sessions/<session_key>/start`
- `POST /v1/sessions/<session_key>/logout`
- `POST /v1/sessions/<session_key>/send`

O QR Code continua sendo devolvido como `data:image/png;base64,...`, no mesmo campo esperado pela tela `/whatsapp.php`.

## Persistência e reconexão

- cada empresa usa uma chave de sessão aleatória e independente;
- cada sessão é salva em uma subpasta própria dentro de `WHATSAPP_SESSION_ROOT`;
- credenciais são atualizadas em disco quando o WhatsApp altera as chaves da sessão;
- ao reiniciar o serviço, sessões salvas são carregadas e reconectadas automaticamente;
- quedas transitórias usam reconexão com backoff;
- logout manual remove os arquivos daquela sessão e impede reconexão automática até uma nova solicitação.

A implementação usa `useMultiFileAuthState`, adequada para esta fase Beta e para o teste em cPanel. Para escala maior, a própria documentação do Baileys recomenda migrar o armazenamento de autenticação para uma implementação própria em banco de dados.

## Segurança e isolamento

- as rotas rejeitam chaves fora do formato esperado;
- toda chamada exige Bearer secret e comparação timing-safe;
- QR Code fica somente em memória e não é registrado nos logs;
- arquivos de sessão não ficam em `public/`;
- mensagens continuam saindo pela outbox PHP com idempotência e retentativas limitadas;
- nunca publique o segredo, arquivos de sessão, cookies ou QR em logs públicos.

## Migração de WPPConnect

Arquivos de sessão antigos do WPPConnect não são compatíveis com Baileys. A primeira conexão após a troca de engine pode exigir um novo QR Code. Depois de vinculada, a sessão Baileys passa a persistir normalmente em `WHATSAPP_SESSION_ROOT`.

## Importante

Esta integração usa WhatsApp Web por uma biblioteca não oficial. Mudanças do WhatsApp podem quebrar sessões e existe risco de limitação/bloqueio da conta, especialmente em automações abusivas ou disparos em massa. Mantenha o módulo como Beta até validar uma conta real em operação controlada. A Bridge separada permite futura troca pela API oficial sem reescrever o restante do EventMenu.
