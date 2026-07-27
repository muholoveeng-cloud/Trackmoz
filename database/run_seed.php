<?php
/**
 * Script de seed para TrackMoz
 * Dados de teste realistas - Empresas, Motoristas, Chats e Missões
 *
 * Usar:
 *   cd c:\wamp64\www\trackmoz
 *   php database/run_seed.php
 *
 * Ou via browser: http://localhost/trackmoz/database/run_seed.php
 */

set_time_limit(0);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';

$SENHA_HASH = '$2y$12$kJrIOeNGRhrLE8zNm/p9H.I3D4TnuH78WK3W8nhZieiS8oYKG6nGK';

$cidadesCoords = [
    'maputo' => [-25.9653, 32.5892],
    'matola' => [-25.9622, 32.4588],
    'beira' => [-19.8436, 34.8389],
    'nampula' => [-15.1165, 39.2666],
    'xai-xai' => [-25.0519, 33.6442],
    'inhambane' => [-23.8650, 35.3833],
    'manica' => [-18.9333, 32.8667],
    'chimoio' => [-19.1167, 33.4833],
    'tete' => [-16.1565, 33.5867],
    'quelimane' => [-17.8764, 36.8873],
    'pemba' => [-12.9730, 40.5175],
    'lichinga' => [-13.3128, 35.2406],
    'maxixe' => [-23.8597, 35.3472],
    'vilanculos' => [-22.0033, 35.3133],
    'dondo' => [-19.6167, 34.7500],
    'moatize' => [-16.1000, 33.7500],
    'nacala' => [-14.5500, 40.6833],
    'angoche' => [-16.2300, 39.9100],
    'cuamba' => [-14.3886, 36.5372],
    'chokwe' => [-24.5333, 32.9833],
    'montepuez' => [-13.1256, 39.1600],
    'gurue' => [-15.4667, 36.9833],
    'mocuba' => [-17.0044, 36.9850],
];

