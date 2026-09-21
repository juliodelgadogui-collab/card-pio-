# Auditoria de branches — EventMenu

Data da auditoria: 2026-09-21

Repositório: `juliodelgadogui-collab/card-pio-`

## Objetivo

Estrutura canônica definida:

- `main` — produção estável
- `develop` — integração geral antes de produção
- `dev/delyvre` — aplicativo DELYVRE
- `dev/eventmenu-go` — aplicativo operacional EventMenu GO
- `dev/eventmenu-desktop` — EventMenu Desktop
- `dev/eventmenu-server` — backend/servidor
- `dev/eventmenu-portal` — portal `go.gestao2.store` e distribuição dos APKs

Fluxo desejado daqui para frente:

`feature/fix -> dev do produto -> develop -> main`

## Regras de segurança usadas

1. Nenhuma branch foi apagada durante esta reorganização.
2. Nenhum `force push` foi usado.
3. `main` não foi alterada.
4. Antes de avançar `dev/eventmenu-server`, foi criada a branch `backup/2026-09-21-dev-eventmenu-server` apontando para o SHA anterior `cbbc56c6b7353bc6ad5677f0fb04850540011cf7`.
5. Também foi criado `backup/2026-09-21-main` apontando para `8f2f80f087340f2b35006810e0c5ffacb90622de`.
6. Branches divergentes foram mantidas para triagem; não foi feito merge cego.

## Descobertas principais

### DELYVRE

- `dev/eventmenu-delivery` e `wip/eventmenu-delivery-customer-app` divergem: a linha do app possui 89 commits que a antiga não tem, enquanto a antiga possui 24 commits exclusivos.
- `wip/eventmenu-delivery-customer-app` e `wip/eventmenu-delivery-customer-auth-payments` também divergem fortemente: a segunda linha possui 390 commits à frente e 121 atrás.
- Portanto, `dev/delyvre` foi criada a partir de `wip/eventmenu-delivery-customer-app` no SHA `94a229a11bbc130ec82595fdb51d525355cb0897`, preservando as duas outras branches para mesclagem seletiva posterior.

### EventMenu GO

- `dev/eventmenu-go` permanece a linha canônica.
- As branches `eventmenu-go/*` antigas não podem ser apagadas ainda: comparações mostraram centenas de commits exclusivos em algumas delas.
- Exemplo: `eventmenu-go/continue-no-actions` possui 119 commits que não estão no `dev/eventmenu-go`; `integration-no-actions` possui 252; `server-continue-no-actions` possui 265.
- As branches temporárias de APK de 17/09 também divergem e carregam snapshots históricos; devem ser arquivadas antes de qualquer remoção.

### Desktop

- `dev/eventmenu-desktop` permanece canônica.
- `feature/eventmenu-desktop`, `wip/eventmenu-desktop-hardware-fiscal`, `-2`, `-build`, `-final` e `-work` apontam exatamente para o mesmo SHA `47f33db10e83e3396325de63595b82cc93373a28`.
- `wip/eventmenu-desktop-hardware-fiscal-code` possui um commit exclusivo de grafo (`3df2ea8878c87c70dedeb6e908550e762554e5b5`), mas a mudança funcional dele — campo `provider` no modelo PIX — já existe e está mais completa em `dev/eventmenu-desktop`.
- O `dev/eventmenu-desktop` estava 30 commits à frente dessa linha antiga.

### Servidor

- `wip/eventmenu-delivery-customer-auth-payments` era descendente direto de `dev/eventmenu-server`: 253 commits à frente e zero atrás.
- Foi seguro avançar `dev/eventmenu-server` por fast-forward, sem force, de `cbbc56c6b7353bc6ad5677f0fb04850540011cf7` para `c285afb50d5197e00f812a907fa899836be02238`.
- A linha moderna contém autenticação do cliente, PIX, Mercado Pago, pagamentos, cupons, WhatsApp, tickets/eventos, migrations, EventMenu Connect e serviços modernos do backend.

### Portal

