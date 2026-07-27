# Parcerias TrackMoz — Regras de Negócio, Validações e Ferramentas

**Projecto:** TrackMoz — Sistema de Gestão de Fretes  
**Módulo:** Contratos / Parcerias profissionais  
**Data:** 21 Julho 2026  
**Fonte:** código em `pages/contratante/`, `pages/transportador/`, `pages/shared/parceria-detalhes.php`, `api/parceria-*.php`, `includes/parceria-helpers.php`, `includes/regras-negocio.php`, `includes/chat-helpers.php`

---

## 1. Visão geral do módulo

A parceria é um **contrato comercial de longo prazo** entre:

| Papel | Tipo de utilizador | Responsabilidade |
|--------|--------------------|------------------|
| Contratante | `empresa` | Propõe termos, aprova, publica missões via parceria |
| Transportadora | `transportador` | Recebe proposta, aceita / contra-propõe / recusa, aprova termos |
| Administração | `admin` | Valida (opcional) quando `requer_validacao_admin = 1` |

Objectivo: permitir missões **directas** (fora do feed público), com preços, SLA, rotas e tipos de carga definidos no contrato.

---

## 2. Máquina de estados (status)

### 2.1 Estados activos no fluxo profissional

| Status | Significado |
|--------|-------------|
| `rascunho` | Rascunho interno (não usado no formulário actual de envio) |
| `pedido_enviado` | Proposta criada pela empresa; aguarda resposta da transportadora |
| `em_negociacao` | Houve contra-proposta; ambos devem reaprovar |
| `aguardando_aprovacao_empresa` | Transportadora aceitou / aprovou; falta a empresa |
| `aguardando_aprovacao_transportador` | Empresa aprovou; falta a transportadora |
| `aguardando_validacao_admin` | Ambas as partes aprovaram e o contrato exige admin |
| `ativa` | Contrato operativo — pode receber missões |
| `suspensa` | Temporariamente inactiva |
| `expirada` | Data de fim ultrapassada (lógica de validação em missões) |
| `cancelada` | Recusada / cancelada por uma das partes ou pelo admin |
| `pendente` / `terminada` / `rejeitada` | Estados legados mantidos no ENUM por compatibilidade |

### 2.2 Transições principais

```
empresa cria proposta
        │
        ▼
  pedido_enviado
        │
        ├── transportador ACEITAR ──► aguardando_aprovacao_empresa
        │                                      │
        │                                      └── empresa APROVAR
        │                                              │
        ├── qualquer CONTRA-PROPOR ──► em_negociacao   │
        │         (reset aprovações)                   │
        │                                              ▼
        └── qualquer RECUSAR ──► cancelada      [ambos aprovaram?]
                                                       │
                              ┌────────────────────────┤
                              │                        │
                    requer_validacao_admin=1    requer_validacao_admin=0
                              │                        │
                              ▼                        ▼
                 aguardando_validacao_admin          ativa
                              │
                    admin VALIDAR ──► ativa
                    admin REJEITAR ──► cancelada
```

### 2.3 Flags de aprovação

| Campo | Regra |
|-------|--------|
| `aprovado_por_empresa` | Na criação já fica `1` (empresa propôs). Contra-proposta põe a `0`. |
| `aprovado_por_transportador` | Fica `1` no aceite inicial ou na acção `aprovar`. |
| `validado_por_admin` | Só quando admin valida. |
| `versao_contrato` | Incrementa +1 em cada contra-proposta. |
| `requer_validacao_admin` | Se `1`, activação só após admin. |

---

## 3. Regras de negócio

### 3.1 Criação da proposta (empresa)

1. Só utilizadores com papel `empresa` podem criar.
2. Só transportadoras com `tipo_usuario = transportador` e `status = ativo` são elegíveis.
3. **Unicidade activa:** não pode existir outra parceria entre o mesmo par `(empresa_id, transportador_id)` com status em:
   - `rascunho`, `pedido_enviado`, `em_negociacao`,
   - `aguardando_aprovacao_empresa`, `aguardando_aprovacao_transportador`,
   - `aguardando_validacao_admin`, `ativa` (e legado `pendente` na listagem).
