# Documentacao — pages_laboratorista

Paginas do painel do laboratorista para gestao de pedidos e catalogo de itens.

---

# acessar.php

Pagina para visualizar e gerenciar pedidos dos estudantes.

---

## Fluxo de status (state machine)

```
pendente → [Aprovar] → em-separacao → [Pronto para retirada] → pronto-para-retirada → [Em andamento] → em-andamento → [Finalizar] → finalizado
         → [Negar]  → negado
```

---

## Menu dos 3 risquinhos (por status)

| Status do pedido       | Opções no menu                          |
|------------------------|-----------------------------------------|
| `pendente`             | Aprovar pedido / Negar pedido           |
| `em-separacao`         | Pronto para retirada                    |
| `pronto-para-retirada` | Em andamento                            |
| `em-andamento`         | Finalizar pedido                        |
| `renovacao-solicitada` | Aprovar renovacao / Negar renovacao     |

---

## Notificacoes automaticas ao estudante

- **Aprovar** → `"Pedido aprovado!"`
- **Negar** → `"Pedido negado. Justificativa: [texto]"` (campo obrigatorio com modal)
- **Pronto para retirada** → `"Seu pedido esta pronto para retirada! Dirija-se ao laboratorio para buscar."`

---

## Estrutura tecnica

- **Handler POST no topo** com PRG pattern (evita resubmissao ao recarregar a pagina)
- **Query real via PDO** pronta para quando o BD estiver conectado, com `try/catch` para falha silenciosa
- **Modal de negacao** com validacao client-side e server-side — justificativa e obrigatoria
- **Busca + filtro por status** via GET (parametros `q` e `status`)
- Design identico ao restante do projeto (dark theme `#141414`)

---

## Descricao de cada status

| Status                 | Descricao                                                                 |
|------------------------|---------------------------------------------------------------------------|
| `pendente`             | Pedido enviado pelo estudante, aguardando avaliacao do laboratorista      |
| `em-separacao`         | Pedido aprovado, laboratorista esta separando os itens fisicamente        |
| `pronto-para-retirada` | Pacote preparado, estudante notificado para buscar no laboratorio         |
| `em-andamento`         | Estudante retirou o pacote. Prazo de 1 semana para devolucao             |
| `finalizado`           | Itens devolvidos, emprestimo encerrado                                    |
| `negado`               | Pedido recusado pelo laboratorista com justificativa obrigatoria          |
| `renovacao-solicitada` | Estudante solicitou mais prazo durante o status `em-andamento`            |

---

## Arquivos relacionados (acessar.php)

| Arquivo                                  | Descricao                                              |
|------------------------------------------|--------------------------------------------------------|
| `public/pages_laboratorista/acessar.php` | Esta pagina — gestao de pedidos pelo laboratorista     |
| `public/pages_aluno/pedido.php`          | Visao do estudante com barra de progresso do pedido    |
| `public/pages_aluno/notificacoes.php`    | Onde o estudante recebe as notificacoes de status      |
| `src/config/database.php`               | Conexao PDO com MySQL via funcao `db()`                |
| `public/includes/auth_check.php`        | Protecao de acesso por perfil (laboratorista/admin)    |

---

# catalogo.php

Pagina para o laboratorista visualizar, buscar e gerenciar os itens disponiveis para emprestimo.

## Funcionalidades

- Lista todos os componentes do BD (sem filtro de status — laboratorista ve todos, incluindo indisponiveis)
- Busca por nome ou categoria via GET (`?q=termo`)
- Indicador colorido de disponibilidade por item (ponto verde = disponivel, vermelho = indisponivel)
- Menu de 3 risquinhos por item com opcao "Editar item" (modal inline para alterar nome e descricao)
- Botao "Cadastrar item" que redireciona para `cadastrar_catalogo.php`
- Flash de sucesso ao retornar com `?cadastro=ok`

## Campos exibidos por item

| Campo           | Coluna BD         | Descricao                              |
|-----------------|-------------------|----------------------------------------|
| Thumbnail       | `imagem_url`      | Foto do componente (placeholder se vazio) |
| Categoria       | `cat_nome`        | Nome da categoria pai                  |
| Nome            | `nome`            | Nome do componente                     |
| Descricao curta | `descricao`       | Primeira linha do campo descricao      |
| Estoque         | `qtd_disponivel`  | Quantidade atual disponivel            |
| Status          | `status_atual`    | disponivel / indisponivel              |

## Edicao de item (modal)

- Abre inline ao clicar em "Editar item" no menu
- Campos editaveis: `nome` e `descricao`
- POST + PRG pattern — redireciona para `catalogo.php` apos salvar
- Validacao client-side e server-side (nome obrigatorio)

---

# cadastrar_catalogo.php

Formulario para cadastrar novos componentes no catalogo.

## Campos do formulario

| Campo do form               | Coluna BD        | Observacao                                          |
|-----------------------------|------------------|-----------------------------------------------------|
| Imagem                      | `imagem_url`     | Upload JPG/PNG/GIF/WebP, max 5MB, salvo em `assets/img/componentes/` |
| Nome do item                | `nome`           | Obrigatorio, max 200 caracteres                     |
| Categoria                   | `id_cat`         | Select populado da tabela `Categoria`               |
| Descricao curta             | `descricao`      | Primeira linha — exibida nos cards do index         |
| Quantidade maxima por usuario | `qtd_max_user` | Limite de unidades por pedido de aluno              |
| Quantidade minima em estoque  | `qtd_minima`   | Threshold de alerta de estoque baixo                |
| Descricao completa          | `descricao`      | Anexada apos `\n` — exibida em `item.php` como specs |

## Como descricao e armazenada

```
descricao = "[descricao_curta]\n[descricao_completa]"
```

- `item.php` ja divide por `\n`: primeira metade → Especificacoes Tecnicas, segunda metade → Alertas/Observacoes
- `index.php` exibe apenas a primeira linha no card do catalogo do aluno

## Valores padrao no INSERT

| Coluna          | Valor inicial |
|-----------------|---------------|
| `qtd_disponivel`| `0`           |
| `status_atual`  | `"disponivel"`|

## Nota de banco de dados

A coluna `qtd_minima` e nova e precisa ser criada caso nao exista:

```sql
ALTER TABLE Componente ADD COLUMN qtd_minima INT DEFAULT 0;
```

A coluna `qtd_max_user` ja existe (usada por `item.php`).

---

# index.php (atualizacoes)

O catalogo do aluno (`public/index.php`) foi atualizado para:

- Incluir `c.qtd_max_user` no SELECT
- Exibir "Max. por usuario: X unid." no card quando o campo estiver preenchido

---

## Arquivos relacionados (catalogo)

| Arquivo                                            | Descricao                                              |
|----------------------------------------------------|--------------------------------------------------------|
| `public/pages_laboratorista/catalogo.php`          | Lista e gerencia itens do catalogo                     |
| `public/pages_laboratorista/cadastrar_catalogo.php`| Formulario de cadastro de novo item                    |
| `public/index.php`                                 | Catalogo do aluno — exibe itens com status "disponivel"|
| `public/pages_aluno/item.php`                      | Detalhe do item — usa `qtd_max_user` e `descricao`     |
| `public/assets/img/componentes/`                   | Diretorio de imagens enviadas pelo laboratorista       |
