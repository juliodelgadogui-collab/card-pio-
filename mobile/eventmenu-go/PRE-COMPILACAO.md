# EventMenu GO — preflight da próxima compilação

Branch de desenvolvimento: `feature/eventmenu-go-mobile-sync`.

A próxima compilação deve ser **Debug Atualizável**, mantendo o package `br.com.eventmenu.go` e a mesma assinatura fixa de desenvolvimento já validada pelo workflow `EventMenu GO Atualizável`. A chave privada não deve ser copiada, exibida ou movida para o APK.

## Antes de compilar

- Usar Java 17, Android SDK 36, Build Tools 36.0.0 e Gradle 9.3.1.
- Não exigir Firebase no build Debug. Firebase/Release continuam fora da homologação desta etapa.
- Não alterar NFC/Tap On durante esta homologação.
- Confirmar que o backend de teste contém `api-go-delivery.php`, `api-go-expedition.php`, `api-go-inventory.php` e `api-hub.php` da branch `feature/eventmenu-go-server-support`.
- Confirmar `APP_URL` em HTTPS e `APP_BASE_PATH=/1` no servidor.
- Preservar os arquivos do GPS Premium: `DeliveryLocationService.kt`, `DeliveryLocationBuffer.kt`, `LocationPermissionActivity.kt` e o fluxo atualizado de `DeliveryProgressViewModel.kt`.

## Compilação

Quando o GitHub Actions estiver disponível novamente, executar manualmente o workflow **EventMenu GO Atualizável** escolhendo a branch `feature/eventmenu-go-mobile-sync`. O workflow faz preflight dos módulos novos, restaura a assinatura de desenvolvimento, roda testes unitários, monta `assembleDebug`, valida package/certificado e publica o artefato `EventMenu-GO-ATUALIZAVEL`.

Para um build local de diagnóstico, a partir de `mobile/eventmenu-go`, usar `gradle :app:testDebugUnitTest --stacktrace --no-daemon` e depois `gradle :app:assembleDebug --stacktrace --no-daemon`. Um build local sem a assinatura fixa não deve ser distribuído como APK atualizável por cima do APK já instalado.

## Roteiro de homologação no aparelho

1. Entrar com cada perfil e confirmar permissões, empresa, unidade e turno.
2. Delivery: retirar pedido, iniciar rota, validar GPS em primeiro plano, abrir WhatsApp somente com descrição dos itens + link HTTPS privado, abrir Google Maps/Waze, marcar chegada, receber pagamento e concluir.
3. Confirmar que entrega concluída some da lista ativa e encerra compartilhamento de localização.
4. Expedição: validar Sem entregador, Aguardando retirada, Retirado, Em rota, Chegou e Entregue.
5. Gestão: validar alertas de estoque baixo/zerado sem permitir administração completa de estoque no celular.
6. Hub: computador online/offline, QR temporário, vínculo por unidade, impressão, recibo, tela do cliente, alerta, gaveta conforme permissão e prevenção de comando duplicado.
7. PINPad/TEF via Hub: o celular só solicita; aprovação local não pode marcar o pedido como pago. A confirmação continua dependendo do servidor/provedor.
8. Trocar usuário/unidade/turno e confirmar que vínculos e comandos antigos não permanecem na tela.
9. Testar perda e retorno da internet. Hub, expedição e estoque operacional não podem usar cache antigo como se fosse estado atual.
10. Só depois da homologação fazer merge seletivo no `main` e iniciar a etapa Release/Firebase.

## Resultado esperado

A build desta branch é candidata a **teste de campo**, não a Release de produção. O merge no `main`, assinatura Release, Firebase de produção e publicação definitiva devem ocorrer apenas após a compilação e os testes acima passarem.
