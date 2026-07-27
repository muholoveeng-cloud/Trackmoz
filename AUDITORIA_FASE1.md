# RELATÓRIO TÉCNICO - FASE 1: AUDITORIA COMPLETA
**Projecto:** TrackMoz - Sistema de Gestão de Fretes
**Data:** 17 Junho 2026
**Responsável:** Auditoria de Software Sénior

---

## 1. MAPEAMENTO DE FUNCIONALIDADES EXISTENTES

### 1.1 Tipos de Usuário
- **admin** - Administrador do sistema
- **empresa** - Empresa contratante de fretes
- **caminhoneiro** - Motorista/caminhoneiro
- **transportador** - Transportador/gestor de frotas

### 1.2 Módulos Principais Implementados

#### Gestão de Usuários
- Cadastro de usuários (multi-tipo)
- Login/Logout com sessão
- Perfis por tipo (caminhoneiro, empresa, transportador)
- Aprovação de usuários (admin)
- Gestão de documentos de usuários

#### Gestão de Missões
- Criação de missões (empresa)
- Listagem de missões disponíveis (caminhoneiro)
- Envio de propostas (caminhoneiro)
- Aceitação de propostas (empresa)
- Atribuição de motoristas/viaturas (transportador)
- Delegação de missões
- Rastreamento em tempo real (GPS)
- Modo condução (GPS, rotas, emergências)

#### Gestão de Entregas
- Confirmação de entrega (3 métodos: OTP, destinatário cadastrado, manual)
- Upload de fotos (carga, documento, assinatura)
- Avaliação de entregas
- Comprovativo de entrega (POD/ePOD)

#### Gestão de Parcerias
- Criação de parcerias (empresa → transportador)
- Negociação bilateral
- Aprovação bilateral
- Validação por admin
- Histórico de negociações
- Versionamento de contratos

#### Gestão de Documentos
- Upload de documentos (usuários, missões)
- Explorador de documentos
- Geração de documentos do sistema (contratos, guias, facturas, recibos)
- Numeração automática com prefixos
- Registro de documentos oficiais

#### Comunicação
- Chat entre usuários
- Chat contextualizado por missão
- Upload de anexos no chat
- Notificações do sistema

#### Gestão de Frota (Transportador)
- Cadastro de veículos
- Cadastro de motoristas
- Documentos de veículos
- Manutenções
- Abastecimentos

#### Administração
- Dashboard executivo
- Mapa geral de missões
- Gestão de usuários
- Verificação de documentos
- Gestão de emergências
- Relatórios
- Configurações do sistema
- Backup

---

## 2. PÁGINAS INCOMPLETAS

### 2.1 Páginas Redirecionadoras (Stub)

#### `pages/transportador/perfil.php`
**Status:** INCOMPLETO (10 linhas)
**Problema:** Apenas redireciona para dashboard, não implementa perfil
**Impacto:** Transportadores não podem editar perfil
**Prioridade:** ALTA

```php
<?php
session_start();
include_once('../../config/app.php');
include_once('../../includes/auth.php');

require_role(['transportador'], '../login.php');

header('Location: ' . BASE_URL . '/pages/transportador/dashboard.php');
exit;
```

#### `pages/admin/documentos.php`
**Status:** INCOMPLETO (5 linhas)
**Problema:** Apenas redireciona para verificar-documentos.php
**Impacto:** Menu aponta para página que não existe funcionalmente
**Prioridade:** MÉDIA

### 2.2 Páginas com Funcionalidade Parcial

#### `pages/caminhoneiro/modo-direcao.php`
**Status:** FUNCIONAL MAS COM PROBLEMAS
**Problema:** Botão "Entrar no modo condução" pode desaparecer em certas condições
**Impacto:** Motoristas não conseguem iniciar modo condução
**Prioridade:** CRÍTICA

#### `pages/chat.php`
**Status:** FUNCIONAL COM TRATAMENTO DE ERROS
**Problema:** Implementa verificação dinâmica de colunas (tableHasColumn) indicando inconsistências de schema
**Impacto:** Pode falhar se migrations não foram executadas
**Prioridade:** ALTA

---

## 3. BOTÕES SEM ACÇÃO

### 3.1 Identificados
- **Não identificados explicitamente** na auditoria inicial
- Recomenda-se teste manual de todos os formulários para identificar handlers de eventos não implementados

### 3.2 Suspeitos
- Botões de "Gerar novo OTP" em entrega-confirmar.php - necessitam verificação de JavaScript
- Botões de emergência em modo-direcao.php - necessitam verificação de handlers

---

## 4. MENUS ÓRFÃOS

### 4.1 Itens de Menu sem Página Correspondente
- **Nenhum identificado** - o menu está bem estruturado

