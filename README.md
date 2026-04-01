# C.I.R.C.U.I.T.O

**Controle Integrado de Registro e Catálogo Unificado de Itens Tecnológicos e Operacionais**

Sistema web para gerenciamento de empréstimos de componentes do Laboratório de Hardware do Instituto Federal Farroupilha. Desenvolvido como projeto da Prática Profissional Integrada (PPI) — 2025.

---

## Regras de Atendimento de Pedidos (Laboratorista)

Para consultar somente as regras de posse/liberação de pedidos (fluxo Sim/Não, bloqueio entre laboratoristas e modo aberto após liberar), veja:

- [`README_REGRAS_PEDIDOS_LAB.md`](README_REGRAS_PEDIDOS_LAB.md)

---

## Estrutura do Projeto

```
C.I.R.C.U.I.T.O/
├── public/                             # Webroot — único diretório exposto ao servidor
│   ├── index.php                       # Entry point / catálogo público
│   ├── login.php                       # Página de login
│   ├── logout.php                      # Encerramento de sessão
│   └── assets/
│       ├── css/
│       │   ├── style.css               # Estilos gerais
│       │   └── dark-mode.css           # Estilos do modo escuro
│       ├── js/
│       │   └── main.js                 # Scripts gerais
│       └── img/
│           └── componentes/            # Imagens dos componentes do catálogo
│
├── src/
│   ├── config/
│   │   ├── app.php                     # Configurações gerais da aplicação
│   │   ├── database.php                # Configurações do banco de dados
│   │   └── ldap.php                    # Configurações da autenticação LDAP
│   │
│   ├── controllers/
│   │   ├── AuthController.php          # Login, logout, autenticação LDAP
│   │   ├── CatalogoController.php      # Catálogo público, busca, detalhe do item
│   │   ├── CarrinhoController.php      # Adicionar, remover e enviar pedido
│   │   ├── PedidoController.php        # Listagem, detalhe, renovação e cancelamento
│   │   ├── ComponenteController.php    # CRUD de componentes (laboratorista)
│   │   ├── CategoriaController.php     # CRUD de categorias (laboratorista)
│   │   ├── UsuarioController.php       # Gerenciamento de usuários (admin)
│   │   ├── NotificacaoController.php   # Listagem e leitura de notificações
│   │   ├── RelatorioController.php     # Geração de relatórios (admin/laboratorista)
│   │   └── OcorrenciaController.php    # Registro de danos e perdas
│   │
│   ├── models/                         # Espelho das tabelas do banco de dados
│   │   ├── Usuario.php
│   │   ├── Componente.php
│   │   ├── Categoria.php
│   │   ├── Pedido.php
│   │   ├── BemPedido.php               # Itens de um pedido
│   │   ├── Ocorrencia.php              # Danos, perdas e inutilizações
│   │   ├── Renovacao.php               # Solicitações de renovação de empréstimo
│   │   ├── Notificacao.php
│   │   ├── LogAuditoria.php            # Registro de ações do sistema
│   │   ├── MovimentacaoEstoque.php     # Histórico de entradas/saídas do estoque
│   │   ├── TermoUso.php                # Versões dos termos de uso
│   │   └── TermoAceito.php             # Aceites dos termos por usuário
│   │
│   ├── views/
│   │   ├── layouts/                    # Componentes reutilizáveis de layout
│   │   │   ├── header.php
│   │   │   ├── footer.php
│   │   │   ├── nav_estudante.php
│   │   │   ├── nav_laboratorista.php
│   │   │   └── nav_admin.php
│   │   │
│   │   ├── auth/
│   │   │   └── login.php               # Tela de login
│   │   │
│   │   ├── catalogo/                   # Telas públicas (sem autenticação)
│   │   │   ├── index.php               # Home / catálogo de componentes
│   │   │   ├── busca.php               # Resultados de pesquisa
│   │   │   └── item.php                # Detalhamento do item
│   │   │
│   │   ├── estudante/
│   │   │   ├── home.php                # Home do estudante + termos de uso
│   │   │   ├── carrinho.php            # Carrinho de reserva
│   │   │   ├── pedidos.php             # Histórico de pedidos
│   │   │   ├── pedido.php              # Detalhamento e acompanhamento do pedido
│   │   │   ├── perfil.php              # Perfil do usuário
│   │   │   └── notificacoes.php        # Central de notificações
│   │   │
│   │   ├── laboratorista/
│   │   │   ├── home.php                # Dashboard do laboratorista
│   │   │   ├── pedidos.php             # Gerenciamento de pedidos
│   │   │   ├── pedido.php              # Aprovação, devolução e renovação
│   │   │   ├── itens/
│   │   │   │   ├── index.php           # Gerenciar itens do catálogo
│   │   │   │   └── form.php            # Cadastrar / editar item
│   │   │   └── categorias/
│   │   │       ├── index.php           # Gerenciar categorias
│   │   │       └── form.php            # Cadastrar / editar categoria
│   │   │
│   │   └── admin/
│   │       ├── home.php                # Dashboard do administrador
│   │       ├── usuarios/
│   │       │   ├── index.php           # Listagem de usuários
│   │       │   └── detalhe.php         # Detalhamento e bloqueio de usuário
│   │       └── relatorios/
│   │           ├── index.php           # Seleção de relatórios
│   │           └── emprestimos.php     # Relatório de empréstimos
│   │
│   ├── middleware/
│   │   ├── AuthMiddleware.php          # Verifica se o usuário está autenticado
│   │   └── RoleMiddleware.php          # Verifica o perfil de acesso (estudante/laboratorista/admin)
│   │
│   └── helpers/
│       ├── Session.php                 # Gerenciamento de sessão
│       ├── Email.php                   # Envio de notificações por e-mail
│       └── Ldap.php                    # Integração com servidor LDAP
│
├── database/
│   ├── migrations/
│   │   └── create_tables.sql           # Script de criação das tabelas
│   └── seeds/
│       └── seed_data.sql               # Dados iniciais para desenvolvimento
│
├── storage/
│   └── uploads/
│       └── componentes/                # Imagens enviadas via upload
│
├── .env.example                        # Modelo de variáveis de ambiente
├── .htaccess                           # Regras de reescrita de URL (Apache)
└── composer.json                       # Dependências PHP
```

