# Changelog

## 1.0.0 — 2026-09-21

Primeira release formal e padronizada da suíte EventMenu.

### Componentes
- EventMenu Server: backend/PWA PHP 8.2 com instalação inicial em SQLite e suporte de migração para MySQL/MariaDB.
- EventMenu GO: aplicativo operacional Android consolidado a partir de `dev/eventmenu-go`.
- DELYVRE: aplicativo Android do consumidor consolidado a partir de `dev/eventmenu-delivery`, com a marca visível padronizada para DELYVRE.
- EventMenu Desktop: aplicativo Windows WPF/.NET 8 consolidado a partir de `dev/eventmenu-desktop`.
- EventMenu WhatsApp Connect: utilitário Windows presente na linha integrada de WhatsApp.
- EventMenu WhatsApp Bridge: serviço Node.js/Baileys para sessões isoladas via WhatsApp Web, mantido como Beta.
- Portal raiz: central de downloads em `go.gestao2.store`, com manifesto, SHA-256 e referência `latest`.

### Release e distribuição
- Versão da suíte centralizada em `VERSION` e `EVENTMENU_RELEASE=1.0.0`.
- Android Release padronizado em `versionName=1.0.0` e `versionCode=10000` nesta release.
- Assinatura Android de produção exigida via secrets/variáveis de ambiente; nenhuma chave privada é armazenada no repositório.
- Metadados de release e data de compilação expostos no BuildConfig dos aplicativos Android.
- Pacote do servidor passa a incluir documentação e instaladores de primeira instalação/atualização.

### Segurança e operação
- `APP_DEBUG=false` no modelo de produção.
- URLs oficiais usam HTTPS.
- `.env`, banco, sessões e integrações privadas permanecem fora da área pública/protegidos pelo pacote.
- Bridge WhatsApp exige segredo compartilhado e mantém arquivos de sessão fora de `public/`.

### Observações
- O WhatsApp Bridge utiliza Baileys/WhatsApp Web não oficial e permanece Beta por risco de incompatibilidade ou limitação da plataforma.
- O histórico `desktop/RELEASE_CHECKLIST_0.3.0.md` permanece apenas como documento antigo; a versão do Desktop nesta suíte é 1.0.0.