4. Status inicial: `pedido_enviado`, `proposto_por = 'empresa'`.
5. É criado um registo em `parceria_negociacoes` (versão 1, campo `criacao`) com snapshot dos termos.
6. É enviada notificação à transportadora (`tipo = parceria`).

### 3.2 Resposta da transportadora / empresa

| Acção | Quem | Pré-condições | Efeito |
|-------|------|---------------|--------|
| `aceitar` | transportador | `status = pedido_enviado` | `aprovado_por_transportador = 1`, status → `aguardando_aprovacao_empresa` |
| `aprovar` | empresa ou transportador | Em negociação / aguardando aprovação | Marca a flag do respectivo lado; se ambos `1`, chama activação |
| `contra_propor` | qualquer das partes | Em estados de negociação/pedido | Actualiza campos alterados, `versao_contrato++`, status → `em_negociacao`, **zera** ambas as aprovações |
| `recusar` | qualquer das partes | Com permissão no contrato | status → `cancelada` (+ motivo opcional) |

**Permissão:** a empresa só actua se `empresa_id = user_id`; a transportadora só se `transportador_id = user_id`.

### 3.3 Activação

Quando **ambos** aprovaram:

- Se `requer_validacao_admin = 1` → `aguardando_validacao_admin` + notifica todos os admins.
- Caso contrário → `ativa` + notifica empresa e transportadora.

### 3.4 Validação administrativa

- Só `admin`.
- Só se `status = aguardando_validacao_admin`.
- `validar` → `ativa`.
- `rejeitar` → `cancelada` (+ motivo opcional).

### 3.5 Terminar parceria (empresa)

- Empresa pode terminar se status ∈ `{ativa, pedido_enviado, em_negociacao, pendente}` (fluxo em `parcerias.php` do contratante).
- Notifica a transportadora.

### 3.6 Missões via parceria

| Regra | Detalhe |
|-------|---------|
| Parceria utilizável | Apenas `status = 'ativa'` |
| Expiração | Se `data_fim < hoje` → bloquear execução; se ≤ 30 dias → aviso |
| Tipo de carga | Se `tipos_carga_permitidos` preenchido, o tipo da missão tem de estar na lista (CSV) |
| Rotas | Se `rotas_cobertas` preenchido, origem→destino deve coincidir com alguma rota listada |
| Destino da missão | Missão fica ligada a `transportador_id` da parceria (não vai ao feed público) |
| Exclusiva (`nova-missao.php`) | Se existe parceria exclusiva activa, opção de enviar directamente (status missão `aceita`) |
| Facturação | Valores do contrato (`valor_missao`, `valor_km`, comissão) alimentam a factura |

### 3.7 Chat

Dois utilizadores podem conversar em chat geral se existir parceria com status em:

`ativa`, `em_negociacao`, `aguardando_aprovacao_*`, `aguardando_validacao_admin`, `pendente`.

### 3.8 Auditoria e histórico

- Toda alteração relevante gera linha em `parceria_negociacoes`.
- Acções críticas registam `registrar_log(...)`.
- Notificações in-app para a outra parte (e admin quando aplicável).

---

## 4. Validação de campos

### 4.1 Campos do contrato

| Campo | Obrigatório | Tipo / domínio | Validação |
|-------|-------------|----------------|-----------|
| `transportador_id` | Sim | INT > 0 | Transportador activo; sem parceria activa/em curso com a empresa |
| `data_inicio` | Sim | DATE | Não vazio; no UI `min = hoje` |
| `data_fim` | Não | DATE / NULL | Se preenchida, deve ser **>** `data_inicio` |
| `tipo_contrato` | Sim | ENUM | `por_missao`, `por_km`, `mensalidade`, `misto`, `tabela` |
| `valor_missao` | Não | DECIMAL | Se enviado, numérico; vazio → NULL |
| `valor_km` | Não | DECIMAL | Idem |
| `valor_mensal` | Não | DECIMAL | Idem |
| `comissao_plataforma_pct` | Não | DECIMAL | Default `0` |
| `condicoes_pagamento` | Não | VARCHAR / ENUM UI | `30_dias`, `15_dias`, `7_dias`, `a_entrega`, `antecipado` |
| `sla_resposta_horas` | Não | INT | Default `24` |
| `penalidade_atraso_pct` | Não | DECIMAL | Default `0` |
| `responsabilidade_carga` | Não | ENUM | `seguro`, `contratante`, `transportador` |
| `tipos_carga_permitidos` | Não | TEXT (CSV) | Validado na publicação de missão |
| `rotas_cobertas` | Não | TEXT (CSV) | Validado na publicação de missão |
| `descricao` | Não | TEXT | Texto livre |
| `observacoes_negociacao` | Não | TEXT | Texto livre |
| `exclusiva` | Não | TINYINT 0/1 | Checkbox |
| `requer_validacao_admin` | Não | TINYINT 0/1 | Checkbox |

