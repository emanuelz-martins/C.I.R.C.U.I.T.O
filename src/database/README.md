# Database – C.I.R.C.U.I.T.O.

Modelo físico MySQL do sistema, gerado com base na Modelagem Lógica (Figura 5 — PPI 2025).

## Tabelas

| Tabela | Descrição |
|---|---|
| `Categoria` | Categorias dos componentes do catálogo |
| `Componente` | Itens disponíveis para empréstimo |
| `Movimentacao_Estoque` | Histórico de entradas e saídas de estoque |
| `Usuario` | Usuários do sistema (estudante, laboratorista, admin) |
| `Termo_Uso` | Versões dos termos de uso do sistema |
| `Termo_Aceito` | Registro de aceite dos termos por usuário |
| `Log_Auditoria` | Log de ações realizadas no sistema |
| `Notificacao` | Notificações enviadas aos usuários |
| `Pedido` | Pedidos de empréstimo realizados |
| `Item_Pedido` | Itens individuais de cada pedido |
| `Ocorrencia` | Registros de danos, perdas ou inutilizações |
| `Renovacao` | Solicitações de renovação de prazo de devolução |

## Como aplicar o schema

```bash
mysql -u root -p circuito < seeds/schema.sql
```

> Certifique-se de que o banco `circuito` existe antes de executar o comando, ou adicione `CREATE DATABASE IF NOT EXISTS circuito; USE circuito;` no início do arquivo.
