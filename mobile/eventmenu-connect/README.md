# EventMenu Connect Android

APK auxiliar dedicado ao WhatsApp do restaurante. Não usa VPS e não adiciona funções operacionais ao EventMenu GO.

## Fluxo

`EventMenu Server -> whatsapp_outbox -> EventMenu Connect Android -> WhatsApp/WhatsApp Business instalado no aparelho`

O APK reutiliza a autenticação nativa já existente em `public/api.php` e a fila/lease já existente em `public/api-whatsapp-desktop.php`.

Para o modo Android sem VPS, o app usa o serviço de Acessibilidade exclusivamente durante um envio pendente: abre a conversa pelo link oficial `wa.me`, localiza o botão Enviar no WhatsApp instalado e confirma a mensagem ao servidor. Se o envio não for concluído, o lease expira/falha e a fila aplica o retry já existente.

## Primeiro uso

1. Instalar o APK.
2. Entrar com o usuário do restaurante.
3. Tocar em `Ativar EventMenu Connect` e habilitar o serviço de acessibilidade.
4. Voltar ao app e tocar em `Iniciar / Reconectar`.
5. Manter o WhatsApp ou WhatsApp Business autenticado no mesmo Android.

O serviço inicia novamente após reinicialização do aparelho, desde que exista uma sessão EventMenu válida.

## Limites desta estratégia

É um modo local para distribuição direta do APK. A automação por acessibilidade depende da interface do WhatsApp e pode exigir ajustes se o WhatsApp alterar identificadores/telas. Para publicação em lojas, revisar as políticas de Acessibilidade da loja antes da distribuição.