### 4.2 Validação na contra-proposta

Campos alteráveis:

`valor_missao`, `valor_km`, `valor_mensal`, `comissao_plataforma_pct`, `condicoes_pagamento`, `sla_resposta_horas`, `penalidade_atraso_pct`, `responsabilidade_carga`, `tipos_carga_permitidos`, `rotas_cobertas`, `data_fim`, `tipo_contrato`, `observacoes_negociacao`.

Regras:

- Só actualiza se o valor **mudou** face ao actual.
- Se nenhum campo mudou → erro: «Nenhuma alteração proposta».
- Comentário opcional.

### 4.3 Validação de segurança (todas as APIs)

| Controlo | Onde |
|----------|------|
| Sessão autenticada | `$_SESSION['user_id']` |
| Papel correcto | `empresa` / `transportador` / `admin` conforme endpoint |
| CSRF | `require_csrf()` / `require_csrf_json()` |
| Ownership | Comparação `empresa_id` / `transportador_id` com o utilizador |
| SQL injection | PDO prepared statements (`ATTR_EMULATE_PREPARES = false`) |
| XSS em saída | `htmlspecialchars` / helper `e()` |

### 4.4 Validação de UI (cliente)

- Selecção de transportadora obrigatória antes do submit (`transportador_id` hidden).
- Inputs `type="number"` com `step` adequado (ex.: KM `0.0001`).
- Datas com `type="date"`.
- Botão de envio desactivado se não houver transportadoras disponíveis.

---

## 5. Artefactos de código (referência)

| Ficheiro | Função |
|----------|--------|
| `pages/contratante/nova-parceria.php` | Formulário + criação server-side |
| `pages/contratante/parcerias.php` | Lista e terminar |
| `pages/transportador/parcerias.php` | Lista propostas / activas |
| `pages/shared/parceria-detalhes.php` | Detalhe, acções, histórico |
| `api/parceria-criar.php` | API JSON criar |
| `api/parceria-responder.php` | API aceitar / aprovar / contra-propor / recusar |
| `api/parceria-validar-admin.php` | API validação admin |
| `includes/parceria-helpers.php` | Labels, formatação de histórico, snapshot |
| `includes/regras-negocio.php` | Validação de missão vs parceria activa/expirada |
| `database/migrate_parceria_profissional.php` | Schema ENUM + tabelas relacionadas |
| `includes/chat-helpers.php` | Acesso ao chat por parceria |
| `api/factura-gerar.php` | Valores comerciais da parceria na factura |

---

## 6. Ferramentas e tecnologias — análise minuciosa

Esta secção documenta **cada ferramenta usada no módulo (e no stack que o sustenta)**, com:

1. o que faz no TrackMoz;
2. porquê foi escolhida;
3. alternativas possíveis;
4. porquê a escolha actual é a mais adequada ao contexto (WAMP, PHP monolítico, equipa pequena, domínio de fretes em Moçambique).

---

### 6.1 PHP (linguagem do backend)

| | |
|--|--|
| **Uso** | Toda a lógica de negócio, páginas, APIs REST-like, helpers, sessões. |
| **Porquê** | O projecto corre em WAMP; PHP é nativo nesse ambiente, com baixo custo de hosting e deploy simples (ficheiros + MySQL). |
| **Alternativas** | Node.js (Express/Nest), Python (Django/FastAPI), Java/Spring, Go. |
| **Porquê PHP vence aqui** | Zero overhead de runtime separado no Apache; integração directa com MySQL via PDO; a base do TrackMoz já está em PHP — reescrever o módulo noutro stack quebraria o monolito sem ganho proporcional. Para negociação de contratos CRUD + estado, PHP 8+ com `match`, typed helpers e PDO é suficiente e mais barato de operar. |

