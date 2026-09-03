# EventMenu Premium v9.2 — Paridade com o sistema antigo

## Regra do projeto

O sistema antigo é a referência oficial de experiência, navegação e funcionalidades.

A versão nova deve manter:
- a mesma organização funcional do painel antigo;
- o mesmo conceito de Cardápio Digital editável;
- os mesmos módulos operacionais existentes no sistema antigo;
- os mesmos fluxos principais para administrador, gerente, caixa, balconista, garçom, cozinha, entregador e promotor;
- linguagem em português e aparência Premium do sistema antigo;
- compatibilidade mobile equivalente ou superior.

A versão nova pode divergir do antigo somente quando a mudança for necessária para segurança, consistência transacional, multiempresa, pagamentos, NFC, estoque ou estabilidade.

## Módulos que devem permanecer equivalentes ao antigo

### Administração
- Visão geral / dashboard
- Busca global
- Notificações
- Configurações da empresa
- Personalização visual
- Multiunidade / filiais
- Equipe e permissões
- Auditoria
- Relatórios
- Exportações
- Backup / restauração
- Diagnóstico
- Termos e privacidade
- Recuperação de senha

### Cardápio Digital
- Editor visual separado do cadastro de produtos
- Logo
- Banner / capa
- Cores da empresa
- Título e subtítulo
- Mensagem do cardápio
- Categorias e ordenação
- Produtos
- Imagens
- Descrição
- Preço e promoção
- Destaques e selos
- Adicionais / opções
- Combos
- Busca de produtos
- Carrinho
- Delivery
- Retirada
- Agendamento
- Cupons
- Checkout e pagamento
- Fidelidade / pontos

### Restaurante / PDV
- PDV / balcão
- Balconista separado de caixa
- Retirada parcial de produtos
- Mesas
- Comandas
- QR da mesa
- Chamar garçom
- Pedir conta
- Cozinha / KDS
- Caixa
- Abertura e fechamento
- Delivery
- Motoboy / entregador
- Histórico de pedidos

### Eventos
- Cadastro e edição de eventos
- Página pública
- Lotes
- Ingressos
- QR Code
- Check-in
- Transferência de ingresso
- Lista de convidados
- Promotores / afiliados
- Cupons
- Comissões
- Relatórios

### SaaS / Super ADM
- Multiempresa
- Planos
- Status / vencimento
- Módulos por empresa
- Domínio personalizado
- Branding
- Criação e bloqueio de empresas
- Administração de usuários principais

## Regras que NÃO devem voltar do sistema antigo

As seguintes proteções da v9.2 são obrigatórias mesmo quando o comportamento antigo era diferente:
- Garçom, balconista e entregador não confirmam pagamentos.
- Entregador só vê pedidos atribuídos a ele.
- Gateways e NFC somente para administradores autorizados.
- Pagamento nunca é confirmado confiando apenas no navegador ou aplicativo.
- Webhooks devem ter assinatura validada.
- Provider, valor, moeda, conta e pedido devem ser validados.
- Pagamentos, estoque, pontos, CRM e comissão devem ser idempotentes e transacionais.
- Pedidos cancelados/finalizados não podem receber nova cobrança.
- Estoque e ingressos devem usar reserva para evitar venda duplicada.

## Critério de conclusão

A migração só é considerada concluída quando um usuário acostumado ao sistema antigo consegue executar os mesmos fluxos no novo sem sentir que está usando outro produto, exceto pelas melhorias de segurança e estabilidade.