- `feature/eventmenu-root-portal` foi usada como origem da nova `dev/eventmenu-portal`, no SHA `32f2684958f2e39f51156bcc34affa11afc4f9f6`.
- Ela contém o portal raiz e a publicação/distribuição automática dos APKs.

### Duplicatas exatas detectadas

Grupo GPS — mesmo SHA `3d86422b372651dc6b3403cf3660d19a1117255a`:

- `feature/delivery-live-gps-2`
- `feature/delivery-live-gps-3`
- `feature/delivery-live-gps-4`
- `feature/delivery-live-gps-final`
- `feature/delivery-live-gps-v1`

Grupo Desktop fiscal — mesmo SHA `47f33db10e83e3396325de63595b82cc93373a28`:

- `feature/eventmenu-desktop`
- `wip/eventmenu-desktop-hardware-fiscal`
- `wip/eventmenu-desktop-hardware-fiscal-2`
- `wip/eventmenu-desktop-hardware-fiscal-build`
- `wip/eventmenu-desktop-hardware-fiscal-final`
- `wip/eventmenu-desktop-hardware-fiscal-work`

## Relatório das 65 branches originais

Legenda de exclusividade:

- `SIM` — há divergência/commits próprios ou a branch é a linha canônica.
- `NÃO` — duplicata exata ou ancestral confirmado da linha de destino.
- `POTENCIAL` — preservar até triagem de conteúdo; não remover automaticamente.
- `GRAFO SIM / CONTEÚDO JÁ INCORPORADO` — há commit exclusivo no histórico, mas a mudança útil foi verificada na linha canônica.