---

### 6.2 MySQL / MariaDB (SGBD)

| | |
|--|--|
| **Uso** | Tabelas `parcerias`, `parceria_negociacoes`, `notificacoes`, `missoes`, `facturas`; ENUM de status; joins com `perfil_empresa` / `perfil_transportador`. |
| **Porquê** | Padrão WAMP; ACID, ENUM para máquina de estados, FKs e índices simples para o volume típico B2B. |
| **Alternativas** | PostgreSQL (JSON/CHECK mais ricos), MongoDB (documentos flexíveis para versões de contrato), SQLite (dev local). |
| **Porquê MySQL vence** | Já é a BD do produto; ENUM cobre bem o workflow; migrar só o módulo de parcerias para outro SGBD criaria complexidade operacional sem benefício claro. PostgreSQL seria a melhor alternativa *se* o projecto inteiro migrasse (constraints e JSONB para snapshots). MongoDB seria pior para integridade relacional empresa↔transportador↔missão↔factura. |

---

### 6.3 PDO (acesso a dados)

| | |
|--|--|
| **Uso** | Todas as queries de parceria (`prepare` / `execute` / binds nomeados). |
| **Porquê** | API nativa PHP, prepared statements, excepções (`ERRMODE_EXCEPTION`), `FETCH_ASSOC`, `EMULATE_PREPARES = false` (binds reais no servidor). |
| **Alternativas** | mysqli, Doctrine ORM / Eloquent, Query Builder (Laravel). |
| **Porquê PDO vence** | Portável, seguro contra SQL injection sem framework; sem curva de ORM. mysqli é MySQL-only e menos idiomático. ORM traria migrações e entidades — útil em greenfield Laravel, mas excessivo para um módulo dentro de um PHP clássico. |

---

### 6.4 Sessões PHP + `require_role` / auth

| | |
|--|--|
| **Uso** | Autenticar e autorizar empresa / transportador / admin em páginas e APIs. |
| **Porquê** | Modelo de sessão server-side já usado em todo o TrackMoz; simples e compatível com formulários clássicos. |
| **Alternativas** | JWT (stateless), OAuth2/OIDC, Laravel Sanctum. |
| **Porquê sessões vencem** | APIs e páginas no mesmo domínio; CSRF + cookie de sessão é o padrão correcto para apps server-rendered. JWT seria melhor para SPA/mobile desacoplados — o módulo de parcerias ainda é HTML + fetch pontual. |

---

### 6.5 Protecção CSRF (`require_csrf` / `require_csrf_json` / `csrf_field`)

| | |
|--|--|
| **Uso** | Impedir submissões cross-site em criar / responder / validar / terminar. |
| **Porquê** | Acções de contrato são sensíveis (activação comercial, cancelamento). |
| **Alternativas** | SameSite cookies estritos apenas; double-submit cookie; frameworks com CSRF built-in. |
| **Porquê token CSRF vence** | Defesa em profundidade além de SameSite; já padronizado no projecto; funciona com POST clássico e JSON. |

---

### 6.6 HTML + Bootstrap 5.3 + Bootstrap Icons

| | |
|--|--|
| **Uso** | UI de `nova-parceria`, listagens, detalhes, modais de contra-proposta/recusa. |
| **Porquê** | Consistência visual com o resto do TrackMoz; grelhas, cards, badges de status, modais sem CSS custom pesado. |
| **Alternativas** | Tailwind + componentes próprios, Material UI, AdminLTE, Quasar. |
| **Porquê Bootstrap vence** | Já está no design system do site; CDN rápido; curva baixa. Tailwind daria mais controlo visual mas exigiria build pipeline que o projecto PHP clássico não tem. |

---

### 6.7 JavaScript (vanilla) + `fetch`

