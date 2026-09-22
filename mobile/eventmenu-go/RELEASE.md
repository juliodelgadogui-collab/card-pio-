# EventMenu GO — Release de produção

Este documento descreve como gerar um APK/AAB **release assinado**, com Firebase Cloud Messaging habilitado, sem armazenar chaves privadas no repositório.

## GitHub Secrets obrigatórios

Configure no repositório:

- `EVENTMENU_FIREBASE_PROJECT_ID`
- `EVENTMENU_FIREBASE_APP_ID`
- `EVENTMENU_FIREBASE_API_KEY`
- `EVENTMENU_FIREBASE_SENDER_ID`
- `EVENTMENU_ANDROID_KEYSTORE_BASE64`
- `EVENTMENU_ANDROID_KEYSTORE_PASSWORD`
- `EVENTMENU_ANDROID_KEY_ALIAS`
- `EVENTMENU_ANDROID_KEY_PASSWORD`

Opcional, mas recomendado:

- `EVENTMENU_ANDROID_CERT_SHA256` — SHA-256 do certificado de assinatura esperado. Quando configurado, o workflow rejeita qualquer APK assinado por outro certificado.

`EVENTMENU_ANDROID_KEYSTORE_BASE64` deve conter o conteúdo Base64 do arquivo JKS/keystore de produção. O workflow decodifica a chave somente no diretório temporário do runner e não a publica como artefato.

## Regras de segurança

- Nunca commitar `.jks`, `.keystore`, senhas ou conta de serviço Firebase.
- A conta de serviço FCM pertence somente ao backend. O APK recebe apenas os identificadores/configuração do aplicativo Firebase necessários ao cliente Android.
- Guarde cópia segura do keystore de produção. Perder a chave impede atualizar instalações assinadas com ela.
- Não reutilize a chave debug como chave de produção.

## Pipelines separados

### Validação de branch

`.github/workflows/eventmenu-go-branch.yml` executa automaticamente:

1. testes unitários;
2. Android Lint;
3. geração do APK debug;
4. verificação criptográfica do APK debug com `apksigner`;
5. conferência dos metadados Android;
6. geração de SHA-256;
7. upload do APK debug para teste interno.

Esse workflow não exige credenciais de produção e, portanto, PRs e branches de desenvolvimento continuam testáveis sem expor ou simular uma chave release.

### Release oficial

`.github/workflows/eventmenu-go-release.yml` é acionado manualmente e exige todos os Secrets de produção. Ele executa:

1. validação de versão, URL HTTPS, Firebase e Secrets de assinatura;
2. restauração temporária do keystore permanente;
3. validação de alias/chave com `keytool`;
4. testes unitários Release;
5. geração do APK e do AAB Release com minificação/shrink;
6. verificação do APK com `apksigner` e do AAB com `jarsigner`;
7. comparação opcional do certificado com `EVENTMENU_ANDROID_CERT_SHA256`;
8. conferência dos metadados do APK;
9. geração de `RELEASE.txt` e `SHA256SUMS.txt`;
10. publicação do artefato `EventMenu-GO-Release-<versão>`.

## Criar a chave permanente uma única vez

Exemplo com JDK 17 ou superior:

```bash
keytool -genkeypair -v \
  -keystore eventmenu-go-release.jks \
  -alias eventmenugo \
  -keyalg RSA \
  -keysize 4096 \
  -validity 10000
```

Escolha senhas fortes e guarde o arquivo em pelo menos dois locais seguros.

Linux/macOS:

```bash
base64 -w 0 eventmenu-go-release.jks > eventmenu-go-release.base64.txt
```

PowerShell:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("eventmenu-go-release.jks")) | Set-Content -NoNewline eventmenu-go-release.base64.txt
```

O conteúdo Base64 deve ser salvo em `EVENTMENU_ANDROID_KEYSTORE_BASE64`, nunca em commit, issue ou log público.

## Versionamento

O `versionCode` não usa mais `GITHUB_RUN_ID`, pois os IDs atuais do GitHub ultrapassam o limite aceito pelo Android. Builds de branch usam um código crescente e limitado; releases podem receber `version_code` explícito ou um valor automático igualmente válido.

O Gradle rejeita `EVENTMENU_VERSION_CODE` fora de `1..2100000000`; ele não reduz silenciosamente um valor inválido.

## Firebase Cloud Messaging

Builds release exigem as quatro configurações Firebase completas. O aplicativo inicializa o Firebase por `FirebaseOptions`, registra o token FCM na API do EventMenu e trata renovação de token pelo `FirebaseMessagingService`.

O manifesto inclui `POST_NOTIFICATIONS` e `VIBRATE` e registra `EventMenuFirebaseMessagingService` para `com.google.firebase.MESSAGING_EVENT`.

Se o login acontecer durante uma indisponibilidade de rede, o ciclo periódico de notificações tenta novamente registrar o token FCM quando a conectividade retorna. Mensagens com expiração explícita malformada são rejeitadas.

## Atualização de instalações debug

APK debug e APK release normalmente são assinados por certificados diferentes. Por segurança, o Android não permite instalar um release por cima de uma instalação debug com a mesma `applicationId` e assinatura diferente.

Na primeira migração de uma instalação antiga debug para a versão release oficial, pode ser necessário desinstalar o APK debug antes de instalar o release. Depois disso, todas as atualizações release devem continuar usando **o mesmo keystore de produção**.
