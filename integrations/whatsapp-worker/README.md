# EventMenu WhatsApp Bridge — Beta

Serviço Node local que isola o EventMenu da engine não oficial do WhatsApp. O painel PHP nunca recebe o token da engine nem acessa os arquivos de sessão diretamente.

## Requisitos

- Node.js 22+
- Chrome/Chromium compatível com WPPConnect/Puppeteer
- pasta de sessão fora de diretório público, com acesso restrito ao usuário do serviço
- segredo aleatório com pelo menos 32 caracteres

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
# Opcional quando o Chromium não for localizado automaticamente:
# CHROME_EXECUTABLE_PATH=/usr/bin/chromium
# Somente se a hospedagem realmente impedir o sandbox do Chromium:
# WHATSAPP_CHROME_NO_SANDBOX=true
```

Inicie com `npm start`.

No `.env` do EventMenu use o mesmo segredo:

```text
WHATSAPP_BRIDGE_ENABLED=true
WHATSAPP_BRIDGE_URL=http://127.0.0.1:21466
WHATSAPP_BRIDGE_SECRET=troque-por-um-segredo-longo-e-aleatorio
```

A bridge deve permanecer em `127.0.0.1` sempre que PHP e Node estiverem no mesmo servidor. Se precisar operar em hosts separados, use rede privada/TLS e firewall; não publique a porta diretamente na internet.

## Segurança e isolamento

- cada empresa recebe uma chave de sessão aleatória e independente;
- as rotas rejeitam chaves fora do formato esperado;
- toda chamada exige Bearer secret e comparação timing-safe;
- QR Code fica somente em memória e é devolvido ao painel autenticado;
- arquivos de sessão não ficam em `public/`;
- logout manual impede reconexão automática até uma nova solicitação;
- mensagens saem pela outbox PHP com idempotência e retentativas limitadas;
- o sandbox do Chromium permanece ligado por padrão. Ative `WHATSAPP_CHROME_NO_SANDBOX=true` apenas quando a infraestrutura exigir e depois de avaliar o risco.

## Importante

Esta integração usa WhatsApp Web por uma biblioteca não oficial. Mudanças do WhatsApp podem quebrar sessões e existe risco de limitação/bloqueio da conta, especialmente em automações abusivas ou disparos em massa. Mantenha o módulo como Beta até validar uma conta real em operação controlada. A bridge separada permite futura troca pela API oficial sem reescrever o restante do EventMenu.
