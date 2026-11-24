-- Alinha descrições longas dos itens com a estrutura utilizada no servidor.
-- Amplia a capacidade do campo e preserva os valores existentes.
ALTER TABLE Componente MODIFY COLUMN descricao TEXT NULL;