| | |
|--|--|
| **Uso** | Selecção de transportadora no formulário; chamadas a `parceria-responder` / `parceria-validar-admin` a partir de `parceria-detalhes.php`. |
| **Porquê** | Interacções pontuais sem necessidade de SPA. |
| **Alternativas** | jQuery, Alpine.js, Vue/React, HTMX. |
| **Porquê vanilla vence** | Zero dependências extra; o módulo não precisa de estado UI complexo. HTMX seria a melhor alternativa “moderna leve” se se quisesse menos JS manual. React/Vue seriam overkill para 2–3 formulários. |

---

### 6.8 APIs PHP JSON (`api/parceria-*.php`)

| | |
|--|--|
| **Uso** | Endpoints dedicados para criar, responder e validar, com `Content-Type: application/json`. |
| **Porquê** | Separar mutações AJAX da renderização HTML; reutilizáveis por UI de detalhes. |
| **Alternativas** | Controllers MVC (Laravel), GraphQL, gRPC, tudo em POST da mesma página. |
| **Porquê estes endpoints vencem** | Encaixam no padrão `api/` existente; JSON simples para o front; GraphQL/gRPC adicionariam complexidade sem clientes múltiplos a justificar. |

---

### 6.9 Tabela `parceria_negociacoes` (histórico versionado)

| | |
|--|--|
| **Uso** | Audit trail: criação, aceite, aprovações, cada campo alterado na contra-proposta, validação admin. |
| **Porquê** | Contratos comerciais exigem rastreabilidade («quem mudou o valor por km e quando»). |
| **Alternativas** | Event sourcing completo; JSON único de versões na linha `parcerias`; logs só em ficheiro. |
| **Porquê tabela de negociações vence** | Consultável em SQL, renderizável no UI (`parceria_negociacao_html`), incremental por campo. Event sourcing seria mais poderoso mas mais caro de implementar. Um único JSON de versão dificulta queries («mostrar todas as mudanças de `valor_missao`»). |

---

### 6.10 ENUM MySQL para status e tipos

| | |
|--|--|
| **Uso** | Status da parceria, `tipo_contrato`, `responsabilidade_carga`, `proposto_por`. |
| **Porquê** | Restringe valores inválidos ao nível da BD; documentação implícita do domínio. |
| **Alternativas** | VARCHAR + CHECK (PostgreSQL), tabela de lookup, state machine em código apenas. |
| **Porquê ENUM vence neste stack** | Barato e legível em MySQL. Custo: alterar ENUM exige `ALTER TABLE` (já feito na migração). Lookup table seria melhor se os estados mudassem com muita frequência ou precisassem de metadados (labels, ordem) na BD. |

---

### 6.11 Notificações in-app (`notificacoes`)

| | |
|--|--|
| **Uso** | Alertar transportadora de nova proposta; alertar da outra parte em aceite/contra-proposta/activação; alertar admin. |
| **Porquê** | Feedback imediato no produto sem depender de email externo. |
| **Alternativas** | Email (SMTP), SMS, push (FCM), filas (Redis + worker). |
| **Porquê in-app vence como primário** | Já existe infraestrutura de notificações; latência zero de configuração. Email/SMS são excelentes *complementos* (não implementados neste módulo) para propostas críticas — especialmente se o utilizador estiver offline. |

---

### 6.12 Socket.IO (Node) — realtime opcional

| | |
|--|--|
| **Uso no projecto** | Servidor em `realtime/` para eventos TMS em tempo real (não é o núcleo síncrono da criação de parceria). |
| **Porquê no ecossistema** | Chat/tracking beneficiam de WebSockets; Socket.IO cobre fallbacks e rooms. |
| **Alternativas** | WebSockets nativos, Ably/Pusher, SSE, polling. |
| **Porquê Socket.IO (quando usado)** | Maduro, rooms fáceis, clientes JS simples. **Para parcerias**, a notificação persistida na BD + badge no menu é mais adequada que push realtime obrigatório: o contrato não precisa de milissegundos — precisa de fiabilidade e histórico. |

---

### 6.13 Apache / WAMP

| | |
|--|--|
| **Uso** | Servir PHP localmente (dev) e tipicamente em hosting partilhado. |
| **Porquê** | Ambiente do projecto; `.php` directo. |
| **Alternativas** | Nginx + PHP-FPM, Docker (php-fpm + mysql), Laravel Sail. |
| **Porquê WAMP/Apache vence no contexto actual** | Alinha com a máquina de desenvolvimento e hosts PHP tradicionais em MZ/PT. Docker seria melhor para paridade prod/dev em equipas maiores. |