| Branch | Status | Produto | Head SHA auditado | Exclusivo? | Destino | Ação recomendada |
|---|---|---|---|---|---|---|
| `ci/eventmenu-go-build-8f2f80f` | ARQUIVAR | GO/CI | `3cd6f71e` | SIM | `dev/eventmenu-go` | preservar até extrair qualquer ajuste de workflow |
| `dev/eventmenu-delivery` | MESCLAR | DELYVRE | `3c3fa14e` | SIM | `dev/delyvre` | revisar 24 commits exclusivos; não apagar |
| `dev/eventmenu-desktop` | MANTER | Desktop | `617c1155` | SIM | próprio | canônica |
| `dev/eventmenu-go` | MANTER | GO | `af881ac0` | SIM | próprio | canônica |
| `dev/eventmenu-server` | MANTER | Server | `cbbc56c6` antes da organização | NÃO vs linha moderna | próprio | fast-forward seguro aplicado após backup |
| `eventmenu-go/continue-no-actions` | MESCLAR | GO | `5f43f481` | SIM (119 commits) | `dev/eventmenu-go` | triagem seletiva; contém lógica de contingência |
| `eventmenu-go/integration-no-actions` | MESCLAR | GO | `7afa59aa` | SIM (252 commits) | `dev/eventmenu-go` | triagem seletiva |
| `eventmenu-go/server-continue-no-actions` | MESCLAR | GO/Server | `98e7cb29` | SIM (265 commits) | GO/Server | triagem seletiva |
| `eventmenu-go/server-package-current` | ARQUIVAR | Server/Build | `47617f21` | POTENCIAL | `dev/eventmenu-server` | preservar até revisar pacote |
| `feat/login-onboarding-email-config` | MESCLAR/ARQUIVAR | Server/Auth | `d045bcaa` | POTENCIAL | `dev/eventmenu-server` | conferir implementação moderna antes de remover |
| `feature/android-fcm-realtime` | MESCLAR/ARQUIVAR | GO/Push | `ceec8c65` | POTENCIAL | `dev/eventmenu-go`/Server | revisar FCM antes de arquivar |
| `feature/app-icon-auth-flow` | MESCLAR/ARQUIVAR | Apps/Auth | `a214e9d5` | POTENCIAL | apps canônicos | revisar conteúdo |
| `feature/customer-order-messaging` | MESCLAR/ARQUIVAR | DELYVRE | `9f35b498` | POTENCIAL | `dev/delyvre`/Server | revisar mensagens de pedido |
| `feature/delivery-live-gps` | ARQUIVAR | Delivery GPS | `fc6bba37` | SIM/POTENCIAL | GO/DELYVRE | manter como referência Android 6+ |
| `feature/delivery-live-gps-2` | ARQUIVAR | Delivery GPS | `3d86422b` | representante do grupo | GO/DELYVRE | manter uma referência do grupo |
| `feature/delivery-live-gps-3` | APAGAR FUTURAMENTE | Delivery GPS | `3d86422b` | NÃO — duplicata exata | grupo GPS | remover só após backup/validação final |
| `feature/delivery-live-gps-4` | APAGAR FUTURAMENTE | Delivery GPS | `3d86422b` | NÃO — duplicata exata | grupo GPS | remover só após backup/validação final |
| `feature/delivery-live-gps-final` | APAGAR FUTURAMENTE | Delivery GPS | `3d86422b` | NÃO — duplicata exata | grupo GPS | nome final não tem valor técnico adicional |
| `feature/delivery-live-gps-v1` | APAGAR FUTURAMENTE | Delivery GPS | `3d86422b` | NÃO — duplicata exata | grupo GPS | duplicata exata |
| `feature/delivery-tracking-premium` | MESCLAR/ARQUIVAR | Delivery GPS | `d65aabcf` | POTENCIAL | GO/DELYVRE | revisar tracking premium |
| `feature/device-auth-v2` | MESCLAR/ARQUIVAR | Auth | `4ae6eefb` | POTENCIAL | Server/apps | revisar autenticação de dispositivo |
| `feature/event-order-qr` | MESCLAR/ARQUIVAR | Eventos | `bb78886b` | POTENCIAL | Server/GO | revisar QR de pedidos |
| `feature/event-public-bar-orders` | MESCLAR/ARQUIVAR | Eventos | `daf70ac4` | POTENCIAL | Server/GO | revisar pedidos públicos de bar |
| `feature/eventmenu-desktop` | ARQUIVAR | Desktop | `47f33db1` | representante duplicado | `dev/eventmenu-desktop` | manter como referência antiga até limpeza final |
| `feature/eventmenu-go-mobile-sync` | MESCLAR/ARQUIVAR | GO | `584573fd` | POTENCIAL | `dev/eventmenu-go` | revisar sincronização mobile |
| `feature/eventmenu-go-server-support` | MESCLAR/ARQUIVAR | GO/Server | `88cceabc` | POTENCIAL | GO/Server | revisar suporte servidor |
| `feature/eventmenu-root-portal` | RENOMEAR/ARQUIVAR | Portal | `32f26849` | SIM | `dev/eventmenu-portal` | nova canônica já criada; manter origem por enquanto |
| `feature/loyalty-points-complete` | MESCLAR/ARQUIVAR | Fidelidade | `24c54124` | POTENCIAL | Server/DELYVRE | revisar pontos/fidelidade |
| `feature/pricing-finance-core` | MESCLAR/ARQUIVAR | Financeiro | `8d0d88d3` | POTENCIAL | Server | revisar pricing/finance |
| `fix/android-order-cancellation-request` | MESCLAR/ARQUIVAR | Android/Orders | `019f6435` | POTENCIAL | GO/DELYVRE | validar correção atual |
| `fix/app-ux-polish-2` | MESCLAR/ARQUIVAR | Apps/UX | `3a19473e` | POTENCIAL | apps canônicos | comparar UX atual |
| `fix/app-ux-tenant-branding` | MESCLAR/ARQUIVAR | Apps/Branding | `52c01965` | POTENCIAL | apps canônicos | comparar branding atual |
| `fix/delivery-live-refresh-20260917` | MESCLAR/ARQUIVAR | Delivery GPS | `f0168898` | POTENCIAL | GO/DELYVRE | revisar atualização ao vivo |
| `fix/eventmenu-go-delivery-e2e` | MESCLAR | GO | `1bb59584` | POTENCIAL | `dev/eventmenu-go` | validar correções E2E |
| `fix/orders-cancellation-payment-ui` | MESCLAR/ARQUIVAR | Orders/Payment | `c3260171` | POTENCIAL | Server/apps | validar contra linha moderna |
| `fix/public-branding-ux` | MESCLAR/ARQUIVAR | Web/Branding | `c520087e` | POTENCIAL | Server/Portal | revisar branding público |
| `fix/stable-dev-signing` | ARQUIVAR | Android/Build | `cb003f48` | POTENCIAL | GO/DELYVRE CI | preservar até padronizar assinatura |
| `hardening/android-offline-readonly` | ARQUIVAR | GO/Hardening | `24bb0491` | NÃO — ancestral confirmado | linha moderna | candidato a remoção futura após backup |
| `hardening/cron-onboarding` | ARQUIVAR | Server/Hardening | `25b2d19b` | POTENCIAL | Server | preservar até revisão |
| `hardening/fcm-build-config` | ARQUIVAR | GO/CI | `aa044268` | POTENCIAL | GO/Server | preservar até revisão |
| `hardening/load-test-tool` | ARQUIVAR | QA/Server | `3281d123` | POTENCIAL | Server | ferramenta de carga; manter como referência |
| `hardening/ops-health-push-test` | ARQUIVAR | Ops | `6fcf9397` | POTENCIAL | Server | preservar testes operacionais |
| `hardening/production-readiness` | MESCLAR | Produção | `e39f6f46` | SIM (5 commits vs linha moderna) | Server/develop | revisar 5 commits exclusivos |
| `hardening/sensitive-rate-limits` | ARQUIVAR/MESCLAR | Security | `2f3f30f3` | POTENCIAL | Server | conferir rate limits modernos |
| `hardening/server-realtime-queue-health` | ARQUIVAR/MESCLAR | Server | `f25f7887` | POTENCIAL | Server | conferir realtime/filas |
| `hardening/system-analysis-corrections` | ARQUIVAR/MESCLAR | Sistema | `29364333` | POTENCIAL | Server/develop | revisar correções de auditoria |
| `hardening/verified-backups` | ARQUIVAR | Ops/Backup | `418b3ad0` | POTENCIAL | Server/Ops | manter referência de backup |
| `hardening-v9.2-continuacao` | ARQUIVAR | Legado | `d749775e` | POTENCIAL/legado | histórico | não usar como base atual |
| `main` | MANTER | Produção | `8f2f80f0` | SIM | próprio | não alterada; somente releases validadas |
| `release/android-fast-1.0.1` | ARQUIVAR | Release Android | `579fdb7e` | snapshot | histórico | conservar como release histórica |
| `release/eventmenu-1.0.0-rc` | ARQUIVAR | Release | `ea215212` | snapshot | histórico | conservar RC até release final |
| `server/order-qr-partial-pickup` | MESCLAR/ARQUIVAR | Server/Orders | `9a73d657` | POTENCIAL | Server | revisar retirada parcial/QR |
| `test/gps-premium-side-by-side` | ARQUIVAR | Teste GPS | `74c2efdf` | teste deliberado | histórico/QA | não mesclar em `main`; PR original já indicava uso apenas para teste |
| `tmp/eventmenu-go-apk-build-20260917-current` | ARQUIVAR | GO/Build | `b0de4386` | POTENCIAL | histórico GO | snapshot; não apagar até triagem |
| `tmp/eventmenu-go-apk-build-20260917b` | ARQUIVAR | GO/Build | `b5a22fb7` | SIM (251 commits vs dev GO) | histórico GO | preservar snapshot |
| `tmp/eventmenu-go-apk-build-20260917` | ARQUIVAR | GO/Build | `4b244847` | SIM (253 commits vs dev GO) | histórico GO | preservar snapshot |
| `tmp-noop` | APAGAR FUTURAMENTE | Temporária | `5e7cfa8d` | POTENCIAL baixo | nenhum | confirmar ausência de conteúdo útil e remover depois |
| `wip/eventmenu-delivery-customer-app` | RENOMEAR/ARQUIVAR | DELYVRE | `94a229a1` | SIM | `dev/delyvre` | nova canônica criada deste SHA; origem mantida |
| `wip/eventmenu-delivery-customer-auth-payments` | MESCLAR/ARQUIVAR | Server/full-stack | `c285afb5` | NÃO vs novo `dev/eventmenu-server` | `dev/eventmenu-server` | servidor canônico avançado para este SHA; manter enquanto PRs dependerem dela |
| `wip/eventmenu-desktop-hardware-fiscal` | APAGAR FUTURAMENTE | Desktop | `47f33db1` | NÃO — duplicata exata | grupo Desktop fiscal | remover após backup final |
| `wip/eventmenu-desktop-hardware-fiscal-2` | APAGAR FUTURAMENTE | Desktop | `47f33db1` | NÃO — duplicata exata | grupo Desktop fiscal | remover após backup final |
| `wip/eventmenu-desktop-hardware-fiscal-build` | APAGAR FUTURAMENTE | Desktop | `47f33db1` | NÃO — duplicata exata | grupo Desktop fiscal | remover após backup final |
| `wip/eventmenu-desktop-hardware-fiscal-code` | ARQUIVAR | Desktop | `3df2ea88` | GRAFO SIM / CONTEÚDO JÁ INCORPORADO | `dev/eventmenu-desktop` | manter até fechar PR/histórico; mudança PIX já presente na canônica |
| `wip/eventmenu-desktop-hardware-fiscal-final` | APAGAR FUTURAMENTE | Desktop | `47f33db1` | NÃO — duplicata exata | grupo Desktop fiscal | remover após backup final |
| `wip/eventmenu-desktop-hardware-fiscal-work` | APAGAR FUTURAMENTE | Desktop | `47f33db1` | NÃO — duplicata exata | grupo Desktop fiscal | remover após backup final |