### 4.2 Páginas sem Acesso via Menu
- `pages/shared/parceria-detalhes.php` - acessível apenas via link de parcerias
- `pages/entrega/confirmar.php` - acessível apenas via link de missão
- `pages/entrega/avaliar.php` - acessível apenas via link de missão

**Recomendação:** Considerar adicionar acesso via menu ou breadcrumbs

---

## 5. FUNCIONALIDADES DUPLICADAS

### 5.1 Acesso a Perfil
- Menu dropdown "Meu Perfil" → `pages/perfil.php`
- Perfil específico por tipo em:
  - `pages/caminhoneiro/perfil.php`
  - `pages/contratante/perfil.php`
  - `pages/transportador/perfil.php` (incompleto)

**Status:** Aceitável - roteamento por tipo de usuário

### 5.2 Listagem de Missões
- `pages/caminhoneiro/missoes.php`
- `pages/contratante/missoes.php`
- `pages/transportador/missoes.php`
- `pages/admin/missoes.php`

**Status:** Aceitável - visões diferentes por tipo de usuário

### 5.3 Dashboard
- `index.php` (landing page com dashboard por tipo)
- `pages/admin/dashboard.php`
- `pages/admin/dashboard-executivo.php`
- `pages/contratante/dashboard.php`
- `pages/caminhoneiro/dashboard.php`
- `pages/transportador/dashboard.php`

**Status:** Aceitável - dashboards específicos por tipo

---

## 6. BUGS E INCONSISTÊNCIAS

### 6.1 Tabelas Referenciadas que Podem Não Existir

#### `veiculos`
**Referência:** `pages/caminhoneiro/entrega-confirmar.php` linha 24
```php
LEFT JOIN veiculos v ON m.veiculo_id = v.id
```
**Status:** Tabela não existe no schema principal
**Impacto:** Erro SQL ao confirmar entrega
**Prioridade:** CRÍTICA

#### `destinatarios`
**Referência:** `pages/caminhoneiro/entrega-confirmar.php` linha 25
```php
LEFT JOIN destinatarios d ON m.destinatario_id = d.id
```
**Status:** Tabela não existe no schema principal
**Impacto:** Erro SQL ao confirmar entrega
**Prioridade:** CRÍTICA

#### `otp_codes`
**Referência:** `api/entrega-confirmar.php` linha 62
```php
SELECT * FROM otp_codes WHERE missao_id = ? AND codigo = ? AND usado = 0 AND expira_em > NOW()
```
**Status:** Tabela não existe no schema principal
**Impacto:** Funcionalidade OTP não funciona
**Prioridade:** CRÍTICA

#### `entregas_confirmacao`
**Referência:** `api/entrega-confirmar.php` linha 89
```php
INSERT INTO entregas_confirmacao
```
**Status:** Tabela não existe no schema principal
**Impacto:** Confirmação de entrega não pode ser gravada
**Prioridade:** CRÍTICA

### 6.2 Campos que Podem Não Existir

#### `missoes.modo_conducao_ativo`
**Referência:** `pages/caminhoneiro/modo-direcao.php` linha 22
**Status:** Campo não existe no schema principal
**Impacto:** Modo condução não funciona corretamente
**Prioridade:** CRÍTICA

#### `missoes.data_inicio_conducao`, `data_pausa_conducao`, `data_retomada_conducao`, `tempo_conducao_acumulado_seg`
**Referência:** `pages/caminhoneiro/modo-direcao.php` linha 23
**Status:** Campos não existem no schema principal
**Impacto:** Rastreamento de tempo de condução não funciona
**Prioridade:** ALTA

#### `missoes.modo_confirmacao_entrega`
**Referência:** `pages/caminhoneiro/entrega-confirmar.php` linha 40
**Status:** Campo não existe no schema principal
**Impacto:** Seleção de método de confirmação não funciona
**Prioridade:** ALTA

#### `missoes.veiculo_id`, `destinatario_id`
**Referência:** `pages/caminhoneiro/entrega-confirmar.php` linha 24-25
**Status:** Campos não existem no schema principal
**Impacto:** Relacionamentos com veículos e destinatários não funcionam
**Prioridade:** CRÍTICA

### 6.3 Inconsistências de Schema

#### Engine InnoDB vs MyISAM
**Problema:** Schema principal usa MyISAM, mas migrations criam tabelas InnoDB
**Impacto:** Inconsistência de engine, possíveis problemas de transações
**Prioridade:** MÉDIA

#### Verificação Dinâmica de Colunas
**Problema:** `pages/chat.php` usa `tableHasColumn()` para verificar colunas em runtime
**Impacto:** Indica que migrations não foram aplicadas uniformemente
**Prioridade:** ALTA

### 6.4 Validações Inconsistentes

