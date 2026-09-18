-- SQLite já armazena payments.provider como TEXT. Esta migração mantém paridade numérica
-- com MySQL; o serviço passa a aceitar o valor lógico 'tef'.
SELECT 1;