## Branches criadas na reorganização

| Branch | Origem | Estado |
|---|---|---|
| `backup/2026-09-21-main` | `main@8f2f80f0` | backup de segurança |
| `backup/2026-09-21-dev-eventmenu-server` | `dev/eventmenu-server@cbbc56c6` | backup antes do fast-forward |
| `develop` | `main@8f2f80f0` | integração geral inicial |
| `dev/delyvre` | `wip/eventmenu-delivery-customer-app@94a229a1` | canônica DELYVRE |
| `dev/eventmenu-portal` | `feature/eventmenu-root-portal@32f26849` | canônica Portal |

## Alteração aplicada na branch de servidor

`dev/eventmenu-server` foi movida por fast-forward, sem force:

`cbbc56c6 -> c285afb5`

A comparação antes da alteração confirmou:

- 253 commits à frente na linha moderna;
- 0 commits atrás;
- merge-base igual ao antigo head de `dev/eventmenu-server`.

Portanto o avanço não descartou histórico da branch de servidor.

## O que NÃO foi feito

- nenhuma branch deletada;
- nenhum force push;
- nenhum merge automático de branches divergentes;
- nenhuma alteração em `main`;
- nenhuma refatoração massiva de diretórios;
- nenhum merge de snapshot temporário no código atual.

## Próximas etapas recomendadas

1. Fazer triagem dos 24 commits exclusivos de `dev/eventmenu-delivery` contra `dev/delyvre`.
2. Fazer triagem das branches `eventmenu-go/*` contra `dev/eventmenu-go`, começando por contingência/failover.
3. Confirmar que todas as funcionalidades Desktop das branches antigas estão semanticamente presentes em `dev/eventmenu-desktop`; o PIX já foi confirmado.
4. Revisar os 5 commits exclusivos de `hardening/production-readiness`.
5. Retargetar/encerrar PRs antigos que apontam para branches WIP substituídas.
6. Integrar cada `dev/*` em `develop` somente via PR e testes.
7. Promover `develop` para `main` apenas após build/testes de todos os produtos.
8. Só então criar backups finais e remover aliases/branches temporárias comprovadamente redundantes.

## Política futura de branches

- Trabalho novo: `feature/<produto>-<descricao>` ou `fix/<produto>-<descricao>`.
- Merge para a branch `dev/<produto>` correspondente.
- Após validação do produto, PR para `develop`.
- Após validação integrada, PR/release para `main`.
- Branches `feature/*` e `fix/*` devem ser removidas depois do merge e da retenção necessária de histórico/release.
