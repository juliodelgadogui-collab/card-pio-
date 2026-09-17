# EventMenu GO — preflight da próxima compilação

Branch integrada de desenvolvimento: `eventmenu-go/integration-no-actions`.

Esta branch reúne o APK Android e o suporte compartilhado do servidor. **Não executar compilação, GitHub Actions, merge no `main` ou publicação sem autorização explícita.**

A próxima compilação autorizada deve ser **Debug Atualizável**, mantendo o package `br.com.eventmenu.go` e a mesma assinatura fixa de desenvolvimento já validada pelo workflow `EventMenu GO Atualizável`. A chave privada não deve ser copiada, exibida ou movida para o APK.

## Antes de compilar

- Usar Java 17, Android SDK 36, Build Tools 36.0.0 e Gradle 9.3.1.
- Não exigir Firebase no build Debug. Firebase/Release continuam fora da homologação desta etapa.
- Manter o fluxo NFC/Tap On atual e sua autorização remota por aparelho.
- Confirmar que o backend de teste foi implantado a partir da mesma branch `eventmenu-go/integration-no-actions` e contém `api-go-delivery.php`, `api-go-expedition.php`, `api-go-inventory.php`, `api-go-routing.php`, `api-cluster.php`, `api-client-policy.php`, `api-client-release.php` e `api-hub.php`.
- Confirmar no servidor os componentes de contingência `api-cluster-bootstrap.php`, `api-cluster-sync.php`, migrations 104, 105 e 106.
- Confirmar `APP_URL` em HTTPS e `APP_BASE_PATH=/1` no servidor.
- Manter `EVENTMENU_CLIENT_POLICY_FAIL_OPEN=false` em produção. A opção `true` existe somente para migração legada deliberada.
- Se a contingência estiver habilitada, confirmar no Super ADM que o servidor adicional foi inicializado, verificado e sincronizado.
- Para a primeira ativação do servidor adicional, usar somente um `EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN` temporário; depois do pareamento remover esse valor do `.env` do adicional.
- Para contingência com escrita, os dois nós devem enxergar o mesmo MySQL/MariaDB (ou uma camada de banco com failover próprio) e usar a mesma `APP_KEY`. SQLite independente fica somente leitura.
- Confirmar que a política Android está ativa e que a versão mínima permite a versão Debug que será gerada.
- Se a política Android restringir certificado, o fingerprint permitido precisa ser o mesmo certificado de desenvolvimento validado pelo workflow.
- Se for testar atualização remota, enviar o APK para `storage/client-releases/EventMenu-GO.apk` no servidor que hospedará o download, calcular o SHA-256 e publicar no Super ADM a mesma versão interna do APK, URL HTTPS e hash.
- A URL recomendada quando o arquivo estiver no próprio servidor adicional é `https://DOMINIO-ADICIONAL/1/api-client-release.php?platform=android`; a pasta `storage` permanece bloqueada ao acesso público.
- Preservar os arquivos do GPS Premium: `DeliveryLocationService.kt`, `DeliveryLocationBuffer.kt`, `LocationPermissionActivity.kt` e o fluxo atualizado de `DeliveryProgressViewModel.kt`.

## Compilação

Somente após autorização explícita, executar manualmente o workflow **EventMenu GO Atualizável** escolhendo a branch `eventmenu-go/integration-no-actions`. O workflow deve fazer preflight dos módulos novos, incluindo failover e atualização remota assinada, restaurar a assinatura de desenvolvimento, rodar testes unitários, montar `assembleDebug`, validar package/certificado e publicar o artefato de homologação.

Para um build local de diagnóstico, a partir de `mobile/eventmenu-go`, usar `gradle :app:testDebugUnitTest --stacktrace --no-daemon` e depois `gradle :app:assembleDebug --stacktrace --no-daemon`. Um build local sem a assinatura fixa não deve ser distribuído como APK atualizável por cima do APK já instalado.

## Roteiro de homologação no aparelho