#### Validação de CNH
**Status:** Não há validação de expiração de CNH
**Impacto:** Motoristas com CNH expirada podem operar
**Prioridade:** ALTA (regra de negócio)

#### Validação de Seguro/Inspecção de Veículos
**Status:** Não há validação de expiração
**Impacto:** Veículos com seguro/inspecção expirados podem operar
**Prioridade:** ALTA (regra de negócio)

#### Limite de Missões Ativas por Motorista
**Status:** Não há validação
**Impacto:** Motorista pode ter múltiplas missões activas simultaneamente
**Prioridade:** ALTA (regra de negócio)

---

## 7. PROBLEMAS DE PERMISSÕES E SEGURANÇA

### 7.1 CSRF
**Status:** IMPLEMENTADO
**Local:** `includes/helpers.php` - `csrf_token()`, `require_csrf()`, `require_csrf_json()`
**Uso:** Utilizado em APIs (`api/chat-send.php`, `api/entrega-confirmar.php`)
**Avaliação:** BOM - mas precisa verificação se todos os POST endpoints usam

### 7.2 SQL Injection
**Status:** RISCO MÉDIO
**Problema:** Algumas queries usam interpolação de strings em vez de prepared statements
**Exemplo:** `pages/chat.php` linha 104
```php
$orderBy = $hasConvUltAtual  ? 'c.ultima_atualizacao DESC' : 'c.id DESC';
// Usado diretamente na query
```
**Impacto:** Possível SQL injection se input não for validado
**Prioridade:** ALTA

### 7.3 Upload de Arquivos
**Status:** IMPLEMENTADO COM VALIDAÇÃO BÁSICA
**Local:** `api/chat-send.php`, `api/documento-upload.php`
**Validações:**
- Tamanho máximo (10MB)
- Tipos MIME permitidos
- Extensões permitidas
- Renomeamento seguro (random bytes)
**Avaliação:** BOM - mas pode melhorar com validação de conteúdo real

### 7.4 XSS
**Status:** PARCIALMENTE IMPLEMENTADO
**Local:** `includes/helpers.php` - `e()` function
**Uso:** Utilizado em muitas páginas para escape
**Problema:** Nem todos os outputs usam `e()`
**Prioridade:** MÉDIA

### 7.5 Sessões
**Status:** IMPLEMENTADO
**Local:** `includes/auth.php`
**Funcionalidades:**
- `require_login()`
- `require_role()`
- `is_logged_in()`
- `is_role()`
**Avaliação:** BOM

### 7.6 Autenticação de Senhas
**Status:** IMPLEMENTADO
**Local:** `pages/login.php`
**Método:** `password_verify()` com bcrypt
**Avaliação:** BOM

### 7.7 Proteção de Directórios Sensíveis
**Status:** IMPLEMENTADO via .htaccess
**Local:** `.htaccess`
**Regras:**
- Bloqueio de listagem de diretórios
- Proteção de arquivos sensíveis (.htaccess, .env, .sql)
- Bloqueio de acesso a config/, database/, scripts/, storage/
**Avaliação:** BOM

---

## 8. OPORTUNIDADES DE MELHORIA

### 8.1 Regras de Negócio Não Implementadas

#### Motoristas
- [ ] Apenas uma missão activa por vez
- [ ] Não pode iniciar nova missão sem concluir a anterior
- [ ] Carta (CNH) expirada bloqueia actividade
- [ ] Motorista suspenso não recebe missões

#### Viaturas
- [ ] Seguro expirado bloqueia operação
- [ ] Inspecção expirada bloqueia operação
- [ ] Viatura indisponível não pode ser atribuída

#### Missões
- [ ] Missão só pode ser executada por parceiros activos
- [ ] Contrato expirado bloqueia novas missões
- [ ] Missão não pode ser concluída sem prova de entrega

#### Entregas
- [ ] OTP obrigatório quando configurado
- [ ] OTP único
- [ ] OTP de utilização única
- [ ] GPS obrigatório
- [ ] Histórico completo

### 8.2 Fluxo Operacional
- [ ] Validar fluxo completo: Criação → Parceria → Aceitação → Atribuição → Recolha → Condução → Emergência → Chegada → OTP → POD → Avaliação → Factura → Pagamento → Recibo → Conclusão

### 8.3 Modo Condução
- [ ] Corrigir desaparecimento do botão "Entrar no modo condução"
- [ ] Implementar retomar condução
- [ ] Implementar pausar condução
- [ ] Melhorar GPS e rotas
- [ ] Melhorar cálculo de distâncias

### 8.4 Gestão de Frota
- [ ] Completar gestão de documentos de veículos
- [ ] Implementar manutenções
- [ ] Implementar abastecimentos
- [ ] Implementar gestão de pneus
- [ ] Criar alertas automáticos (seguro, inspecção, carta, licenças)

