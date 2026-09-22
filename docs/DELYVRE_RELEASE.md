# DELYVRE — Release Android de produção

Este documento descreve o pipeline oficial de Release do aplicativo DELYVRE (`br.com.eventmenu.delivery`).

## Regra principal

O APK/AAB de produção **não** deve ser gerado com a chave debug do Android. A identidade do aplicativo depende de uma keystore permanente do DELYVRE. Perder ou trocar essa chave impede a atualização normal sobre instalações anteriores assinadas com outro certificado.

O workflow oficial é `.github/workflows/delyvre-release.yml` e deve ser executado manualmente a partir da branch que contém a versão aprovada.

## GitHub Secrets obrigatórios

Firebase/FCM:

- `EVENTMENU_FIREBASE_PROJECT_ID`
- `EVENTMENU_DELIVERY_FIREBASE_APP_ID`
- `EVENTMENU_FIREBASE_API_KEY`
- `EVENTMENU_FIREBASE_SENDER_ID`

Assinatura exclusiva do DELYVRE:

- `DELYVRE_ANDROID_KEYSTORE_BASE64`
- `DELYVRE_ANDROID_KEYSTORE_PASSWORD`
- `DELYVRE_ANDROID_KEY_ALIAS`
- `DELYVRE_ANDROID_KEY_PASSWORD`

Validação adicional recomendada:

- `DELYVRE_ANDROID_CERT_SHA256`

O valor de `DELYVRE_ANDROID_CERT_SHA256` deve ser o SHA-256 do certificado usado para assinar o APK oficial. Quando configurado, o workflow recusa qualquer pacote assinado por certificado diferente.

## Artefatos

O Release gera:

- `DELYVRE-<versao>.apk`
- `DELYVRE-<versao>.aab`
- `RELEASE.txt`
- `SHA256SUMS.txt`

O APK é validado com `apksigner` e o AAB com `jarsigner` antes do upload.

## CI de desenvolvimento

O workflow `.github/workflows/eventmenu-delivery-branch.yml` continua gerando um APK debug apenas para teste. Além dos testes e Android Lint, ele executa um build Release minificado usando uma keystore temporária criada no runner apenas para detectar erros de R8/ProGuard e assinatura. Esse Release temporário não é publicado nem deve ser distribuído.

## Migração de instalações antigas

Um APK assinado com chave diferente não atualiza uma instalação existente. Antes de substituir o APK atualmente disponibilizado no portal, comparar o SHA-256 do certificado do APK instalado/distribuído com `DELYVRE_ANDROID_CERT_SHA256`.

Se a versão antiga tiver sido distribuída como debug e não houver acesso à mesma chave, a migração pode exigir desinstalação da versão antiga antes da instalação do primeiro Release oficial. Isso deve ser comunicado antes da troca do arquivo no portal.
