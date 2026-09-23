# Portal raiz — EventMenu

Este diretório foi criado para ser publicado na **raiz** de `https://go.gestao2.store/`.
Ele é independente do sistema existente em `https://go.gestao2.store/1/` e não deve substituir os arquivos desse subdiretório.

## Estrutura pública

- `/` — página institucional EventMenu
- `/download` — Central de Downloads
- `/apk/eventmenu-go/latest.apk` — link permanente do EventMenu GO
- `/apk/delyvre/latest.apk` — link permanente do DELYVRE
- `/updates/apps.json` — manifesto JSON para consulta de versão pelos aplicativos
- `/admin-apks.php` — publicação manual protegida por token
- `/api/apk/publish` — endpoint protegido para publicação automatizada

## Publicação

Copie **o conteúdo deste diretório** para o DocumentRoot de `go.gestao2.store`, preservando o diretório `/1` já instalado.

Exemplo esperado no servidor:

```text
public_html/go/
├── index.php                 <- portal
├── downloads.php
├── download.php
├── updates.php
├── admin-apks.php
├── api-apk-publish.php
├── assets/
├── data/
├── lib/
├── storage/
└── 1/                        <- sistema EventMenu atual (não alterar)
```

O Apache precisa permitir `.htaccess`/mod_rewrite para os links amigáveis. Caso não permita, os arquivos PHP continuam funcionando diretamente.

## Tokens de publicação

A área `/admin-apks.php` nasce **desativada** por segurança. Defina no ambiente PHP/Apache:

```text
EVENTMENU_APK_ADMIN_TOKEN=<token forte com pelo menos 20 caracteres>
```

Não existe senha padrão no código.

Para uma futura publicação automatizada por CI, configure também:

```text
EVENTMENU_APK_PUBLISH_TOKEN=<token forte com pelo menos 24 caracteres>
```

O endpoint `/api/apk/publish` já aceita esse token via Bearer ou `X-EventMenu-Token`, mas a automação de release deve ser configurada separadamente e somente em workflow confiável da `main`. Esta entrega **não publica builds automaticamente**.

## Segurança do upload

O portal valida:

- aplicativo cadastrado e slug permitido;
- versão e `versionCode`;
- upload HTTP real (`is_uploaded_file`);
- extensão `.apk`;
- MIME compatível com APK/ZIP;
- assinatura ZIP esperada do APK;
- tamanho máximo de 250 MB;
- nome final gerado pelo servidor, sem usar caminho fornecido pelo cliente;
- SHA-256 após a publicação.

As pastas `data`, `lib` e `storage` são bloqueadas para acesso HTTP direto pelo `.htaccess`.

## Política dos 500 MB

Para cada aplicativo o portal guarda no máximo:

1. versão atual;
2. versão anterior.

Na terceira publicação, a versão mais antiga é removida automaticamente.

O `latest.apk` **não é uma cópia física**: a regra de rewrite encaminha o link permanente para `download.php`, que entrega o arquivo versionado atual. Isso evita gastar o dobro do armazenamento.

## Fluxo de publicação manual

1. Acesse `/admin-apks.php`.
2. Informe o token.
3. Escolha o aplicativo.
4. Informe `version` (ex.: `1.4.2`) e `versionCode` (ex.: `142`).
5. Envie o `.apk`.
6. O sistema calcula tamanho e SHA-256, atualiza `data/apps.json`, troca a versão atual e mantém a anterior.

## Limites de upload do PHP

O portal limita o APK a 250 MB. No hosting/cPanel, `upload_max_filesize` e `post_max_size` precisam estar acima do tamanho real do APK e nunca devem ser usados como substitutos da validação da aplicação.