### 8.5 Contratos e Parcerias
- [ ] Melhorar negociação
- [ ] Implementar aprovação bilateral completa
- [ ] Implementar histórico de alterações
- [ ] Implementar renegociação
- [ ] Implementar versionamento
- [ ] Implementar validade
- [ ] Implementar suspensão
- [ ] Bloquear operações quando contrato não válido

### 8.6 Documentos Profissionais
- [ ] Padronizar todos os documentos (facturas, contratos, guias, comprovativos, POD, relatórios, recibos)
- [ ] Adicionar logo em todos os documentos
- [ ] Adicionar dados fiscais (NUIT, endereço, contactos)
- [ ] Adicionar rodapé profissional
- [ ] Adicionar cabeçalho profissional
- [ ] Implementar numeração automática
- [ ] Implementar referências
- [ ] Adicionar QR Code quando possível
- [ ] Implementar layout empresarial moderno
- [ ] Adicionar campos de assinatura

### 8.7 Explorador de Documentos
- [ ] Melhorar visualização
- [ ] Melhorar pesquisa
- [ ] Melhorar filtros
- [ ] Implementar impressão
- [ ] Implementar download
- [ ] Melhorar organização
- [ ] Relacionar documentos com missões, empresas, motoristas, contratos, facturas

### 8.8 Chat
- [ ] Corrigir erro CSRF completamente
- [ ] Melhorar mensagens
- [ ] Melhorar permissões
- [ ] Melhorar conversas
- [ ] Melhorar notificações
- [ ] Melhorar histórico
- [ ] Melhorar anexos
- [ ] Implementar pesquisa
- [ ] Melhorar UX

### 8.9 UX/UI
- [ ] Padronizar cores em todas as páginas
- [ ] Padronizar espaçamentos
- [ ] Padronizar tabelas
- [ ] Padronizar cards
- [ ] Padronizar menus
- [ ] Padronizar dashboards
- [ ] Padronizar formulários
- [ ] Padronizar modais
- [ ] Padronizar alertas
- [ ] Criar sistema visual único
- [ ] Melhorar experiência mobile

### 8.10 Dashboards
- [ ] Criar dashboards profissionais
- [ ] Mostrar missões activas
- [ ] Mostrar missões concluídas
- [ ] Mostrar missões atrasadas
- [ ] Mostrar emergências
- [ ] Mostrar receita
- [ ] Mostrar custos
- [ ] Mostrar frota
- [ ] Mostrar motoristas
- [ ] Mostrar entregas
- [ ] Mostrar pagamentos

### 8.11 Performance
- [ ] Optimizar consultas SQL
- [ ] Optimizar carregamento de páginas
- [ ] Optimizar mapas
- [ ] Optimizar dashboards
- [ ] Optimizar uploads

---

## 9. RESUMO DE PRIORIDADES

### CRÍTICAS (Bloqueiam funcionamento principal)
1. Criar tabela `veiculos`
2. Criar tabela `destinatarios`
3. Criar tabela `otp_codes`
4. Criar tabela `entregas_confirmacao`
5. Adicionar campos de modo condução em `missoes`
6. Adicionar campos de confirmação de entrega em `missoes`
7. Implementar `pages/transportador/perfil.php`
8. Corrigir botão "Entrar no modo condução"

### ALTAS (Impactam regras de negócio)
1. Implementar validação de CNH expirada
2. Implementar validação de seguro/inspecção expirados
3. Implementar limite de missões activas por motorista
4. Aplicar migrations uniformemente
5. Corrigir SQL injection em queries dinâmicas
6. Verificar uso de CSRF em todos os endpoints POST

### MÉDIAS (Melhorias de UX/UI)
1. Padronizar documentos profissionais
2. Melhorar chat
3. Padronizar UX/UI
4. Criar dashboards profissionais
5. Completar gestão de frota

### BAIXAS (Optimizações)
1. Optimizar performance
2. Melhorar exploração de documentos

---

## 10. PRÓXIMOS PASSOS RECOMENDADOS

1. **FASE 2:** Implementar regras de negócio (motoristas, viaturas, missões, entregas)
2. **FASE 3:** Validar fluxo operacional completo
3. **FASE 4:** Rever modo condução
4. **FASE 5:** Completar gestão de frota
5. **FASE 6:** Melhorar contratos e parcerias
6. **FASE 7:** Padronizar documentos profissionais
7. **FASE 8:** Melhorar explorador de documentos
8. **FASE 9:** Corrigir e melhorar chat
9. **FASE 10:** Padronizar UX/UI
10. **FASE 11:** Criar dashboards profissionais
11. **FASE 12:** Validar e corrigir segurança
12. **FASE 13:** Optimizar performance

---

**Assinatura:** Auditoria de Software Sénior
**Aprovação:** Pendente