---

## Perfis de Usuário

| Perfil | Acesso |
|---|---|
| **Estudante** | Catálogo, carrinho, pedidos, perfil, notificações |
| **Laboratorista** | Pedidos, itens, categorias, devoluções, ocorrências |
| **Administrador** | Usuários, relatórios, termos de uso, notificações |

---

## Tecnologias

- **Backend:** PHP (MVC)
- **Banco de dados:** MySQL
- **Autenticação:** LDAP institucional
- **Frontend:** HTML, CSS, JavaScript

---

## Como rodar local (XAMPP)

### 1) Pré-requisitos

- Git instalado
- XAMPP instalado (Apache + MySQL)
- PHP 8.0+ recomendado

### 2) Clonar o projeto

```bash
git clone git@github.com:emanueca/C.I.R.C.U.I.T.O.git
cd C.I.R.C.U.I.T.O
```

### 3) Colocar a pasta no diretório do XAMPP

- Linux (XAMPP): copie para `htdocs` (ex.: `/opt/lampp/htdocs/C.I.R.C.U.I.T.O`)
- Windows (XAMPP): copie para `C:\xampp\htdocs\C.I.R.C.U.I.T.O`

### 4) Subir Apache e MySQL

- Abra o XAMPP Control Panel
- Inicie **Apache** e **MySQL**

### 5) Criar banco e importar schema (phpMyAdmin)

1. Abra o phpMyAdmin
2. Crie o banco `circuito`
3. Vá em **Importar**
4. Selecione o arquivo `src/database/seeds/schema.sql`
5. Execute a importação

### 6) Configurar variáveis locais

No projeto, crie o arquivo local `.env` a partir do modelo `.env.example`:

```bash
cp .env.example .env
```

> Se você anotou `ssh.example`, no projeto atual o arquivo correto é `.env.example` e o arquivo final deve ser `.env`.

Depois, edite o `.env` com dados locais (XAMPP):

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/C.I.R.C.U.I.T.O/public

DB_CONNECTION=mysql
DB_PROFILE=xampp
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=circuito
DB_USERNAME=root
DB_PASSWORD=
```

### 7) Acessar no navegador

- URL: `http://localhost/C.I.R.C.U.I.T.O/public/login.php`

Se aparecer erro de conexão, revise primeiro os campos `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env`.

---

## Banco de Dados

O modelo físico completo está em [`src/database/seeds/schema.sql`](src/database/seeds/schema.sql).

### Tabelas

| Tabela | Descrição |
|---|---|
| `Categoria` | Categorias dos componentes do catálogo |
| `Componente` | Itens disponíveis para empréstimo |
| `Movimentacao_Estoque` | Histórico de entradas e saídas de estoque |
| `Usuario` | Usuários do sistema (estudante, laboratorista, admin) |
| `Termo_Uso` | Versões dos termos de uso do sistema |
| `Termo_Aceito` | Registro de aceite dos termos por usuário |
| `Log_Auditoria` | Log de ações realizadas no sistema |
| `Notificacao` | Notificações enviadas aos usuários (`tipo`: `automatica` = sistema, `aviso` = laboratorista) |
| `Pedido` | Pedidos de empréstimo realizados |
| `Item_Pedido` | Itens individuais de cada pedido |
| `Ocorrencia` | Registros de danos, perdas ou inutilizações |
| `Renovacao` | Solicitações de renovação de prazo de devolução |

### Como aplicar o schema

```bash
mysql -u root -p circuito < src/database/seeds/schema.sql
```

> Certifique-se de que o banco `circuito` existe antes de executar o comando, ou adicione `CREATE DATABASE IF NOT EXISTS circuito; USE circuito;` no início do arquivo.

### Migrações aplicadas

| Migração | Descrição |
|---|---|
| `ALTER TABLE Notificacao ADD COLUMN tipo` | Separa notificações automáticas do sistema (`automatica`) de avisos diretos do laboratorista (`aviso`) |