try {
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<pre>";
    echo "=== TrackMoz Seed Script ===\n";

    // Desabilitar checks
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Limpar tabelas
    $tabelas = [
        'avaliacoes','conversas','documentos','documentos_missao','fotos_veiculo',
        'historico_localizacao','locais','mensagens','missoes','notificacoes',
        'parcerias','perfil_caminhoneiro','perfil_empresa','perfil_transportador',
        'propostas','registros_viagem','transportador_motoristas','transportador_veiculos',
        'usuarios'
    ];
    foreach ($tabelas as $t) {
        $conn->exec("TRUNCATE TABLE `$t`");
        echo "TRUNCATE $t OK\n";
    }

    // Resetar AUTO_INCREMENT
    foreach ($tabelas as $t) {
        $conn->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
    }
    echo "\n";

    // ---------- USUARIOS ----------
    echo "--- Inserindo usuarios...\n";

    // Admin
    $stmtUser = $conn->prepare("INSERT INTO usuarios (nome, email, senha, telefone, tipo_usuario, status, verificado) VALUES (:n, :e, :s, :t, :tu, 'ativo', 1)");
    $stmtUser->execute([':n'=>'Administrador Sistema', ':e'=>'admin@trackmoz.mz', ':s'=>$SENHA_HASH, ':t'=>'840000000', ':tu'=>'admin']);
    $adminId = $conn->lastInsertId();
    $stmtUser->execute([':n'=>'Suporte TrackMoz', ':e'=>'suporte@trackmoz.mz', ':s'=>$SENHA_HASH, ':t'=>'841111111', ':tu'=>'admin']);

    // Empresas
    $empresas = [
        // Maputo
        ['Petromoc Lda', 'petromoc@trackmoz.mz', '820000001', 'Combustiveis', 'Maputo'],
        ['Oiltanking Matola', 'oiltanking@trackmoz.mz', '820000002', 'Petróleo', 'Matola'],
        ['Emossa', 'emossa@trackmoz.mz', '820000003', 'Saneamento', 'Maputo'],
        ['Mozal', 'mozal@trackmoz.mz', '820000004', 'Metalurgia', 'Boane'],
        ['CFM Maputo', 'cfm.maputo@trackmoz.mz', '820000005', 'Transporte Ferroviário', 'Maputo'],
        ['TotalEnergies MZ', 'total@trackmoz.mz', '820000006', 'Combustiveis', 'Maputo'],
        ['Galp Mocambique', 'galp@trackmoz.mz', '820000007', 'Combustiveis', 'Maputo'],
        ['JFS Holding', 'jfs@trackmoz.mz', '820000008', 'Agricultura', 'Matola'],
        ['Cimentos Mocambique', 'cimentosmz@trackmoz.mz', '820000009', 'Construção', 'Matola'],
        ['Grupo Focus', 'focus@trackmoz.mz', '820000010', 'Logistica', 'Maputo'],
        // Gaza
        ['Agricom SA', 'agricom@trackmoz.mz', '821000001', 'Agricultura', 'Chokwe'],
        ['Cimento Gaza', 'cimentogaza@trackmoz.mz', '821000002', 'Construção', 'Xai-Xai'],
        ['Mocambique Florestas GZ', 'florestas.gz@trackmoz.mz', '821000003', 'Florestal', 'Chokwe'],
        ['Cooperativa Chokwe', 'coop.chokwe@trackmoz.mz', '821000004', 'Agricultura', 'Chokwe'],
        ['Pescamar Gaza', 'pescamar.gz@trackmoz.mz', '821000005', 'Pesca', 'Xai-Xai'],
        ['Transportes Nweti', 'nweti@trackmoz.mz', '821000006', 'Transporte', 'Chokwe'],
        ['Supermercado Save', 'save@trackmoz.mz', '821000007', 'Comércio', 'Xai-Xai'],
        ['Fabrica de Gelo Xai-Xai', 'gelo.xx@trackmoz.mz', '821000008', 'Industrial', 'Xai-Xai'],
        ['Agroindustrial Limpopo', 'agro.limpopo@trackmoz.mz', '821000009', 'Agricultura', 'Chokwe'],
        ['Construtora Massinga GZ', 'massinga@trackmoz.mz', '821000010', 'Construção', 'Xai-Xai'],
        // Inhambane
        ['Petromoc Inhambane', 'petromoc.ib@trackmoz.mz', '822000001', 'Combustiveis', 'Inhambane'],
        ['Miti Lda', 'miti@trackmoz.mz', '822000002', 'Turismo', 'Inhambane'],
        ['Madeiras de Inhambane', 'madeiras.ib@trackmoz.mz', '822000003', 'Florestal', 'Maxixe'],
        ['Caju Industrial IB', 'caju.ib@trackmoz.mz', '822000004', 'Agroindustria', 'Inhambane'],
        ['Vilanculos Beach Lodge', 'vbl@trackmoz.mz', '822000005', 'Turismo', 'Vilanculos'],
        ['Inhambane Comercial', 'comercial.ib@trackmoz.mz', '822000006', 'Comércio', 'Inhambane'],
        ['Pescamar Inhambane', 'pescamar.ib@trackmoz.mz', '822000007', 'Pesca', 'Inhambane'],
        ['Transportes Inhambane', 'transp.ib@trackmoz.mz', '822000008', 'Transporte', 'Maxixe'],
        ['Construtora Maxixe', 'maxixe.cons@trackmoz.mz', '822000009', 'Construção', 'Maxixe'],
        ['Agromoz Inhambane', 'agromoz.ib@trackmoz.mz', '822000010', 'Agricultura', 'Inhambane'],
        // Sofala
        ['Portucel Mocambique', 'portucel@trackmoz.mz', '823000001', 'Florestal', 'Beira'],
        ['Beira Grain Terminal', 'bgt@trackmoz.mz', '823000002', 'Grãos', 'Beira'],
        ['Cornelder de Mocambique', 'cornelder@trackmoz.mz', '823000003', 'Portos', 'Beira'],
        ['Madal Sofala', 'madal@trackmoz.mz', '823000004', 'Agroindustria', 'Dondo'],
        ['Beira Steel', 'beirasteel@trackmoz.mz', '823000005', 'Metalurgia', 'Beira'],
        ['Armazens Gerais Beira', 'agb@trackmoz.mz', '823000006', 'Armazenagem', 'Beira'],
        ['Companhia Ind Beira', 'cib@trackmoz.mz', '823000007', 'Industrial', 'Beira'],
        ['Ferromo Lda', 'ferromo@trackmoz.mz', '823000008', 'Mineração', 'Beira'],
        ['Construtora SOGEMA', 'sogema@trackmoz.mz', '823000009', 'Construção', 'Beira'],
        ['Mphingue Transportes', 'mphingue@trackmoz.mz', '823000010', 'Transporte', 'Dondo'],
        // Manica
        ['Matanuska Mocambique', 'matanuska@trackmoz.mz', '824000001', 'Chá', 'Chimoio'],
        ['Manica Gold', 'manicagold@trackmoz.mz', '824000002', 'Mineração', 'Manica'],
        ['Companhia de Sena', 'sena@trackmoz.mz', '824000003', 'Açúcar', 'Chimoio'],
        ['Chimoio Cereais', 'cereais.chimoio@trackmoz.mz', '824000004', 'Grãos', 'Chimoio'],
        ['Manica Mocambique Lda', 'manica.lda@trackmoz.mz', '824000005', 'Comércio', 'Manica'],
        ['Transportes Manica', 'transp.manica@trackmoz.mz', '824000006', 'Transporte', 'Chimoio'],
        ['Agromoz Manica', 'agromoz.manica@trackmoz.mz', '824000007', 'Agricultura', 'Chimoio'],
        ['Chimoio Armazens', 'arm.chimoio@trackmoz.mz', '824000008', 'Armazenagem', 'Chimoio'],
        ['Manica Marmores', 'marmores@trackmoz.mz', '824000009', 'Mineração', 'Manica'],
        ['Construtora Chimanimani', 'chimanimani@trackmoz.mz', '824000010', 'Construção', 'Manica'],
        // Tete
        ['Vale Mocambique', 'vale@trackmoz.mz', '825000001', 'Mineração', 'Tete'],
        ['Jindal Mocambique', 'jindal@trackmoz.mz', '825000002', 'Mineração', 'Moatize'],
        ['Cahora Bassa', 'cbh@trackmoz.mz', '825000003', 'Energia', 'Tete'],
        ['Tete Steel', 'tetesteel@trackmoz.mz', '825000004', 'Metalurgia', 'Tete'],
        ['Minas Moatize', 'moatize@trackmoz.mz', '825000005', 'Mineração', 'Moatize'],
        ['Companhia Ind Zambeze', 'ciz@trackmoz.mz', '825000006', 'Industrial', 'Tete'],
        ['Tete Agroindustrial', 'teteagro@trackmoz.mz', '825000007', 'Agroindustria', 'Tete'],
        ['Transportes Zambeze', 'transp.zambeze@trackmoz.mz', '825000008', 'Transporte', 'Tete'],
        ['Benga Power Project', 'benga@trackmoz.mz', '825000009', 'Energia', 'Tete'],
        ['Mphanda Nkuwa', 'mphanda@trackmoz.mz', '825000010', 'Energia', 'Tete'],
        // Zambezia
        ['Companhia de Sena ZA', 'sena.zambezia@trackmoz.mz', '826000001', 'Açúcar', 'Quelimane'],
        ['Quelimane Cereais', 'cereais.q@trackmoz.mz', '826000002', 'Grãos', 'Quelimane'],
        ['Madal Zambezia', 'madal.z@trackmoz.mz', '826000003', 'Agroindustria', 'Quelimane'],
        ['Mocuba Agroindustrial', 'mocuba@trackmoz.mz', '826000004', 'Agroindustria', 'Mocuba'],
        ['Zambezia Industrial', 'zamind@trackmoz.mz', '826000005', 'Industrial', 'Quelimane'],
        ['Transportes Quelimane', 'transp.q@trackmoz.mz', '826000006', 'Transporte', 'Quelimane'],
        ['N Mocambique Zambezia', 'nmz.z@trackmoz.mz', '826000007', 'Comércio', 'Quelimane'],
        ['Quelimane Portos', 'portos.q@trackmoz.mz', '826000008', 'Portos', 'Quelimane'],
        ['Cervejas Zambezia', 'cervejas.z@trackmoz.mz', '826000009', 'Bebidas', 'Quelimane'],
        ['Mocambique Florestas ZA', 'florestas.z@trackmoz.mz', '826000010', 'Florestal', 'Quelimane'],
        // Nampula
        ['Portos do Norte', 'pn@trackmoz.mz', '827000001', 'Portos', 'Nacala'],
        ['SAHN', 'sahn@trackmoz.mz', '827000002', 'Agricultura', 'Nampula'],
        ['Cimentos Nampula', 'cimentos.np@trackmoz.mz', '827000003', 'Construção', 'Nampula'],
        ['Nampula Agroindustrial', 'agro.np@trackmoz.mz', '827000004', 'Agroindustria', 'Nampula'],
        ['Mcel Logistica', 'mcel@trackmoz.mz', '827000005', 'Telecom/Logistica', 'Nampula'],
        ['Ola Nampula', 'ola.np@trackmoz.mz', '827000006', 'Industrial', 'Nampula'],
        ['Petromoc Nampula', 'petromoc.np@trackmoz.mz', '827000007', 'Combustiveis', 'Nampula'],
        ['Nampula Armazens', 'arm.np@trackmoz.mz', '827000008', 'Armazenagem', 'Nacala'],
        ['Minas de Nampula', 'minas.np@trackmoz.mz', '827000009', 'Mineração', 'Nampula'],
        ['Transportes Nampula', 'transp.np@trackmoz.mz', '827000010', 'Transporte', 'Nampula'],
        // Cabo Delgado
        ['TotalEnergies LNG', 'totallng@trackmoz.mz', '828000001', 'Petróleo e Gás', 'Pemba'],
        ['Sasol Pemba', 'sasol@trackmoz.mz', '828000002', 'Energia', 'Pemba'],
        ['Delgado Mineracao', 'delgado.min@trackmoz.mz', '828000003', 'Mineração', 'Montepuez'],
        ['Pemba Bay Logistica', 'pembabay@trackmoz.mz', '828000004', 'Logistica', 'Pemba'],
        ['Cabo Delgado Cereais', 'cereais.cd@trackmoz.mz', '828000005', 'Grãos', 'Pemba'],
        ['Palma Agroindustrial', 'palma@trackmoz.mz', '828000006', 'Agroindustria', 'Palma'],
        ['Montepuez Ruby Mining', 'ruby@trackmoz.mz', '828000007', 'Mineração', 'Montepuez'],
        ['Transportes Cabo Delgado', 'transp.cd@trackmoz.mz', '828000008', 'Transporte', 'Pemba'],
        ['Afungi Logistica', 'afungi@trackmoz.mz', '828000009', 'Logistica', 'Pemba'],
        ['Anadarko Mocambique', 'anadarko@trackmoz.mz', '828000010', 'Petróleo', 'Pemba'],
        // Niassa
        ['SAPEC Niassa', 'sapec@trackmoz.mz', '829000001', 'Tabaco', 'Lichinga'],
        ['Lichinga Industrial', 'lichinga.ind@trackmoz.mz', '829000002', 'Industrial', 'Lichinga'],
        ['Niassa Cereais', 'cereais.ni@trackmoz.mz', '829000003', 'Grãos', 'Lichinga'],
        ['Niassa Mineracao', 'mineracao.ni@trackmoz.mz', '829000004', 'Mineração', 'Lichinga'],
        ['Lichinga Madeiras', 'madeiras.ni@trackmoz.mz', '829000005', 'Florestal', 'Lichinga'],
        ['Niassa Agroindustrial', 'niassaagro@trackmoz.mz', '829000006', 'Agroindustria', 'Cuamba'],
        ['Cuamba Comercial', 'cuamba@trackmoz.mz', '829000007', 'Comércio', 'Cuamba'],
        ['Transportes Niassa', 'transp.ni@trackmoz.mz', '829000008', 'Transporte', 'Lichinga'],
        ['Unilurio Logistica', 'unilurio@trackmoz.mz', '829000009', 'Educacional/Logistica', 'Lichinga'],
        ['Mandimba Cooperativa', 'mandimba@trackmoz.mz', '829000010', 'Agricultura', 'Mandimba'],
    ];

    $empresaIds = [];
    $stmtEmpUser = $conn->prepare("INSERT INTO usuarios (nome, email, senha, telefone, tipo_usuario, status, verificado) VALUES (:n, :e, :s, :t, 'empresa', 'ativo', 1)");
    $stmtEmpPerfil = $conn->prepare("INSERT INTO perfil_empresa (usuario_id, nome_empresa, nuit, ramo_atividade, cidade, provincia, telefone_comercial, email_comercial, verificada) VALUES (:uid, :ne, :nuit, :ramo, :cidade, :prov, :tel, :email, 1)");
    $i = 1;
    foreach ($empresas as $e) {
        $stmtEmpUser->execute([':n'=>$e[0], ':e'=>$e[1], ':s'=>$SENHA_HASH, ':t'=>$e[2]]);
        $uid = $conn->lastInsertId();
        $empresaIds[] = $uid;
        $prov = match ($e[4]) {
            'Maputo', 'Matola', 'Boane' => 'Maputo',
            'Chokwe', 'Xai-Xai' => 'Gaza',
            'Inhambane', 'Maxixe', 'Vilanculos' => 'Inhambane',
            'Beira', 'Dondo' => 'Sofala',
            'Chimoio', 'Manica' => 'Manica',
            'Tete', 'Moatize' => 'Tete',
            'Quelimane', 'Mocuba' => 'Zambezia',
            'Nampula', 'Nacala' => 'Nampula',
            'Pemba', 'Montepuez', 'Palma' => 'Cabo Delgado',
            'Lichinga', 'Cuamba', 'Mandimba' => 'Niassa',
            default => 'Maputo',
        };
        $stmtEmpPerfil->execute([':uid'=>$uid, ':ne'=>$e[0], ':nuit'=>strval(100000000 + $i), ':ramo'=>$e[3], ':cidade'=>$e[4], ':prov'=>$prov, ':tel'=>$e[2], ':email'=>$e[1]]);
        $i++;
    }
    echo "Empresas inseridas: " . count($empresaIds) . "\n";

    // Caminhoneiros
    $caminhoneirosNomes = [
        'Carlos Mbasha','Armando Zavale','Elias Matsinhe','Jose Muchanga','Manuel Ussene',
        'Felisberto Morais','Paulo Cuamba','Abdul Gafar','Ricardo Salimo','Samuel Matavele',
        'Domingos Nguli','Jaime Jone','Beto Macie','Luis Nampula','Fernando Dique'
    ];
    $caminhoneiroIds = [];
    $stmtCamUser = $conn->prepare("INSERT INTO usuarios (nome, email, senha, telefone, tipo_usuario, status, verificado) VALUES (:n, :e, :s, :t, 'caminhoneiro', 'ativo', 1)");
    $stmtCamPerfil = $conn->prepare("INSERT INTO perfil_caminhoneiro (usuario_id, tipo_veiculo, placa_veiculo, capacidade_carga, numero_cnh, validade_cnh, disponibilidade, avaliacao_media, total_entregas, latitude, longitude) VALUES (:uid, :tv, :placa, :cap, :cnh, :val, :disp, :av, :tot, :lat, :lng)");
    $tipos = ['Camião articulado','Basculante','Camião de caixa','Cisterna','Carreta','Camião articulado','Basculante','Camião de caixa','Cisterna','Carreta','Camião articulado','Basculante','Camião de caixa','Cisterna','Carreta'];
    $placas = ['AAA 111 MP','BBB 222 GZ','CCC 333 IB','DDD 444 SF','EEE 555 MN','FFF 666 TT','GGG 777 ZA','HHH 888 NP','III 999 CD','JJJ 000 NS','KKK 111 MP','LLL 222 GZ','MMM 333 IB','NNN 444 SF','OOO 555 MN'];
    $caps = [15000,12000,8000,20000,22000,18000,14000,10000,25000,20000,16000,11000,9000,18000,24000];
    for ($i=0; $i<15; $i++) {
        $tel = '840000' . str_pad($i+1, 2, '0', STR_PAD_LEFT);
        $email = strtolower(str_replace(' ', '.', $caminhoneirosNomes[$i])) . '@trackmoz.mz';
        $stmtCamUser->execute([':n'=>$caminhoneirosNomes[$i], ':e'=>$email, ':s'=>$SENHA_HASH, ':t'=>$tel]);
        $uid = $conn->lastInsertId();
        $caminhoneiroIds[] = $uid;
        $disp = ['disponivel','disponivel','disponivel','ocupado','disponivel','disponivel','disponivel','manutencao','disponivel','disponivel','disponivel','ocupado','disponivel','disponivel','disponivel'][$i];
        $stmtCamPerfil->execute([
            ':uid'=>$uid, ':tv'=>$tipos[$i], ':placa'=>$placas[$i], ':cap'=>$caps[$i],
            ':cnh'=>'CNH100'.str_pad($i+1,3,'0',STR_PAD_LEFT),
            ':val'=>date('Y-m-d', strtotime('+' . (18 + ($i*3)%24) . ' months')),
            ':disp'=>$disp, ':av'=>number_format(3.5 + rand(0,15)/10,1), ':tot'=>rand(15,90),
            ':lat'=>null, ':lng'=>null
        ]);
    }
    echo "Caminhoneiros inseridos: " . count($caminhoneiroIds) . "\n";

    // Transportadores
    $transpNomes = [
        'Transportes Mocambique Lda','Carga Rapida MZ','Logistica Sul','Norte Transportes',
        'Caminhos do Zambeze','RodoNorte Lda','Expresso Limpopo','Beira Logistica',
        'Moatize Cargas','Nacala Frete','Tete Transportes','Maputo Freight',
        'Cabo Delgado Frete','Zambezia Rodovias','Niassa Logistica'
    ];
    $transpIds = [];
    $stmtTrUser = $conn->prepare("INSERT INTO usuarios (nome, email, senha, telefone, tipo_usuario, status, verificado) VALUES (:n, :e, :s, :t, 'transportador', 'ativo', 1)");
    $stmtTrPerfil = $conn->prepare("INSERT INTO perfil_transportador (usuario_id, nome_empresa, nuit, cidade, provincia, telefone_comercial, email_comercial, verificada) VALUES (:uid, :ne, :nuit, :cidade, :prov, :tel, :email, 1)");
    for ($i=0; $i<15; $i++) {
        $tel = '850000' . str_pad($i+1, 2, '0', STR_PAD_LEFT);
        $email = strtolower(str_replace(' ', '.', $transpNomes[$i])) . '@trackmoz.mz';
        $stmtTrUser->execute([':n'=>$transpNomes[$i], ':e'=>$email, ':s'=>$SENHA_HASH, ':t'=>$tel]);
        $uid = $conn->lastInsertId();
        $transpIds[] = $uid;
        $cidProv = [
            ['Maputo','Maputo'],['Matola','Maputo'],['Xai-Xai','Gaza'],['Nampula','Nampula'],['Tete','Tete'],
            ['Pemba','Cabo Delgado'],['Chokwe','Gaza'],['Beira','Sofala'],['Moatize','Tete'],['Nacala','Nampula'],
            ['Tete','Tete'],['Maputo','Maputo'],['Pemba','Cabo Delgado'],['Quelimane','Zambezia'],['Lichinga','Niassa']
        ][$i];
        $stmtTrPerfil->execute([':uid'=>$uid, ':ne'=>$transpNomes[$i], ':nuit'=>strval(200000001+$i), ':cidade'=>$cidProv[0], ':prov'=>$cidProv[1], ':tel'=>$tel, ':email'=>$email]);
    }
    echo "Transportadores inseridos: " . count($transpIds) . "\n";

    // Veiculos e motoristas para transportadores
    $stmtVei = $conn->prepare("INSERT INTO transportador_veiculos (transportador_id, placa, tipo_veiculo, capacidade_carga, status) VALUES (:tid, :placa, :tv, :cap, 'ativo')");
    $stmtMot = $conn->prepare("INSERT INTO transportador_motoristas (transportador_id, nome, telefone, email, cnh, status) VALUES (:tid, :n, :t, :e, :cnh, 'ativo')");
    foreach ($transpIds as $tid) {
        for ($v=0; $v<3; $v++) {
            $stmtVei->execute([':tid'=>$tid, ':placa'=>'TR'.$tid.'-'.($v+1), ':tv'=>['Camião articulado','Basculante','Cisterna'][$v%3], ':cap'=>[15000,12000,20000][$v%3]]);
        }
        for ($m=0; $m<2; $m++) {
            $stmtMot->execute([':tid'=>$tid, ':n'=>'Motorista '.($m+1).' TR'.$tid, ':t'=>'860000'.rand(1,99), ':e'=>'mot'.($m+1).'tr'.$tid.'@trackmoz.mz', ':cnh'=>'CNH300'.($tid*10+$m)]);
        }
    }
    echo "Veiculos e motoristas inseridos por transportador\n";

    // ---------- LOCAIS ----------
    echo "--- Inserindo locais...\n";
    $locaisNomes = [
        'Maputo' => [-25.9653, 32.5892], 'Matola' => [-25.9622, 32.4588], 'Boane' => [-26.3500, 32.3167],
        'Beira' => [-19.8436, 34.8389], 'Dondo' => [-19.6167, 34.7500],
        'Nampula' => [-15.1165, 39.2666], 'Nacala' => [-14.5500, 40.6833],
        'Xai-Xai' => [-25.0519, 33.6442], 'Chokwe' => [-24.5333, 32.9833],
        'Inhambane' => [-23.8650, 35.3833], 'Maxixe' => [-23.8597, 35.3472], 'Vilanculos' => [-22.0033, 35.3133],
        'Manica' => [-18.9333, 32.8667], 'Chimoio' => [-19.1167, 33.4833], 'Gurue' => [-15.4667, 36.9833],
        'Tete' => [-16.1565, 33.5867], 'Moatize' => [-16.1000, 33.7500], 'Angonia' => [-15.7000, 34.2000],
        'Quelimane' => [-17.8764, 36.8873], 'Mocuba' => [-17.0044, 36.9850],
        'Pemba' => [-12.9730, 40.5175], 'Montepuez' => [-13.1256, 39.1600], 'Palma' => [-10.7667, 40.4833],
        'Lichinga' => [-13.3128, 35.2406], 'Cuamba' => [-14.3886, 36.5372], 'Mandimba' => [-13.9833, 35.9500],
    ];
    $stmtLocal = $conn->prepare("INSERT INTO locais (endereco, cidade, provincia, latitude, longitude) VALUES (:end, :cid, :prov, :lat, :lng)");
    $localIdMap = [];
    $provMap = [
        'Maputo'=>'Maputo','Matola'=>'Maputo','Boane'=>'Maputo','Beira'=>'Sofala','Dondo'=>'Sofala',
        'Nampula'=>'Nampula','Nacala'=>'Nampula','Xai-Xai'=>'Gaza','Chokwe'=>'Gaza',
        'Inhambane'=>'Inhambane','Maxixe'=>'Inhambane','Vilanculos'=>'Inhambane',
        'Manica'=>'Manica','Chimoio'=>'Manica','Gurue'=>'Manica','Tete'=>'Tete','Moatize'=>'Tete','Angonia'=>'Tete',
        'Quelimane'=>'Zambezia','Mocuba'=>'Zambezia','Pemba'=>'Cabo Delgado','Montepuez'=>'Cabo Delgado','Palma'=>'Cabo Delgado',
        'Lichinga'=>'Niassa','Cuamba'=>'Niassa','Mandimba'=>'Niassa',
    ];
    foreach ($locaisNomes as $nome => $coords) {
        $stmtLocal->execute([':end'=>$nome.', '.$provMap[$nome], ':cid'=>$nome, ':prov'=>$provMap[$nome], ':lat'=>$coords[0], ':lng'=>$coords[1]]);
        $localIdMap[$nome] = $conn->lastInsertId();
    }
    echo "Locais inseridos: " . count($localIdMap) . "\n";

    // ---------- MISSOES ----------
    echo "--- Inserindo missoes...\n";

    $tiposVeiculo = ['caminhao','caminhao_tanque','basculante','carreta','camião_frigorífico'];
    $tiposCarga = ['geral','perigosa','refrigerada','granel','construcao','agricola','mineral'];
    $rotas = [
        ['Maputo','Beira'],['Maputo','Nampula'],['Matola','Xai-Xai'],['Maputo','Chimoio'],
        ['Beira','Nampula'],['Beira','Quelimane'],['Nampula','Pemba'],['Xai-Xai','Maxixe'],
        ['Chokwe','Inhambane'],['Manica','Beira'],['Chimoio','Maputo'],['Tete','Beira'],
        ['Moatize','Maputo'],['Quelimane','Nacala'],['Quelimane','Beira'],['Mocuba','Nampula'],
        ['Pemba','Nampula'],['Montepuez','Pemba'],['Lichinga','Nampula'],['Cuamba','Quelimane'],
        ['Nacala','Maputo'],['Maputo','Tete'],['Beira','Matola'],['Nampula','Beira'],
        ['Pemba','Lichinga'],['Chimoio','Tete'],['Xai-Xai','Chokwe'],['Inhambane','Vilanculos'],
        ['Dondo','Beira'],['Moatize','Tete'],['Palma','Pemba'],['Mandimba','Lichinga'],
        ['Matola','Boane'],['Gurue','Quelimane'],['Angonia','Tete'],['Nacala','Pemba'],
        ['Beira','Manica'],['Maputo','Vilanculos'],['Maxixe','Maputo'],['Lichinga','Cuamba'],
        ['Quelimane','Mocuba'],['Nampula','Montepuez'],['Tete','Moatize'],['Xai-Xai','Inhambane'],
        ['Chokwe','Maputo'],['Beira','Dondo'],['Pemba','Montepuez'],['Nampula','Nacala'],
        ['Maputo','Quelimane'],['Matola','Nampula'],['Chimoio','Manica'],['Vilanculos','Maxixe'],
        ['Tete','Maputo'],['Lichinga','Pemba'],['Cuamba','Nampula'],['Dondo','Quelimane'],
        ['Moatize','Chimoio'],['Inhambane','Beira'],['Xai-Xai','Beira'],['Nacala','Quelimane'],
        ['Pemba','Maputo'],['Montepuez','Nampula'],['Beira','Tete'],['Manica','Chimoio'],
        ['Maputo','Nacala'],['Matola','Beira'],['Quelimane','Maputo'],['Gurue','Nampula'],
    ];

    $distStatus = [
        'concluida'=>10, 'aberta'=>15, 'em_negociacao'=>7, 'aceita'=>5,
        'em_andamento'=>7, 'em_transito'=>5, 'em_entrega'=>3, 'cancelada'=>8
    ];

    $statusViagemMap = [
        'concluida'=>'finalizada','aberta'=>'nao_iniciada','em_negociacao'=>'nao_iniciada',
        'aceita'=>'nao_iniciada','em_andamento'=>'coleta','em_transito'=>'entrega',
        'em_entrega'=>'entrega','cancelada'=>'nao_iniciada'
    ];

    $stmtMissao = $conn->prepare("INSERT INTO missoes (empresa_id, caminhoneiro_id, titulo, descricao, origem, local_origem_id, destino, local_destino_id, tipo_veiculo, tipo_carga, peso_carga, valor, prazo_entrega, status, status_viagem, data_criacao, data_inicio, data_coleta, data_chegada) VALUES (:eid, :cid, :titulo, :desc, :origem, :loid, :destino, :ldid, :tv, :tc, :peso, :valor, :prazo, :status, :sv, :dc, :di, :dco, :dch)");

    $missaoIds = [];
    $rotaIdx = 0;
    $dataBase = strtotime('2025-11-01');
    foreach ($distStatus as $st => $qtd) {
        for ($i=0; $i<$qtd; $i++) {
            $empresa = $empresaIds[array_rand($empresaIds)];
            $rota = $rotas[$rotaIdx % count($rotas)];
            $rotaIdx++;
            $origem = $rota[0]; $destino = $rota[1];
            $loid = $localIdMap[$origem] ?? null;
            $ldid = $localIdMap[$destino] ?? null;
            $titulo = 'Transporte de ' . ['carga geral','materiais de construcao','cereais','combustivel','minério','cimento','madeira','produtos agricolas','mercadorias diversas','equipamentos industriais'][rand(0,9)] . ' - ' . $origem . ' para ' . $destino;
            $desc = 'Missao de transporte entre ' . $origem . ' e ' . $destino . '. Carga deve ser transportada com seguranca e no prazo combinado.';
            $tv = $tiposVeiculo[array_rand($tiposVeiculo)];
            $tc = $tiposCarga[array_rand($tiposCarga)];
            $peso = rand(50, 300) * 100;
            $valor = rand(50000, 500000);
            $prazo = date('Y-m-d', $dataBase + rand(1, 120) * 86400);
            $dc = date('Y-m-d H:i:s', $dataBase + rand(1, 90) * 86400);
            $camId = null;
            if (in_array($st, ['aceita','em_andamento','em_transito','em_entrega','concluida'])) {
                $camId = $caminhoneiroIds[array_rand($caminhoneiroIds)];
            }
            $di = $dco = $dch = null;
            if ($st === 'concluida') {
                $di = date('Y-m-d H:i:s', strtotime($dc) + rand(1,3)*86400);
                $dco = date('Y-m-d H:i:s', strtotime($di) + rand(1,5)*86400);
                $dch = date('Y-m-d H:i:s', strtotime($dco) + rand(1,7)*86400);
            } elseif (in_array($st, ['em_andamento','em_transito','em_entrega'])) {
                $di = date('Y-m-d H:i:s', strtotime($dc) + rand(1,3)*86400);
                if (in_array($st, ['em_transito','em_entrega'])) {
                    $dco = date('Y-m-d H:i:s', strtotime($di) + rand(1,3)*86400);
                }
            }
            $stmtMissao->execute([
                ':eid'=>$empresa, ':cid'=>$camId, ':titulo'=>$titulo, ':desc'=>$desc,
                ':origem'=>$origem, ':loid'=>$loid, ':destino'=>$destino, ':ldid'=>$ldid,
                ':tv'=>$tv, ':tc'=>$tc, ':peso'=>$peso, ':valor'=>$valor, ':prazo'=>$prazo,
                ':status'=>$st, ':sv'=>$statusViagemMap[$st], ':dc'=>$dc,
                ':di'=>$di, ':dco'=>$dco, ':dch'=>$dch
            ]);
            $mid = $conn->lastInsertId();
            $missaoIds[] = ['id'=>$mid, 'status'=>$st, 'empresa'=>$empresa, 'caminhoneiro'=>$camId];
        }
    }
    echo "Missoes inseridas: " . count($missaoIds) . "\n";

    // ---------- PROPOSTAS ----------
    echo "--- Inserindo propostas...\n";
    $stmtProp = $conn->prepare("INSERT INTO propostas (missao_id, caminhoneiro_id, valor, observacoes, status) VALUES (:mid, :cid, :val, :obs, :st)");
    foreach ($missaoIds as $m) {
        if ($m['status'] === 'aberta') {
            // 1-3 propostas pendentes
            $n = rand(1,3);
            shuffle($caminhoneiroIds);
            for ($p=0; $p<$n; $p++) {
                $val = rand(40000, 600000);
                $obs = ['Estou interessado na missao','Disponivel para iniciar ja','Tenho experiencia nesta rota','Posso fazer melhor preco','Veiculo adequado para a carga'][rand(0,4)];
                $stmtProp->execute([':mid'=>$m['id'], ':cid'=>$caminhoneiroIds[$p], ':val'=>$val, ':obs'=>$obs, ':st'=>'pendente']);
            }
        } elseif (in_array($m['status'], ['aceita','em_andamento','em_transito','em_entrega','concluida'])) {
            // Proposta aceita
            $val = rand(40000, 600000);
            $stmtProp->execute([':mid'=>$m['id'], ':cid'=>$m['caminhoneiro'], ':val'=>$val, ':obs'=>'Proposta aceita pelo contratante', ':st'=>'aceita']);
            // Talvez 1-2 rejeitadas
            if (rand(0,1)) {
                $outro = array_filter($caminhoneiroIds, fn($c) => $c != $m['caminhoneiro']);
                $stmtProp->execute([':mid'=>$m['id'], ':cid'=>array_values($outro)[0], ':val'=>$val+rand(10000,50000), ':obs'=>'Fora do prazo', ':st'=>'rejeitada']);
            }
        } elseif ($m['status'] === 'em_negociacao') {
            $n = rand(2,4);
            shuffle($caminhoneiroIds);
            for ($p=0; $p<$n; $p++) {
                $val = rand(40000, 600000);
                $stmtProp->execute([':mid'=>$m['id'], ':cid'=>$caminhoneiroIds[$p], ':val'=>$val, ':obs'=>'Aguardando negociacao', ':st'=>'pendente']);
            }
        }
    }
    echo "Propostas inseridas.\n";

    // ---------- CONVERSAS E MENSAGENS ----------
    echo "--- Inserindo conversas e mensagens...\n";
    $stmtConv = $conn->prepare("INSERT INTO conversas (usuario1_id, usuario2_id, missao_id, nao_lidas) VALUES (:u1, :u2, :mid, 0)");
    $stmtMsg = $conn->prepare("INSERT INTO mensagens (remetente_id, destinatario_id, missao_id, mensagem, data_envio, lida) VALUES (:rem, :dest, :mid, :msg, :dt, :lida)");

    $frases = [
        'Bom dia, estou interessado na missao.','Quando podemos iniciar?','Qual o tipo de carga exactamente?',
        'Preciso de mais detalhes sobre o destino.','O preco pode ser negociado?','Veiculo pronto para carga.',
        'Estou a caminho do local de recolha.','Carga carregada com sucesso.','Vou parar para descanso em 2 horas.',
        'Problema mecanico resolvido, retomando viagem.','Cheguei ao destino.','Descarga concluida.',
        'Obrigado pela oportunidade.','Avaliacao positiva enviada.','Quando sera o pagamento?',
        'Documentos da carga enviados por email.','Pode confirmar o peso exacto?','Estou no ponto de encontro.',
        'A carga esta bem acondicionada?','Qual a melhor rota para evitar trovoadas?','Combustivel abastecido.',
        'Parada para almoco em Dondo.','Tudo a correr bem.','Ciente do atraso, chegarei amanha.',
        'Pode indicar o contacto do responsavel?','Confirmo disponibilidade para esta data.',
        'Qual o codigo da missao?','Fotos da carga ja foram tiradas.','Recebi o pagamento, obrigado.'
    ];

    $convIds = [];
    $cMissoesComChat = array_slice($missaoIds, 0, 35); // 35 missoes com chat
    foreach ($cMissoesComChat as $m) {
        $emp = $m['empresa'];
        $cam = $m['caminhoneiro'];
        if (!$cam) {
            $cam = $caminhoneiroIds[array_rand($caminhoneiroIds)];
        }
        $u1 = min($emp, $cam); $u2 = max($emp, $cam);
        $stmtConv->execute([':u1'=>$u1, ':u2'=>$u2, ':mid'=>$m['id']]);
        $convId = $conn->lastInsertId();
        $convIds[] = ['id'=>$convId, 'u1'=>$u1, 'u2'=>$u2, 'mid'=>$m['id']];
    }

    // Conversas sem missao (gerais)
    for ($c=0; $c<10; $c++) {
        $u1 = $empresaIds[array_rand($empresaIds)];
        $u2 = $caminhoneiroIds[array_rand($caminhoneiroIds)];
        $a = min($u1,$u2); $b = max($u1,$u2);
        $stmtConv->execute([':u1'=>$a, ':u2'=>$b, ':mid'=>null]);
        $convId = $conn->lastInsertId();
        $convIds[] = ['id'=>$convId, 'u1'=>$a, 'u2'=>$b, 'mid'=>null];
    }

    foreach ($convIds as $c) {
        $numMsgs = rand(6, 18);
        $dt = strtotime('2025-11-01') + rand(1, 60)*86400;
        for ($m=0; $m<$numMsgs; $m++) {
            $rem = ($m % 2 == 0) ? $c['u1'] : $c['u2'];
            $dest = ($m % 2 == 0) ? $c['u2'] : $c['u1'];
            $msg = $frases[array_rand($frases)];
            $stmtMsg->execute([':rem'=>$rem, ':dest'=>$dest, ':mid'=>$c['mid'], ':msg'=>$msg, ':dt'=>date('Y-m-d H:i:s', $dt + $m*rand(300, 7200)), ':lida'=>1]);
        }
    }
    echo "Conversas inseridas: " . count($convIds) . ", com media de 12 mensagens cada.\n";

    // ---------- AVALIACOES ----------
    echo "--- Inserindo avaliacoes...\n";
    $stmtAval = $conn->prepare("INSERT INTO avaliacoes (missao_id, avaliador_id, avaliado_id, nota, comentario) VALUES (:mid, :av, :avd, :nota, :com)");
    $comentarios = [
        'Excelente servico, muito pontual.','Motorista muito profissional.','Carga chegou sem danos.',
        'Recomendo fortemente.','Comunicacao clara durante toda a viagem.','Veiculo em boas condicoes.',
        'Poderia ter sido mais rapido.','Bom preco.','Precisa melhorar a documentacao.','Servico razoavel.',
        'Empresa muito organizada.','Pagamento rapido e justo.','Corrida tranquila.','Recomendo.','Otimo trabalho.'
    ];
    $missoesConcluidas = array_filter($missaoIds, fn($m) => $m['status'] === 'concluida');
    foreach ($missoesConcluidas as $m) {
        // Empresa avalia caminhoneiro
        $nota = rand(3,5);
        $stmtAval->execute([':mid'=>$m['id'], ':av'=>$m['empresa'], ':avd'=>$m['caminhoneiro'], ':nota'=>$nota, ':com'=>$comentarios[array_rand($comentarios)]]);
        // Caminhoneiro avalia empresa
        $nota = rand(3,5);
        $stmtAval->execute([':mid'=>$m['id'], ':av'=>$m['caminhoneiro'], ':avd'=>$m['empresa'], ':nota'=>$nota, ':com'=>$comentarios[array_rand($comentarios)]]);
    }
    echo "Avaliacoes inseridas.\n";

    // ---------- NOTIFICACOES ----------
    echo "--- Inserindo notificacoes...\n";
    $usuariosNotif = array_merge($empresaIds, $caminhoneiroIds, $transpIds);
    $stmtNotif = $conn->prepare("INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, lida) VALUES (:uid, :tipo, :tit, :msg, :link, :lida)");
    $tiposNotif = ['missao','proposta','proposta_aceita','mensagem','avaliacao','sistema'];
    foreach ($usuariosNotif as $uid) {
        $nNotif = rand(2, 8);
        for ($n=0; $n<$nNotif; $n++) {
            $tipo = $tiposNotif[array_rand($tiposNotif)];
            $tit = match($tipo) {
                'missao'=>'Nova missao disponivel','proposta'=>'Nova proposta recebida','proposta_aceita'=>'Proposta aceita',
                'mensagem'=>'Nova mensagem','avaliacao'=>'Nova avaliacao recebida','sistema'=>'Atualizacao do sistema'
            };
            $msg = match($tipo) {
                'missao'=>'Uma nova missao foi publicada na sua regiao.','proposta'=>'Um caminhoneiro enviou uma proposta.',
                'proposta_aceita'=>'Sua proposta foi aceita pelo contratante.','mensagem'=>'Voce recebeu uma nova mensagem.',
                'avaliacao'=>'Foi avaliado apos a missao.','sistema'=>'Sistema atualizado com novas funcionalidades.'
            };
            $stmtNotif->execute([':uid'=>$uid, ':tipo'=>$tipo, ':tit'=>$tit, ':msg'=>$msg, ':link'=>null, ':lida'=>rand(0,1)]);
        }
    }
    echo "Notificacoes inseridas.\n";

    // ---------- REGISTROS DE VIAGEM ----------
    echo "--- Inserindo registros de viagem...\n";
    $stmtReg = $conn->prepare("INSERT INTO registros_viagem (missao_id, tipo, descricao) VALUES (:mid, :tipo, :desc)");
    $missoesAtivas = array_filter($missaoIds, fn($m) => in_array($m['status'], ['concluida','em_andamento','em_transito','em_entrega','aceita']));
    foreach ($missoesAtivas as $m) {
        $regs = [
            ['criacao','Missao criada pelo contratante'],
            ['inicio','Viagem iniciada pelo motorista'],
            ['coleta','Carga recolhida no local de origem'],
        ];
        if (in_array($m['status'], ['em_transito','em_entrega','concluida'])) {
            $regs[] = ['transito','Em transito para o destino'];
        }
        if (in_array($m['status'], ['em_entrega','concluida'])) {
            $regs[] = ['chegada','Chegada ao destino'];
        }
        if ($m['status'] === 'concluida') {
            $regs[] = ['entrega','Carga entregue com sucesso'];
            $regs[] = ['finalizacao','Missao concluida e avaliada'];
        }
        foreach ($regs as $r) {
            $stmtReg->execute([':mid'=>$m['id'], ':tipo'=>$r[0], ':desc'=>$r[1]]);
        }
    }
    echo "Registros de viagem inseridos.\n";

    // ---------- HISTORICO LOCALIZACAO ----------
    echo "--- Inserindo historico de localizacao...\n";
    $stmtHist = $conn->prepare("INSERT INTO historico_localizacao (usuario_id, latitude, longitude) VALUES (:uid, :lat, :lng)");
    foreach ($caminhoneiroIds as $cid) {
        for ($h=0; $h<5; $h++) {
            $lat = -25 + (rand(0, 1500) / 100);
            $lng = 32 + (rand(0, 800) / 100);
            $stmtHist->execute([':uid'=>$cid, ':lat'=>$lat, ':lng'=>$lng]);
        }
    }
    echo "Historico de localizacao inserido.\n";

    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "\n=== SEED CONCLUIDO COM SUCESSO ===\n";
    echo "Resumo:\n";
    echo "- Empresas: " . count($empresaIds) . "\n";
    echo "- Caminhoneiros: " . count($caminhoneiroIds) . "\n";
    echo "- Transportadores: " . count($transpIds) . "\n";
    echo "- Locais: " . count($localIdMap) . "\n";
    echo "- Missoes: " . count($missaoIds) . "\n";
    echo "- Conversas: " . count($convIds) . "\n";
    echo "</pre>";

} catch (Throwable $e) {
    echo "<pre>ERRO: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "</pre>";
}
