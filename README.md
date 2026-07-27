# TrackMoz - Sistema de Gestão de Fretes

Sistema de gestão de fretes rodoviários em Moçambique, conectando empresas contratantes, caminhoneiros e transportadores.

## Requisitos

- PHP 7.4 ou superior
- MySQL 5.7 ou superior
- Apache com mod_rewrite habilitado
- Extensões PHP: PDO, PDO_MySQL, json, mbstring

## Instalação

### 1. Configurar a base de dados

Importe o arquivo SQL inicial:

```bash
mysql -u root -p < database/crbhlspv_trackmoz.sql
```

Ou use o phpMyAdmin para importar o arquivo `database/crbhlspv_trackmoz.sql`.

### 2. Configurar conexão com a base de dados

O sistema usa variáveis de ambiente para configuração. Configure as seguintes variáveis no seu servidor ou no arquivo `config/database.php`:

- `DB_HOST` - Host do MySQL (padrão: localhost)
- `DB_USER` - Usuário do MySQL (padrão: root)
- `DB_PASS` - Senha do MySQL (padrão: vazio)
- `DB_NAME` - Nome da base de dados (padrão: crbhlspv_trackmoz)

Alternativamente, edite diretamente o arquivo `config/database.php` e altere as constantes no topo do arquivo.

### 3. Executar migrations

Execute os scripts de migração para atualizar a estrutura da base de dados:

```bash
php database/migrate_correcoes_base.php
php database/migrate_fluxo_operacional.php
php database/migrate_parceria_profissional.php
php database/migrate_emergencias.php
php database/migrate_chat_anexos.php
```

### 4. Popular dados iniciais (opcional)

Para criar dados de teste e usuários iniciais:

```bash
php database/run_seed.php
```

### 5. Configurar permissões de escrita

Certifique-se de que os seguintes diretórios têm permissão de escrita:

- `uploads/`
- `storage/`

No Linux/Mac:

```bash
chmod -R 755 uploads storage
```

No Windows (WAMP), as permissões geralmente já estão configuradas corretamente.

### 6. Acessar o sistema

Acesse no navegador:

```
http://localhost/trackmoz/
```

## Estrutura do Projeto

```
trackmoz/
├── api/                    # Endpoints da API REST
├── assets/                 # Arquivos estáticos (CSS, JS, imagens)
├── config/                 # Configurações (app.php, database.php)
├── database/               # Migrations e scripts de banco de dados
├── includes/               # Funções auxiliares e componentes compartilhados
├── pages/                  # Páginas do sistema (admin, caminhoneiro, contratante, etc.)
├── scripts/                # Scripts de manutenção
├── storage/                # Arquivos temporários
├── uploads/                # Arquivos enviados pelos usuários
├── index.php               # Página inicial / router
└── .htaccess               # Configurações do Apache
```

## Tipos de Usuário

- **admin** - Administrador do sistema
- **empresa** - Empresa contratante de fretes
- **caminhoneiro** - Motorista/caminhoneiro
- **transportador** - Transportador/gestor de frotas

## Scripts de Manutenção

### Corrigir perfis de caminhoneiros

Se você tiver problemas com perfis de caminhoneiros (campos não informados), execute:

```bash
php scripts/fix_profiles.php
```

Veja mais detalhes em `scripts/README.md`.

## Desenvolvimento

### Adicionar novas migrations

Crie um novo arquivo em `database/` seguindo o padrão `migrate_nome_da_feature.php`.

### Adicionar novas APIs

Crie um novo arquivo em `api/` seguindo o padrão dos arquivos existentes (autenticação via sessão, JSON response).

## Solução de Problemas

### Erro de conexão com a base de dados

Verifique as configurações em `config/database.php` e certifique-se de que o MySQL está rodando.

### Permissões negadas em uploads

Verifique se os diretórios `uploads/` e `storage/` têm permissão de escrita.

### Redirecionamentos incorretos

Verifique a configuração do `BASE_URL` em `config/app.php` e as regras no `.htaccess`.

## Suporte

Para problemas específicos, consulte os scripts em `scripts/README.md` ou verifique os logs de erro do PHP.
