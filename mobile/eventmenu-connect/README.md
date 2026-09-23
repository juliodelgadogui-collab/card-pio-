# EventMenu Connect Android

Aplicativo auxiliar dedicado ao canal WhatsApp do restaurante. O Connect não replica regras de negócio: pedidos, pagamentos, estoque, preços e estados continuam no EventMenu Server.

## Arquitetura atual

`WhatsApp <-> Baileys/Node embarcado <-> EventMenu Connect Android <-> HTTPS autenticado <-> EventMenu Server <-> whatsapp_outbox`

O runtime Node/Baileys é empacotado no próprio APK. Ele escuta somente em `127.0.0.1`, usa um segredo local aleatório e mantém a sessão do WhatsApp no armazenamento privado do aplicativo. Não depende de VPS própria nem de automação por Acessibilidade.

O Connect reutiliza a autenticação do EventMenu e a fila/lease de `public/api-whatsapp-desktop.php`. Saídas sempre nascem no servidor, passam por `whatsapp_outbox`, são reivindicadas pelo Connect e recebem ACK após o envio. Mensagens inbound seguem o caminho inverso e são confirmadas ao motor local somente depois do processamento.

## Recursos

- pareamento por QR Code ou código/número;
- restauração da sessão sem apagar credenciais;
- envio de texto e mídia com idempotência;
- recebimento e ACK de mensagens inbound;
- reconexão após troca/retorno de rede com backoff;
- retomada após reinicialização/atualização do aparelho;
- serviço foreground para manter o conector operacional;
- credenciais EventMenu, segredo local, QR/código de pareamento e ACKs sensíveis protegidos pelo Android Keystore.

## Primeiro uso

1. Instalar o APK oficial assinado.
2. Entrar com um usuário autorizado do restaurante.
3. Iniciar o vínculo do WhatsApp por QR Code ou código de pareamento.
4. Confirmar que o status chegou a `connected`.
5. Manter o aparelho com internet e permitir a operação em segundo plano.

## Produção

O build `release` exige uma keystore oficial e não pode ser produzido sem:

- `EVENTMENU_CONNECT_RELEASE_KEYSTORE_PATH`
- `EVENTMENU_CONNECT_RELEASE_STORE_PASSWORD`
- `EVENTMENU_CONNECT_RELEASE_KEY_ALIAS`
- `EVENTMENU_CONNECT_RELEASE_KEY_PASSWORD`

Releases usam R8/minificação e shrink de recursos. O bridge JNI do Node possui regras ProGuard explícitas porque o símbolo nativo depende do nome da classe Kotlin.

## Limites

O Connect usa Baileys/WhatsApp Web, portanto depende do protocolo e das políticas do WhatsApp e não equivale à WhatsApp Business Platform oficial da Meta. Mudanças no protocolo podem exigir atualização do runtime. Para operação comercial, monitore reconexões, falhas da outbox e versão do conector.
