# EventMenu Android Signing Certificate

Release da suíte: **1.0.1**

Esta documentação registra apenas os dados públicos do certificado usado para assinar os aplicativos Android da suíte EventMenu. A chave privada nunca deve ser adicionada ao Git.

- Alias: `eventmenu-release`
- Algoritmo: RSA 4096 / SHA256withRSA
- Validade: 10000 dias
- SHA-256: `FC:A2:DC:F0:BB:57:D1:86:DB:09:FE:93:72:97:9E:B6:8C:21:C2:8B:9B:7A:A3:E4:26:23:0C:9B:78:CA:FA:C4`
- SHA-1: `EA:67:DD:FD:8D:9C:45:2E:7E:14:5B:89:69:7B:B9:DF:12:12:11:A6`
- Keystore SHA-256 de referência: `429eaa4357b237cef9238e2dc24319827003f7da48044c3e091b061c7a951911`

## Aplicativos previstos

- EventMenu GO — `br.com.eventmenu.go`
- DELYVRE — `br.com.eventmenu.delivery`

## Segurança

O arquivo privado do keystore e suas senhas devem existir somente em armazenamento seguro e nos GitHub Actions Secrets necessários ao build. Nunca publicar, versionar ou armazenar a chave privada em `public/`, releases públicas, issues ou logs.
