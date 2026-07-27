# Scripts de Manutenção - Frete Ship

Este diretório contém scripts para manutenção e correção de problemas no sistema Frete Ship.

## Correção de Perfis de Caminhoneiros

### Problema

Os perfis de caminhoneiros podem ter problemas se foram criados antes da correção no sistema que exigia valores obrigatórios para campos que deveriam ser opcionais.

Sintomas do problema:
- Página de perfil mostra "Não informado" para todos os campos de veículo
- Informações de veículo não aparecem mesmo depois de serem preenchidas
- Problemas ao atualizar informações do perfil

### Solução

Execute o script `fix_profiles.php` para:

1. Corrigir a estrutura da tabela `perfil_caminhoneiro` para tornar campos opcionais
2. Criar perfis para caminhoneiros que não possuem um
3. Preencher valores padrão para campos obrigatórios que estejam vazios
4. Exibir um relatório com os perfis atualizados

### Como executar

1. Acesse a pasta raiz do projeto via terminal
2. Execute o comando:

```
php scripts/fix_profiles.php
```

3. Verifique o resultado exibido e confirme se o problema foi resolvido

### Melhorias implementadas

O sistema agora:
- Trata adequadamente valores NULL em campos do perfil
- Cria automaticamente um perfil para novos caminhoneiros com valores padrão
- Fornece mensagens mais claras quando dados estão indisponíveis

### Suporte

Se tiver problemas após executar este script, contate o administrador do sistema. 