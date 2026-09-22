# EventMenu GO — Release de produção

Este documento descreve como gerar um APK **release assinado**, com Firebase Cloud Messaging habilitado, sem armazenar chaves privadas no repositório.

## GitHub Secrets obrigatórios

Configure no repositório:

- `EVENTMENU_FIREBASE_PROJECT_ID`
- `EVENTMENU_FIREBASE_APP_ID`
- `EVENTMENU_FIREBASE_API_KEY`
- `EVENTMENU_FIREBASE_SENDER_ID`
- `EVENTMENU_RELEASE_KEYSTORE_BASE64`
- `EVENTMENU_RELEASE_STORE_PASSWORD`
- `EVENTMENU_RELEASE_KEY_ALIAS`
- `EVENTMENU_RELEASE_KEY_PASSWORD`

Opcional, mas recomendado:

- `EVENTMENU_RELEASE_CERT_SHA256` — SHA-256 do certificado de assinatura esperado. Quando configurado, o CI rejeita qualquer APK assinado por outro certificado.

`EVENTMENU_RELEASE_KEYSTORE_BASE64` deve conter o conteúdo Base64 do arquivo JKS/keystore de produção. O workflow decodifica a chave somente no diretório temporário do runner e não a publica como artefato.

## Regras de segurança

- Nunca commitar `.jks`, `.keystore`, senhas ou conta de serviço Firebase.
- A conta de serviço FCM pertence somente ao backend. O APK recebe apenas os identificadores/configuração do aplicativo Firebase necessários ao cliente Android.
- Guarde cópia segura do keystore de produção. Perder a chave impede atualizar instalações assinadas com ela.
- Não reutilize a chave debug como chave de produção.

## Pipeline

O workflow `.github/workflows/eventmenu-go-branch.yml` executa:

1. testes unitários;
2. Android Lint;
3. geração e verificação do APK debug;
4. validação das configurações Firebase e dos segredos de assinatura;
5. decodificação temporária do keystore;
6. `assembleRelease` com minificação e shrink de recursos;
7. `apksigner verify --verbose --print-certs`;
8. conferência de metadados do APK;
9. geração do SHA-256 do APK;
10. upload do APK release e do arquivo `.sha256`.

O APK final é publicado no artefato `EventMenu-GO-<run>-release-apk` com nome semelhante a:

`EventMenu-GO-v0.2.<run>.apk`

## Versionamento

O `versionCode` não usa mais `GITHUB_RUN_ID`, pois os IDs atuais do GitHub ultrapassam o limite aceito pelo Android. O pipeline usa um código crescente e limitado ao intervalo válido do Android.

O Gradle rejeita explicitamente `EVENTMENU_VERSION_CODE` fora de `1..2100000000`; ele não reduz silenciosamente um valor inválido.

## Firebase Cloud Messaging

Builds release exigem as quatro configurações Firebase completas. O aplicativo inicializa o Firebase por `FirebaseOptions`, registra o token FCM na API do EventMenu e trata renovação de token pelo `FirebaseMessagingService`.

O manifesto já inclui `POST_NOTIFICATIONS` e registra `EventMenuFirebaseMessagingService` para `com.google.firebase.MESSAGING_EVENT`.

## Atualização de instalações debug

APK debug e APK release normalmente são assinados por certificados diferentes. Por segurança, o Android não permite instalar um release por cima de uma instalação debug com a mesma `applicationId` e assinatura diferente.

Na primeira migração de uma instalação antiga debug para a versão release oficial, pode ser necessário desinstalar o APK debug antes de instalar o release. Depois disso, as atualizações release devem continuar usando **o mesmo keystore de produção**.
