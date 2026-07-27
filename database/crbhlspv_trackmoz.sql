-- phpMyAdmin SQL Dump
-- version 4.9.11
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Tempo de geração: 09-Mar-2026 às 07:05
-- Versão do servidor: 5.7.44-cll-lve
-- versão do PHP: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `crbhlspv_trackmoz`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `avaliacoes`
--

CREATE TABLE `avaliacoes` (
  `id` int(11) NOT NULL,
  `missao_id` int(11) NOT NULL,
  `avaliador_id` int(11) NOT NULL,
  `avaliado_id` int(11) NOT NULL,
  `nota` int(11) NOT NULL,
  `comentario` text,
  `data_avaliacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `configuracoes`
--

CREATE TABLE `configuracoes` (
  `id` int(11) NOT NULL,
  `chave` varchar(50) NOT NULL,
  `valor` text,
  `descricao` text,
  `data_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `configuracoes`
--

INSERT INTO `configuracoes` (`id`, `chave`, `valor`, `descricao`, `data_atualizacao`) VALUES
(1, 'site_name', 'TrackMoz', 'Nome do site', '2025-12-19 23:49:40'),
(2, 'site_email', 'contato@trackmoz.com', 'E-mail de contato do site', '2025-12-19 23:49:40'),
(3, 'max_upload_size', '5', 'Tamanho máximo de upload em MB', '2025-12-19 23:49:40'),
(4, 'maintenance_mode', '0', 'Modo de manutenção (0=desativado, 1=ativado)', '2025-12-19 23:49:40'),
(5, 'notify_new_users', '1', 'Notificar sobre novos usuários', '2025-12-19 23:49:40'),
(6, 'notify_new_missions', '1', 'Notificar sobre novas missões', '2025-12-19 23:49:40'),
(7, 'notify_new_ratings', '1', 'Notificar sobre novas avaliações', '2025-12-19 23:49:40'),
(8, 'notify_reported_content', '1', 'Notificar sobre conteúdo reportado', '2025-12-19 23:49:40');

-- --------------------------------------------------------

--
-- Estrutura da tabela `conversas`
--

CREATE TABLE `conversas` (
  `id` int(11) NOT NULL,
  `usuario1_id` int(11) NOT NULL,
  `usuario2_id` int(11) NOT NULL,
  `missao_id` int(11) DEFAULT NULL,
  `ultima_mensagem_id` int(11) DEFAULT NULL,
  `nao_lidas` int(11) DEFAULT '0',
  `ultima_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `conversas`
--

INSERT INTO `conversas` (`id`, `usuario1_id`, `usuario2_id`, `missao_id`, `ultima_mensagem_id`, `nao_lidas`, `ultima_atualizacao`) VALUES
(1, 3, 5, NULL, NULL, 0, '2025-12-23 23:07:42'),
(2, 3, 4, 1, NULL, 0, '2025-12-23 23:14:26');

-- --------------------------------------------------------

--
-- Estrutura da tabela `documentos`
--

CREATE TABLE `documentos` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo_documento` enum('bi','cnh','alvara','registro_empresa','outros') NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `caminho_arquivo` varchar(255) NOT NULL,
  `data_upload` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pendente','aprovado','rejeitado') DEFAULT 'pendente',
  `bloqueado` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `documentos_missao`
--

CREATE TABLE `documentos_missao` (
  `id` int(11) NOT NULL,
  `missao_id` int(11) NOT NULL,
  `nome` varchar(255) NOT NULL,
  `arquivo` varchar(255) NOT NULL,
  `tipo` varchar(100) DEFAULT NULL,
  `data_upload` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_from_contratante` tinyint(1) DEFAULT '1',
  `descricao` text
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `documentos_missao`
--

INSERT INTO `documentos_missao` (`id`, `missao_id`, `nome`, `arquivo`, `tipo`, `data_upload`, `is_from_contratante`, `descricao`) VALUES
(1, 2, 'IMG_2057.png', 'missao_2_694afc647352a.png', 'image/png', '2025-12-23 20:32:36', 1, ''),
(2, 2, 'IMG_0016.png', 'missao_2_694afc647452e.png', 'image/png', '2025-12-23 20:32:41', 1, 'Nada ');

-- --------------------------------------------------------

--
-- Estrutura da tabela `fotos_veiculo`
--

CREATE TABLE `fotos_veiculo` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `caminho_arquivo` varchar(255) NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `tipo_veiculo` varchar(50) DEFAULT NULL,
  `data_upload` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `fotos_veiculo`
--

INSERT INTO `fotos_veiculo` (`id`, `usuario_id`, `caminho_arquivo`, `nome_arquivo`, `tipo_veiculo`, `data_upload`) VALUES
(1, 4, '../../uploads/veiculos/veiculo_4_1766523450.png', 'veiculo_4_1766523450.png', '', '2025-12-23 20:57:30');

-- --------------------------------------------------------

--
-- Estrutura da tabela `historico_localizacao`
--

CREATE TABLE `historico_localizacao` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(10,8) NOT NULL,
  `data_registro` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `locais`
--

CREATE TABLE `locais` (
  `id` int(11) NOT NULL,
  `endereco` varchar(255) NOT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(10,8) NOT NULL,
  `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `mensagens`
--

CREATE TABLE `mensagens` (
  `id` int(11) NOT NULL,
  `remetente_id` int(11) NOT NULL,
  `destinatario_id` int(11) NOT NULL,
  `missao_id` int(11) DEFAULT NULL,
  `mensagem` text NOT NULL,
  `data_envio` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `lida` tinyint(1) DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `mensagens`
--

INSERT INTO `mensagens` (`id`, `remetente_id`, `destinatario_id`, `missao_id`, `mensagem`, `data_envio`, `lida`) VALUES
(1, 3, 5, NULL, 'ola', '2025-12-23 23:07:42', 0);

-- --------------------------------------------------------

--
-- Estrutura da tabela `missoes`
--

CREATE TABLE `missoes` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `caminhoneiro_id` int(11) DEFAULT NULL,
  `transportador_id` int(11) DEFAULT NULL,
  `parceria_id` int(11) DEFAULT NULL,
  `titulo` varchar(100) NOT NULL,
  `descricao` text,
  `origem` varchar(100) NOT NULL,
  `local_origem_id` int(11) DEFAULT NULL,
  `destino` varchar(100) NOT NULL,
  `local_destino_id` int(11) DEFAULT NULL,
  `tipo_veiculo` varchar(50) DEFAULT NULL,
  `tipo_carga` varchar(50) DEFAULT NULL,
  `peso_carga` decimal(10,2) DEFAULT NULL,
  `valor` decimal(10,2) DEFAULT NULL,
  `prazo_entrega` date DEFAULT NULL,
  `status` enum('aberta','em_negociacao','aceita','em_andamento','concluida','cancelada','aguardando_confirmacao','emergencia','em_transito','em_entrega') DEFAULT 'aberta',
  `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ultima_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status_viagem` enum('nao_iniciada','coleta','entrega','finalizada') DEFAULT 'nao_iniciada',
  `requer_documento_carga` tinyint(1) DEFAULT '0',
  `tipo_documento_carga` varchar(100) DEFAULT NULL,
  `chegada_destino` timestamp NULL DEFAULT NULL,
  `data_inicio` timestamp NULL DEFAULT NULL,
  `data_coleta` timestamp NULL DEFAULT NULL,
  `data_chegada` timestamp NULL DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `missoes`
--

INSERT INTO `missoes` (`id`, `empresa_id`, `caminhoneiro_id`, `transportador_id`, `titulo`, `descricao`, `origem`, `local_origem_id`, `destino`, `local_destino_id`, `tipo_veiculo`, `tipo_carga`, `peso_carga`, `valor`, `prazo_entrega`, `status`, `data_criacao`, `data_atualizacao`, `ultima_atualizacao`, `status_viagem`, `requer_documento_carga`, `tipo_documento_carga`, `chegada_destino`, `data_inicio`, `data_coleta`, `data_chegada`) VALUES
(1, 3, NULL, NULL, 'Transporte de viaturas ', 'a carga deve ser movida com cuidado e cada dano causado resulta em multas ao motorista e responsabilizacao completa ', 'Maputo', NULL, 'Xai-Xai, Gaza', NULL, 'caminhao', 'geral', NULL, '102000.00', '2025-12-26', 'em_andamento', '2025-12-23 20:21:25', '2025-12-23 20:33:31', '2025-12-23 20:33:31', 'nao_iniciada', 0, '', NULL, NULL, NULL, NULL),
(2, 3, NULL, NULL, 'Tranporte de carga ', 'Motorista capacitado ', 'Maputo ', NULL, 'Inhambane ', NULL, 'caminhao', 'perigosa', NULL, '120000.00', '2025-12-30', 'aberta', '2025-12-23 20:32:36', '2025-12-23 20:32:36', '2025-12-23 20:32:36', 'nao_iniciada', 1, 'guia_transporte', NULL, NULL, NULL, NULL),
(3, 3, NULL, NULL, 'Transporte de materiais de construção', 's', 'Beira', NULL, 'Maputo', NULL, 'caminhao', 'geral', NULL, '130000.00', '2025-12-27', 'aberta', '2025-12-23 22:22:35', '2025-12-23 22:22:35', '2025-12-23 22:22:35', 'nao_iniciada', 0, '', NULL, NULL, NULL, NULL),
(4, 3, NULL, NULL, 'Trasnporte de Combustivel', 'caro em perfeitas condicoes\r\n', 'Maputo', NULL, 'Manica', NULL, 'caminhao', 'perigosa', NULL, '170000.00', '2025-12-31', 'aberta', '2025-12-23 22:24:05', '2025-12-23 22:24:05', '2025-12-23 22:24:05', 'nao_iniciada', 0, '', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura da tabela `notificacoes`
--

CREATE TABLE `notificacoes` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo` enum('missao','proposta','proposta_aceita','mensagem','avaliacao','sistema','confirmacao_entrega','emergencia','documento','parceria') NOT NULL,
  `titulo` varchar(100) NOT NULL,
  `mensagem` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `lida` tinyint(1) DEFAULT '0',
  `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `notificacoes`
--

INSERT INTO `notificacoes` (`id`, `usuario_id`, `tipo`, `titulo`, `mensagem`, `link`, `lida`, `data_criacao`) VALUES
(1, 4, 'missao', 'Nova missão disponível', 'Uma nova missão foi publicada: Transporte de materiais de construção', NULL, 1, '2025-12-23 22:22:35'),
(2, 4, 'missao', 'Nova missão disponível', 'Uma nova missão foi publicada: Trasnporte de Combustivel', NULL, 0, '2025-12-23 22:24:05');

-- --------------------------------------------------------

--
-- Estrutura da tabela `perfil_caminhoneiro`
--

CREATE TABLE `perfil_caminhoneiro` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo_veiculo` varchar(50) DEFAULT 'Não informado',
  `placa_veiculo` varchar(20) DEFAULT NULL,
  `capacidade_carga` decimal(10,2) DEFAULT NULL,
  `descricao_veiculo` text,
  `numero_cnh` varchar(50) DEFAULT NULL,
  `validade_cnh` date DEFAULT NULL,
  `disponibilidade` enum('disponivel','indisponivel','ocupado','manutencao') NOT NULL DEFAULT 'indisponivel',
  `avaliacao_media` decimal(3,1) DEFAULT '0.0',
  `total_entregas` int(11) DEFAULT '0',
  `ultima_atualizacao_local` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(10,8) DEFAULT NULL,
  `ultima_localizacao_lat` decimal(10,8) DEFAULT NULL,
  `ultima_localizacao_lng` decimal(10,8) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `perfil_caminhoneiro`
--

INSERT INTO `perfil_caminhoneiro` (`id`, `usuario_id`, `tipo_veiculo`, `placa_veiculo`, `capacidade_carga`, `descricao_veiculo`, `numero_cnh`, `validade_cnh`, `disponibilidade`, `avaliacao_media`, `total_entregas`, `ultima_atualizacao_local`, `latitude`, `longitude`, `ultima_localizacao_lat`, `ultima_localizacao_lng`) VALUES
(1, 4, 'Camiao articulado', 'ADW 131 MP', '12000.00', '', '345678', '2029-12-23', 'disponivel', '0.0', 0, '2025-12-23 20:38:08', NULL, NULL, '-25.91293440', '32.55173120'),
(2, 5, 'Não informado', NULL, NULL, NULL, NULL, NULL, 'indisponivel', '0.0', 0, '2025-12-23 23:04:00', NULL, NULL, NULL, NULL),
(3, 6, 'Não informado', NULL, NULL, NULL, NULL, NULL, 'indisponivel', '0.0', 0, '2025-12-23 23:06:05', NULL, NULL, NULL, NULL),
(4, 8, 'Não informado', NULL, NULL, NULL, NULL, NULL, 'indisponivel', '0.0', 0, '2026-03-02 07:23:04', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura da tabela `perfil_empresa`
--

CREATE TABLE `perfil_empresa` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `nome_empresa` varchar(100) NOT NULL,
  `nuit` varchar(30) DEFAULT NULL,
  `tipo_empresa` varchar(50) DEFAULT NULL,
  `cnpj` varchar(20) DEFAULT NULL,
  `ramo_atividade` varchar(100) DEFAULT NULL,
  `site` varchar(255) DEFAULT NULL,
  `descricao` text,
  `endereco` varchar(255) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `responsavel_legal` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `telefone_comercial` varchar(20) DEFAULT NULL,
  `banco` varchar(100) DEFAULT NULL,
  `iban` varchar(100) DEFAULT NULL,
  `email_comercial` varchar(100) DEFAULT NULL,
  `avaliacao_media` decimal(3,1) DEFAULT '0.0',
  `total_missoes` int(11) DEFAULT '0',
  `verificada` tinyint(1) DEFAULT '0',
  `observacoes_verificacao` text,
  `distrito` varchar(100) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `perfil_empresa`
--

INSERT INTO `perfil_empresa` (`id`, `usuario_id`, `nome_empresa`, `nuit`, `tipo_empresa`, `cnpj`, `ramo_atividade`, `site`, `descricao`, `endereco`, `cidade`, `responsavel_legal`, `provincia`, `telefone_comercial`, `banco`, `iban`, `email_comercial`, `avaliacao_media`, `total_missoes`, `verificada`, `observacoes_verificacao`, `distrito`) VALUES
(1, 3, 'Muholove investiments, LDA', '8763862', 'industrial', NULL, NULL, NULL, NULL, 'Gaza', 'xai-xai', 'Helton Carlos muholove', 'Gaza', '843908149', 'BCI', '6242535244536242', 'muholoveinvestments@gmail.com', '0.0', 0, 0, NULL, 'xai-xai');

-- --------------------------------------------------------

--
-- Estrutura da tabela `perfil_transportador`
--

CREATE TABLE `perfil_transportador` (
  `id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `nome_empresa` varchar(100) NOT NULL,
  `nuit` varchar(20) DEFAULT NULL,
  `alvara` varchar(50) DEFAULT NULL,
  `endereco` varchar(255) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) DEFAULT NULL,
  `telefone_comercial` varchar(20) DEFAULT NULL,
  `email_comercial` varchar(100) DEFAULT NULL,
  `avaliacao_media` decimal(3,1) DEFAULT '0.0',
  `total_missoes` int(11) DEFAULT '0',
  `verificada` tinyint(1) DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `perfil_transportador`
--

INSERT INTO `perfil_transportador` (`id`, `usuario_id`, `nome_empresa`, `nuit`, `alvara`, `endereco`, `cidade`, `provincia`, `telefone_comercial`, `email_comercial`, `avaliacao_media`, `total_missoes`, `verificada`) VALUES
(1, 7, 'muholove', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '0.0', 0, 0);

-- --------------------------------------------------------

--
-- Estrutura da tabela `propostas`
--

CREATE TABLE `propostas` (
  `id` int(11) NOT NULL,
  `missao_id` int(11) NOT NULL,
  `caminhoneiro_id` int(11) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `observacoes` text,
  `status` enum('pendente','aceita','rejeitada','cancelada') NOT NULL DEFAULT 'pendente',
  `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `propostas`
--

INSERT INTO `propostas` (`id`, `missao_id`, `caminhoneiro_id`, `valor`, `observacoes`, `status`, `data_criacao`, `data_atualizacao`) VALUES
(1, 1, 4, '102000.00', 'estou interessado na missao', 'aceita', '2025-12-23 20:30:02', '2025-12-23 20:33:31'),
(2, 4, 5, '170000.00', '', 'pendente', '2025-12-23 23:04:31', '2025-12-23 23:04:31'),
(3, 4, 6, '170000.00', '', 'pendente', '2025-12-23 23:06:21', '2025-12-23 23:06:21'),
(4, 3, 6, '130000.00', '', 'pendente', '2025-12-23 23:06:28', '2025-12-23 23:06:28');

-- --------------------------------------------------------

--
-- Estrutura da tabela `registros_viagem`
--

CREATE TABLE `registros_viagem` (
  `id` int(11) NOT NULL,
  `missao_id` int(11) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `descricao` text,
  `data_registro` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Estrutura da tabela `transportador_motoristas`
--

CREATE TABLE `transportador_motoristas` (
  `id` int(11) NOT NULL,
  `transportador_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `cnh` varchar(50) DEFAULT NULL,
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `transportador_motoristas`
--

INSERT INTO `transportador_motoristas` (`id`, `transportador_id`, `nome`, `telefone`, `email`, `cnh`, `status`, `data_criacao`) VALUES
(1, 7, 'Cornelio Mahunlha', '843908149', 'liriabonecarlos5@gmail.com', NULL, 'ativo', '2026-01-01 19:56:48'),
(2, 7, 'Khelven Carlos Muholove', '877887546', 'kelven@gmail.com', '22DEFAS', 'ativo', '2026-01-01 20:10:00'),
(3, 7, 'Julio Edmundo Muholove', '845467898', 'julio@gmail.com', 'HHG322', 'ativo', '2026-01-01 20:11:55');

-- --------------------------------------------------------

--
-- Estrutura da tabela `transportador_veiculos`
--

CREATE TABLE `transportador_veiculos` (
  `id` int(11) NOT NULL,
  `transportador_id` int(11) NOT NULL,
  `placa` varchar(20) NOT NULL,
  `tipo_veiculo` varchar(50) DEFAULT NULL,
  `capacidade_carga` decimal(10,2) DEFAULT NULL,
  `status` enum('ativo','manutencao','inativo') NOT NULL DEFAULT 'ativo',
  `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `transportador_veiculos`
--

INSERT INTO `transportador_veiculos` (`id`, `transportador_id`, `placa`, `tipo_veiculo`, `capacidade_carga`, `status`, `data_criacao`) VALUES
(1, 7, 'AHZ 4567 MP', 'Camiao articulado', '10000.00', 'ativo', '2026-01-01 19:57:13'),
(2, 7, 'AHZ 123 Mp', 'Sisterna', '12000.00', 'ativo', '2026-01-01 20:06:11'),
(3, 7, 'ADE 324 GZ', 'Basculante', '9000.00', 'ativo', '2026-01-01 20:06:55'),
(4, 7, 'AAZ 323 GZ', 'Camiao articulado', '13000.00', 'ativo', '2026-01-01 20:07:45'),
(5, 7, 'AFD 232 NP', 'Carreta', '7000.00', 'ativo', '2026-01-01 20:08:15');

-- --------------------------------------------------------

--
-- Estrutura da tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `tipo_usuario` enum('admin','caminhoneiro','empresa','transportador') NOT NULL,
  `status` enum('pendente','ativo','bloqueado','inativo') DEFAULT 'pendente',
  `data_registro` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ultima_atividade` timestamp NULL DEFAULT NULL,
  `token_recuperacao` varchar(100) DEFAULT NULL,
  `token_expiracao` timestamp NULL DEFAULT NULL,
  `verificado` tinyint(1) DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

--
-- Extraindo dados da tabela `usuarios`
--

INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`, `telefone`, `foto_perfil`, `tipo_usuario`, `status`, `data_registro`, `ultima_atividade`, `token_recuperacao`, `token_expiracao`, `verificado`) VALUES
(1, 'Admin', 'admin@trackmoz.ivoneerp.com', '$2y$10$Me2NXSSjXMn/kt4Uen4EJ.D/EElb0sT11CLVgA/58SXhX5aFI2wEi', NULL, NULL, 'admin', 'ativo', '2025-12-23 20:11:07', NULL, NULL, NULL, 1),
(2, 'Emilton Muholove', 'emiltonmuholove845@gmail.com', '$2y$10$Me2NXSSjXMn/kt4Uen4EJ.D/EElb0sT11CLVgA/58SXhX5aFI2wEi', '879038185', NULL, 'admin', 'ativo', '2025-12-23 20:16:23', NULL, NULL, NULL, 0),
(3, 'Helton Carlos muholove', 'helton@gmail.com', '$2y$10$Me2NXSSjXMn/kt4Uen4EJ.D/EElb0sT11CLVgA/58SXhX5aFI2wEi', '876758800', '694b02e5b99c3_Camiao zitah.png', 'empresa', 'ativo', '2025-12-23 20:18:57', NULL, NULL, NULL, 0),
(4, 'Maya Zitha', 'arcenia@gmail.com', '$2y$10$VXo.8iUsANzY4T3fFgFGM.KFfT4MrzStztRfA1I90WQ1ue.GnQXki', '843908149', NULL, 'caminhoneiro', 'ativo', '2025-12-23 20:28:52', '2025-12-23 23:16:11', NULL, NULL, 0),
(5, 'Rachide Sufo', 'rachide@gmail.com', '$2y$10$5zXTwh4v6BFGVyVDfMPsG.DtDRD.XUmq2EE0euFwksA46nlVEdm.u', NULL, NULL, 'caminhoneiro', 'ativo', '2025-12-23 23:04:00', NULL, NULL, NULL, 0),
(6, 'CORNELIO SALOMAO MAHUNLHA', 'nelitosalomaomahu@gmail.com', '$2y$10$NPKCNQaGqFVt7UP5Ly2IoehAtWqZMtdgW802iDhoLBfsNZTs5Ke1y', NULL, NULL, 'caminhoneiro', 'ativo', '2025-12-23 23:06:05', NULL, NULL, NULL, 0),
(7, 'muholove', 'muholove@gmail.com', '$2y$10$kJZ02m4Q7PKQLO2BcdczluvuIcbyCISpy4aUbLoHZ0Cs7tggcGHk2', NULL, NULL, 'transportador', 'ativo', '2026-01-01 19:55:40', NULL, NULL, NULL, 0),
(8, 'Legacy', 'zambuko@gmail.com', '$2y$10$eps0cWPgE3NBHmO/YVIzWuOSS7WgfamBtv1j.czzfGFRVcOGBcSbu', NULL, NULL, 'caminhoneiro', 'ativo', '2026-03-02 07:23:04', NULL, NULL, NULL, 0);

--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `avaliacoes`
--
ALTER TABLE `avaliacoes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `missao_id` (`missao_id`),
  ADD KEY `avaliador_id` (`avaliador_id`),
  ADD KEY `avaliado_id` (`avaliado_id`);

--
-- Índices para tabela `configuracoes`
--
ALTER TABLE `configuracoes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chave` (`chave`);

--
-- Índices para tabela `conversas`
--
ALTER TABLE `conversas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario1_id` (`usuario1_id`,`usuario2_id`,`missao_id`),
  ADD KEY `usuario2_id` (`usuario2_id`),
  ADD KEY `missao_id` (`missao_id`);

--
-- Índices para tabela `documentos`
--
ALTER TABLE `documentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Índices para tabela `documentos_missao`
--
ALTER TABLE `documentos_missao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `missao_id` (`missao_id`);

--
-- Índices para tabela `fotos_veiculo`
--
ALTER TABLE `fotos_veiculo`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Índices para tabela `historico_localizacao`
--
ALTER TABLE `historico_localizacao`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Índices para tabela `locais`
--
ALTER TABLE `locais`
  ADD PRIMARY KEY (`id`);

--
-- Índices para tabela `mensagens`
--
ALTER TABLE `mensagens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `remetente_id` (`remetente_id`),
  ADD KEY `destinatario_id` (`destinatario_id`),
  ADD KEY `missao_id` (`missao_id`);

--
-- Índices para tabela `missoes`
--
ALTER TABLE `missoes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `empresa_id` (`empresa_id`),
  ADD KEY `caminhoneiro_id` (`caminhoneiro_id`),
  ADD KEY `fk_local_origem` (`local_origem_id`),
  ADD KEY `fk_local_destino` (`local_destino_id`);

--
-- Índices para tabela `notificacoes`
--
ALTER TABLE `notificacoes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Índices para tabela `perfil_caminhoneiro`
--
ALTER TABLE `perfil_caminhoneiro`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario_id` (`usuario_id`);

--
-- Índices para tabela `perfil_empresa`
--
ALTER TABLE `perfil_empresa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario_id` (`usuario_id`);

--
-- Índices para tabela `perfil_transportador`
--
ALTER TABLE `perfil_transportador`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario_id` (`usuario_id`);

--
-- Índices para tabela `propostas`
--
ALTER TABLE `propostas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_missao_caminhoneiro` (`missao_id`,`caminhoneiro_id`),
  ADD KEY `missao_id` (`missao_id`),
  ADD KEY `caminhoneiro_id` (`caminhoneiro_id`);

--
-- Índices para tabela `registros_viagem`
--
ALTER TABLE `registros_viagem`
  ADD PRIMARY KEY (`id`),
  ADD KEY `missao_id` (`missao_id`);

--
-- Índices para tabela `transportador_motoristas`
--
ALTER TABLE `transportador_motoristas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transportador_id` (`transportador_id`);

--
-- Índices para tabela `transportador_veiculos`
--
ALTER TABLE `transportador_veiculos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_transportador_placa` (`transportador_id`,`placa`),
  ADD KEY `transportador_id` (`transportador_id`);

--
-- Índices para tabela `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT de tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `avaliacoes`
--
ALTER TABLE `avaliacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `configuracoes`
--
ALTER TABLE `configuracoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de tabela `conversas`
--
ALTER TABLE `conversas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `documentos`
--
ALTER TABLE `documentos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `documentos_missao`
--
ALTER TABLE `documentos_missao`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `fotos_veiculo`
--
ALTER TABLE `fotos_veiculo`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `historico_localizacao`
--
ALTER TABLE `historico_localizacao`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `locais`
--
ALTER TABLE `locais`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `mensagens`
--
ALTER TABLE `mensagens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `missoes`
--
ALTER TABLE `missoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `notificacoes`
--
ALTER TABLE `notificacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `perfil_caminhoneiro`
--
ALTER TABLE `perfil_caminhoneiro`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `perfil_empresa`
--
ALTER TABLE `perfil_empresa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `perfil_transportador`
--
ALTER TABLE `perfil_transportador`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `propostas`
--
ALTER TABLE `propostas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `registros_viagem`
--
ALTER TABLE `registros_viagem`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `transportador_motoristas`
--
ALTER TABLE `transportador_motoristas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `transportador_veiculos`
--
ALTER TABLE `transportador_veiculos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

-- --------------------------------------------------------

--
-- Estrutura da tabela `parcerias`
-- Fase 2: Contratos de Longo Prazo
--

CREATE TABLE IF NOT EXISTS `parcerias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `empresa_id` int(11) NOT NULL,
  `transportador_id` int(11) NOT NULL,
  `descricao` text,
  `data_inicio` date NOT NULL,
  `data_fim` date DEFAULT NULL,
  `exclusiva` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('pendente','ativa','suspensa','terminada','rejeitada') NOT NULL DEFAULT 'pendente',
  `proposto_por` enum('empresa','transportador') NOT NULL DEFAULT 'empresa',
  `motivo_rejeicao` text DEFAULT NULL,
  `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `empresa_id` (`empresa_id`),
  KEY `transportador_id` (`transportador_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
