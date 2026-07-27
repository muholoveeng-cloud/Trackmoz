-- Script para corrigir os perfis de caminhoneiros
-- Versão: 1.0
-- Data: 2024-05-25

-- Verifica e atualiza a estrutura da tabela
ALTER TABLE perfil_caminhoneiro MODIFY COLUMN tipo_veiculo VARCHAR(50) DEFAULT NULL;

-- Encontrar usuários caminhoneiros sem perfil
INSERT INTO perfil_caminhoneiro (usuario_id, disponibilidade)
SELECT id, 'indisponivel' FROM usuarios 
WHERE tipo_usuario = 'caminhoneiro' 
AND id NOT IN (SELECT usuario_id FROM perfil_caminhoneiro);

-- Verifica e corrige valores NULL para campos essenciais
UPDATE perfil_caminhoneiro 
SET tipo_veiculo = 'Não informado' 
WHERE tipo_veiculo IS NULL;

-- Atualiza a data de atualização de localização para os que não têm
UPDATE perfil_caminhoneiro 
SET ultima_atualizacao_local = NOW() 
WHERE ultima_atualizacao_local IS NULL;

-- Exibe os perfis atualizados
SELECT u.id, u.nome, u.email, pc.tipo_veiculo, pc.disponibilidade  
FROM usuarios u 
JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
WHERE u.tipo_usuario = 'caminhoneiro'; 