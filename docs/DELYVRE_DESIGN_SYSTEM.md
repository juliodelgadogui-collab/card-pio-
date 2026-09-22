# DELYVRE Design System

Este documento define a base visual do aplicativo do cliente DELYVRE.

## Princípios

- Interface de consumidor, não painel administrativo.
- Ação primária evidente e poucas decisões por tela.
- Informações de preço, prazo e disponibilidade próximas da ação correspondente.
- Estados de carregamento, vazio, erro e indisponibilidade devem ser explícitos.
- Componentes devem usar `MaterialTheme` e os tokens do DELYVRE, evitando cores, raios e espaçamentos arbitrários.

## Tokens

Os tokens ficam em `DelyvreDesignTokens.kt`:

- `DelyvreSpacing`: escala de espaçamento.
- `DelyvreSize`: alvos de toque, alturas e dimensões recorrentes.
- `DelyvreElevation`: elevações padronizadas.

A paleta, tipografia e formas ficam em `DelyvreTheme.kt`.

## Cores semânticas

- `primary`: ação principal e marca DELYVRE.
- `surface`: cards e superfícies principais.
- `surfaceVariant`: blocos de apoio, placeholders e estados neutros.
- `error`: falhas e indisponibilidades.
- `DelyvreSuccess`: aberto, confirmado, entregue e sucesso.
- `DelyvreWarning`: alertas que não impedem continuidade.

Telas novas não devem usar `Color.White`, `Color.Black` ou cores hexadecimais quando existir equivalente semântico no tema.

## Tipografia

- `headline*`: títulos de página e chamadas principais.
- `title*`: títulos de seção, cards e produtos.
- `body*`: informações e descrições.
- `label*`: botões, chips e metadados curtos.

## Formas e toque

- Alvo de toque mínimo: `DelyvreSize.minTouch`.
- Botão primário: mínimo `DelyvreSize.primaryButtonHeight`.
- Cards devem preferir `MaterialTheme.shapes.medium` ou `large`.
- Bottom sheets e superfícies de destaque usam `extraLarge`.

## Estados

Todo carregamento remoto deve prever:

1. loading/skeleton;
2. sucesso;
3. vazio quando aplicável;
4. erro amigável com ação de tentar novamente quando possível.

Imagens usam o componente remoto do DELYVRE com cache, skeleton e fallback.

## Modo escuro

A paleta escura já existe no tema, mas permanece opt-in enquanto telas legadas ainda usam cores fixas. O modo escuro só deve ser ativado globalmente após a migração das telas ativas para cores semânticas do `MaterialTheme`.

## Migração incremental

Cada módulo da evolução do DELYVRE deve substituir valores visuais locais pelos tokens ao ser revisado. Isso evita uma refatoração visual massiva e reduz o risco de regressões.