1. Entrar com cada perfil e confirmar permissões, empresa, unidade e turno.
2. Confirmar que, ao abrir o app, o APK obtém do principal a rota de contingência, a chave pública da política e um manifesto Android assinado; depois confirmar atualização periódica enquanto o app está visível.
3. Alterar no Super ADM a versão recomendada ou uma política não bloqueante, sincronizar e confirmar que o APK recebe a mudança sem recompilação.
4. Ativar manutenção Android no painel e confirmar que o backend e o APK bloqueiam a operação com a mensagem configurada; depois reativar.
5. Se o certificado estiver restringido, confirmar que somente o APK com o fingerprint permitido é aceito.
6. Publicar uma atualização Android de teste com versão, URL HTTPS, SHA-256 e notas. Confirmar que o manifesto assinado contém os mesmos dados e que a contingência recebe a mesma política.
7. Tocar no aviso de atualização no APK. Confirmar download pelo próprio EventMenu GO, conferência do SHA-256, package `br.com.eventmenu.go`, certificado igual ao app instalado e bloqueio de downgrade antes de abrir o Package Installer.
8. Em Android 8+, se solicitado, liberar uma única vez a permissão de “instalar apps desconhecidos” para o EventMenu GO; voltar ao app, tocar novamente e confirmar que o arquivo já verificado pode ser reutilizado do cache.
9. Alterar propositalmente o arquivo hospedado sem atualizar o SHA-256 e confirmar que o download/instalação é bloqueado.
10. Delivery: retirar pedido, iniciar rota, validar GPS em primeiro plano e com tela apagada, abrir WhatsApp somente com descrição dos itens + link HTTPS privado, abrir Google Maps/Waze, marcar chegada, receber pagamento e concluir.
11. Confirmar que entrega concluída some da lista ativa e encerra compartilhamento de localização.
12. Confirmar que coordenada mock ou imprecisa não é publicada ao cliente; deve permanecer o último ponto confiável ou localização indisponível.
13. Expedição: validar Sem entregador, Aguardando retirada, Retirado, Em rota, Chegou e Entregue.
14. Gestão: validar alertas de estoque baixo/zerado sem permitir administração completa de estoque no celular.
15. Hub: computador online/offline, QR temporário, vínculo por unidade, impressão, recibo, tela do cliente, alerta, gaveta conforme permissão e prevenção de comando duplicado.
16. PINPad/TEF via Hub: o celular só solicita; aprovação local não pode marcar o pedido como pago. A confirmação continua dependendo do servidor/provedor.
17. Solicitar autorização NFC, aprovar no servidor e confirmar que a tela muda de `pending` para `active` automaticamente sem logout ou reinstalação.
18. Trocar usuário/unidade/turno e confirmar que vínculos e comandos antigos não permanecem na tela.
19. Failover: com os dois servidores online, autenticar e deixar o APK baixar a configuração de roteamento. Em seguida indisponibilizar apenas o servidor principal e confirmar que uma consulta GET troca para o servidor de contingência somente depois de validar o manifesto RS256 do nó candidato.
20. Com o principal fora do ar, confirmar que `api-client-policy.php?platform=android` continua entregando no adicional o mesmo manifesto assinado que foi sincronizado pelo principal.
21. No modo `shared_db`, depois da troca, confirmar que a primeira gravação que detectou a queda não é repetida automaticamente; repetir manualmente a ação e confirmar que ela é executada uma única vez no servidor adicional.
22. No modo `read_only`, confirmar que consultas continuam pelo adicional apenas quando ele possui acesso aos dados necessários e que pedidos, pagamentos, estoque e outras gravações ficam bloqueados até o principal voltar.
23. Reativar o servidor principal e confirmar que o APK volta automaticamente a ele após a sondagem de recuperação.
24. Testar perda total da internet. Hub, expedição e estoque operacional não podem usar cache antigo como se fosse estado atual.
25. Só depois da homologação fazer merge seletivo no `main` e iniciar a etapa Release/Firebase.

## Política remota, autorização e atualização

O APK contém a lógica para baixar e verificar um manifesto `RS256`. A alteração de versão mínima, manutenção, servidor de contingência, recursos conhecidos e dados de uma nova versão pode ocorrer sem recompilar o APK já instalado. Função ou tela nova continua exigindo uma nova versão do APK.

A atualização remota não é uma instalação silenciosa. O APK baixa por HTTPS, limita redirecionamentos, confere o SHA-256 assinado, valida package, certificado e versão do APK, bloqueia downgrade e então entrega o arquivo ao Package Installer do Android para confirmação do usuário. O arquivo fica apenas no cache privado do aplicativo e é servido ao instalador por `FileProvider`.

A chave privada que assina políticas fica criptografada no servidor principal. O nó de contingência recebe somente a chave pública e os manifestos já assinados. Login, sessão, permissões de usuário e confirmação de pagamentos continuam sendo validados pelo backend; o manifesto não substitui autenticação.

## Resultado esperado

A build desta branch é candidata a **teste de campo**, não a Release de produção. O merge no `main`, assinatura Release, Firebase de produção e publicação definitiva devem ocorrer apenas após a compilação e os testes acima passarem.
