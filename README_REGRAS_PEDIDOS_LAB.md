# Regras de Atendimento de Pedidos (Laboratorista)

Documento rápido para entender como funciona a regra de posse de pedido na tela de pedidos do laboratorista.

---

## Objetivo

Evitar conflito entre laboratoristas quando vários pedidos aparecem ao mesmo tempo, garantindo clareza sobre quem está atendendo cada pedido.

---

## Fluxo resumido

1. Pedido aparece com a pergunta: **Deseja atender esse pedido?**
2. Primeiro laboratorista que clicar em **Sim (✓)** assume o pedido.
3. Enquanto o pedido estiver assumido:
   - Outros laboratoristas veem: **Pedido pego por NOME**
   - Outros laboratoristas **não podem alterar** esse pedido.
4. O laboratorista dono pode clicar em **Liberar para outros Laboratoristas**.
5. Após liberar, o pedido entra em **modo aberto**:
   - Todos os laboratoristas podem alterar normalmente.
   - A etapa de **Sim/Não (✓/✗)** não aparece mais para esse pedido.

---

## Regra de notificação para o estudante

- A notificação de atendimento com nome do laboratorista é enviada **somente no primeiro clique em Sim (✓)**.
- Depois que o pedido é liberado para todos, **não envia nova notificação de posse** ao estudante.

Exemplo de mensagem:

- Seu pedido está em atendimento por Mayra.

---

## O que cada perfil vê

### Laboratorista que assumiu

- Badge: Pedido em andamento com você
- Botão: Liberar para outros Laboratoristas
- Pode usar as ações do pedido

### Outros laboratoristas (antes de liberar)

- Badge: Pedido em andamento com NOME
- Texto: Pedido pego por NOME
- Não podem usar as ações do pedido

### Todos os laboratoristas (após liberar)

- Pedido fica sem dono
- Todos podem usar as ações
- Não volta para etapa de assumir (✓/✗)

---

## Regra técnica (persistência)

A lógica usa três campos no pedido:

- id_laboratorista_responsavel
- nome_laboratorista_responsavel
- fluxo_livre_laboratoristas

Interpretação:

- fluxo_livre_laboratoristas = 0 → regra de dono ativo (pedido pode ficar privado)
- fluxo_livre_laboratoristas = 1 → regra aberta para todos (sem etapa inicial)

---

## Arquivo principal da implementação

- public/pages_laboratorista/acessar.php