---

### 6.14 Migrações SQL manuais (`database/migrate_parceria_profissional.php`)

| | |
|--|--|
| **Uso** | Expandir ENUM, adicionar colunas comerciais, criar `parceria_negociacoes`, facturas, etc. |
| **Porquê** | Evolução controlada do schema sem framework de migrações. |
| **Alternativas** | Phinx, Doctrine Migrations, Laravel Migrations, Flyway. |
| **Porquê scripts PHP manuais vencem aqui** | Um ficheiro idempotente (`SHOW COLUMNS` antes de `ADD`) encaixa no deploy actual. Ferramentas de migração seriam preferíveis se houvesse CI/CD e várias ambientes — hoje o custo de adoptar Phinx supera o benefício imediato. |

---

### 6.15 Helpers de domínio (`parceria-helpers.php`)

| | |
|--|--|
| **Uso** | Labels PT, formatação de valores (MT, %, horas), snapshot limpo (sem binds PDO), HTML do histórico. |
| **Porquê** | Evitar duplicar mapas de tradução e lógica de apresentação nas páginas. |
| **Alternativas** | Classes DTO/ViewModel, Twig filters, i18n gettext. |
| **Porquê helpers funcionais vencem** | Leves, alinhados ao estilo do resto do código (`includes/*.php`). Classes seriam melhores se o módulo crescesse para PDF de contrato, assinatura digital, etc. |

---

### 6.16 `error_log` + `registrar_log`

| | |
|--|--|
| **Uso** | Erros técnicos no log do PHP; acções de negócio na tabela/auditoria da aplicação. |
| **Porquê** | Separar falha técnica de trilha de compliance. |
| **Alternativas** | Monolog, Sentry, ELK. |
| **Porquê o par actual vence** | Zero dependências; suficiente para o volume. Sentry seria o próximo passo se a taxa de incidentes em produção o justificar. |

---

### 6.17 CDN (jsDelivr) para Bootstrap / Icons

| | |
|--|--|
| **Uso** | Carregar CSS/JS Bootstrap e ícones. |
| **Porquê** | Cache global, sem build step. |
| **Alternativas** | Assets locais / npm + Vite, Self-host. |
| **Porquê CDN vence agora** | Simplicidade. Self-host seria melhor para ambientes offline ou compliance que exija CSP estrita sem CDNs. |

---

## 7. Mapa resumo: escolha vs alternativa

| Necessidade | Escolhida | Melhor alternativa se o contexto mudasse |
|-------------|-----------|------------------------------------------|
| Backend | PHP + Apache | Laravel (se o monolito for reescrito) |
| BD | MySQL + ENUM + PDO | PostgreSQL + JSONB (snapshots e constraints) |
| Auth | Sessão + roles | OAuth2 se houver apps móveis nativas |
| Mutações AJAX | APIs PHP JSON | HTMX (menos JS) |
| UI | Bootstrap 5 | Design system próprio / Tailwind com build |
| Histórico contrato | Tabela `parceria_negociacoes` | Event store se auditoria regulatória avançada |
| Alertas | Notificações BD | + Email/SMS para propostas críticas |
| Realtime | Socket.IO (opcional, outros módulos) | SSE para badges leves |
| Schema | Script de migração PHP | Phinx/Flyway com CI |

---

## 8. Conclusão

O módulo de parcerias implementa um **workflow comercial bilateral (com validação admin opcional)** com:

- regras claras de unicidade, ownership e activação;
- validação de campos comerciais e de missão (carga/rota/expiração);
- histórico versionado e notificações;
- stack PHP/MySQL/Bootstrap alinhado ao resto do TrackMoz.

As ferramentas foram escolhidas por **encaixe no monolito existente, custo operacional baixo e adequação ao domínio** (contratos B2B com auditoria), não por moda tecnológica. Alternativas mais “modernas” (ORM, SPA, event sourcing) só se justificam se o produto evoluir para multi-cliente API-first, mobile nativo ou compliance documental avançada (assinatura digital, PDF legal, renovação automática).
