# EventMenu — fechamento de produção

Este documento descreve o fechamento técnico do servidor EventMenu Premium e do aplicativo Android EventMenu GO.

## Servidor

O pacote de produção é gerado pelo workflow `EventMenu CI` somente quando passam:

- lint/sintaxe PHP e regras de segurança;
- testes SQLite;
- testes MySQL 8;
- invariantes de pagamento/idempotência;
- regras de permissões;
- prontidão de produção.

O artefato continua se chamando `EventMenu-Premium-Servidor-SQLite-1` porque a instalação inicial automatizada usa SQLite. O pacote contém suporte de código a MySQL/MariaDB e só é liberado depois que o caminho MySQL também passa no CI.

Cada pacote final inclui:

- `VERSION.txt`;
- `BUILD-MANIFEST.json` com commit e identificação do build;
- `SHA256SUMS.txt` gerado e validado no CI;
- dependências de produção em `vendor/`;
- `.env.example`, nunca um `.env` real;
- regras para bloquear acesso HTTP às pastas privadas.

Depois da instalação, o Super ADM deve manter a tela **Saúde do sistema** sem bloqueadores. Pix/cartão reais só devem ser liberados quando a seção **Pagamentos reais** estiver `PRONTO`.

## EventMenu GO — Release assinado

O workflow `.github/workflows/release.yml` é o único caminho oficial para gerar o APK/AAB de produção. Ele:

1. exige HTTPS para a API;
2. exige versão/versionCode válidos;
3. exige Firebase completo;
4. exige uma chave de assinatura Release permanente;
5. valida o alias da chave;
6. executa testes unitários Release;
7. gera APK e AAB minificados;
8. verifica criptograficamente a assinatura do APK e do AAB;
9. publica checksums SHA-256.

### Segredos obrigatórios no GitHub Actions

Configure estes secrets no repositório antes da primeira Release:

- `EVENTMENU_ANDROID_KEYSTORE_BASE64`
- `EVENTMENU_ANDROID_KEYSTORE_PASSWORD`
- `EVENTMENU_ANDROID_KEY_ALIAS`
- `EVENTMENU_ANDROID_KEY_PASSWORD`
- `EVENTMENU_FIREBASE_PROJECT_ID`
- `EVENTMENU_FIREBASE_APP_ID`
- `EVENTMENU_FIREBASE_API_KEY`
- `EVENTMENU_FIREBASE_SENDER_ID`

A chave `.jks`/`.keystore` nunca deve ser commitada. O `.gitignore` do Android bloqueia esses formatos.

### Criar a chave permanente uma única vez

Exemplo local com JDK 17 ou superior:

```bash
keytool -genkeypair -v \
  -keystore eventmenu-go-release.jks \
  -alias eventmenugo \
  -keyalg RSA \
  -keysize 4096 \
  -validity 10000
```

Escolha senhas fortes e guarde o arquivo em pelo menos dois locais seguros. Perder essa chave pode impedir atualizações do aplicativo distribuído fora de mecanismos de assinatura gerenciada por loja.

Para converter o arquivo para base64 antes de cadastrá-lo como secret no GitHub:

Linux/macOS:

```bash
base64 -w 0 eventmenu-go-release.jks > eventmenu-go-release.base64.txt
```

PowerShell:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes("eventmenu-go-release.jks")) | Set-Content -NoNewline eventmenu-go-release.base64.txt
```

Cadastre o conteúdo do arquivo base64 como `EVENTMENU_ANDROID_KEYSTORE_BASE64`. Não envie esse conteúdo em issues, commits, logs ou mensagens públicas.

## Gerar a Release final

No GitHub Actions, execute manualmente o workflow **EventMenu Production Release** e informe:

- `version_name`, por exemplo `1.0.0`;
- `version_code` ou deixe vazio para usar o número da execução;
- URL HTTPS oficial da API.

O artefato final conterá:

- `EventMenu-GO-VERSAO.apk`;
- `EventMenu-GO-VERSAO.aab`;
- `RELEASE.txt`;
- `SHA256SUMS.txt`.

O APK Debug continua existindo apenas para testes internos e nunca deve ser tratado como distribuição final.
