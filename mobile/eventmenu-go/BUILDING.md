# Compilar o EventMenu GO

O projeto Android está em `mobile/eventmenu-go` e usa o servidor fixo:

`https://go.gestao2.store/1/`

## Requisitos

- JDK 17
- Android SDK Platform 36
- Android SDK Build Tools 36.0.0
- conexão com a internet na primeira compilação para baixar Gradle e dependências

O projeto usa:

- Android Gradle Plugin 9.1.0
- Gradle 9.3.1
- Kotlin integrado do AGP, fixado em KGP 2.4.10
- Compose Compiler 2.4.10
- Compose BOM 2026.06.00
- Firebase Cloud Messaging `firebase-messaging:25.0.1`
- `minSdk 23`
- `targetSdk 36`
- `compileSdk 36`

## Windows

Abra o PowerShell na pasta do projeto:

```powershell
cd mobile/eventmenu-go
powershell -ExecutionPolicy Bypass -File .\build-debug.ps1
```

O script procura o Android SDK em `ANDROID_SDK_ROOT`, `ANDROID_HOME` ou `%LOCALAPPDATA%\Android\Sdk`, cria `local.properties` localmente quando necessário, baixa Gradle 9.3.1 e executa `:app:assembleDebug`.

## Linux / macOS

```bash
cd mobile/eventmenu-go
bash ./build-debug.sh
```

Se necessário, defina antes:

```bash
export ANDROID_SDK_ROOT="$HOME/Android/Sdk"
```

## Android Studio

Abra **a pasta `mobile/eventmenu-go`**, não a raiz inteira do repositório.

Configure o Gradle JDK para Java 17 e instale pelo SDK Manager:

- Android SDK Platform 36
- Android SDK Build-Tools 36.0.0

Como o repositório não contém o `gradle-wrapper.jar` binário, os scripts `build-debug.ps1` e `build-debug.sh` são a forma reproduzível de usar exatamente Gradle 9.3.1. Também é possível configurar uma instalação local do Gradle 9.3.1 no Android Studio.

## APK de debug

Após uma compilação bem-sucedida, o APK será criado em:

`app/build/outputs/apk/debug/app-debug.apk`

O workflow `EventMenu CI` também executa `:app:assembleDebug` em push e pull request. Quando o GitHub Actions estiver disponível para a conta/repositório, o job Android publica o APK como artefato `eventmenu-go-debug-apk` por 7 dias.

## Firebase Cloud Messaging

O aplicativo compila normalmente mesmo sem um projeto Firebase configurado. Nesse caso, a sincronização periódica de notificações continua funcionando e o FCM permanece desativado.

Para ativar push em tempo real no APK, defina no ambiente de compilação:

```bash
export EVENTMENU_FIREBASE_PROJECT_ID="seu-project-id"
export EVENTMENU_FIREBASE_APP_ID="1:000000000000:android:0000000000000000"
export EVENTMENU_FIREBASE_API_KEY="sua-chave-do-app-android"
export EVENTMENU_FIREBASE_SENDER_ID="000000000000"
```

`EVENTMENU_FIREBASE_PROJECT_ID` também aceita `FCM_PROJECT_ID` como fallback. Esses valores pertencem à configuração do app Android no projeto Firebase. A conta de serviço usada pelo servidor continua separada e é configurada no backend por `FCM_SERVICE_ACCOUNT_PATH` ou `FCM_SERVICE_ACCOUNT_BASE64`.

Quando uma sessão é aberta, o EventMenu GO obtém o token FCM e registra o aparelho em `api-go-notifications.php?action=push-register`. A renovação de token é tratada pelo `FirebaseMessagingService`. O servidor inclui `tenant_id` e `user_id` no push e o app rejeita qualquer mensagem que não pertença à sessão ativa do aparelho.

No logout, o backend desativa o registro push associado ao mesmo aparelho. Pagamentos, alterações de pedido, check-in e entrega continuam dependendo da API; push apenas informa e abre a área correta.

## Deep links e notificações inteligentes

O app registra o esquema:

`eventmenugo://open/{entity_type}/{entity_id}`

A notificação inclui também `notification_id`, `notification_type` e `notification_mode`. O `MainActivity` espera uma sessão e um turno válidos antes de navegar e nunca troca um turno já aberto silenciosamente.

Exemplos de teste com ADB:

```bash
adb shell am start -a android.intent.action.VIEW -d "eventmenugo://open/order/123?notification_type=order.new&notification_mode=operation"
adb shell am start -a android.intent.action.VIEW -d "eventmenugo://open/order/123?notification_type=order.ready&notification_mode=operation"
adb shell am start -a android.intent.action.VIEW -d "eventmenugo://open/discount_request/55?notification_type=discount.requested"
```

Rotas inteligentes atuais:

- `order.new` → Cozinha quando o usuário possui permissão de cozinha no modo Operação.
- `order.ready` → Despacho quando o usuário possui permissão de despacho/atribuição no modo Operação.
- notificações de pedido no modo Delivery → área Delivery.
- `discount_request` e `cancellation_request` → Gerência quando a função possui acesso ao painel gerencial.
- `event` → Eventos.
- `table` / `tab` → Mesas.
- `payment` → Caixa quando permitido.
- demais pedidos → Pedidos; caso a rota não seja permitida, o app mantém o usuário na Central de Notificações.

## Observações

- O domínio do servidor não é configurável pela interface do funcionário.
- `local.properties`, pastas de build e arquivos do Android Studio ficam fora do Git.
- O deep link apenas navega para uma área permitida; autorizações sensíveis continuam validadas pelo servidor.
- Se um workflow do GitHub Actions falhar sem iniciar nenhuma etapa, isso não representa um erro de compilação do projeto. Use os scripts locais acima para separar falha de execução do Actions de falha real do Gradle/Kotlin.
