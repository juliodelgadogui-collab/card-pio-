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
├── assets/
├── data/
├── lib/
├── storage/
└── 1/                        <- sistema EventMenu atual (não alterar)
```

O Apache precisa permitir `.htaccess`/mod_rewrite para os links amigáveis. Caso não permita, os arquivos PHP continuam funcionando diretamente.

## Token do administrador de APKs

A área `/admin-apks.php` nasce **desativada** por segurança. Defina no ambiente PHP/Apache:

```text
EVENTMENU_APK_ADMIN_TOKEN=<token forte com pelo menos 20 caracteres>
```

Não existe senha padrão no código.

## Política dos 500 MB

Para cada aplicativo o portal guarda no máximo:

1. versão atual;
2. versão anterior.

Na terceira publicação, a versão mais antiga é removida automaticamente.

O `latest.apk` **não é uma cópia física**: a regra de rewrite encaminha o link permanente para `download.php`, que entrega o arquivo versionado atual. Isso evita gastar o dobro do armazenamento.

## Fluxo de publicação

1. Acesse `/admin-apks.php`.
2. Informe o token.
3. Escolha o aplicativo.
4. Informe `version` (ex.: `1.4.2`) e `versionCode` (ex.: `142`).
5. Envie o `.apk`.
6. O sistema calcula tamanho e SHA-256, atualiza `data/apps.json`, troca a versão atual e mantém a anterior.

## Limites de upload do PHP

Se APKs maiores falharem, ajuste no hosting/cPanel os valores de `upload_max_filesize` e `post_max_size` para valores acima do tamanho máximo esperado.
