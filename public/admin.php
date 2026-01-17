<?php
/*
 * PAINEL ADMINISTRATIVO TOP GEAR BOT
 */

// ATIVAR LOGS DE ERRO PARA DEBUG DO ADMIN
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ============================================================
// 1. CONFIGURAÇÃO E AUTENTICAÇÃO
// ============================================================

// Definição de Diretórios 
define('BASE_DIR', __DIR__);
if (!defined('DATA_DIR')) define('DATA_DIR', BASE_DIR . '/../storage/json');
if (!defined('LOG_DIR'))  define('LOG_DIR',  BASE_DIR . '/../storage/logs');
if (!defined('BACKUP_DIR')) define('BACKUP_DIR', BASE_DIR . '/../storage/backups');

// Cria diretórios se não existirem
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!is_dir(LOG_DIR))  mkdir(LOG_DIR, 0755, true);
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

// Arquivos de Dados
if (!defined('FILE_PILOTS'))    define('FILE_PILOTS', DATA_DIR . '/pilots.json');
if (!defined('FILE_MATCHES'))   define('FILE_MATCHES', DATA_DIR . '/matches.json');
if (!defined('FILE_SCHEDULES')) define('FILE_SCHEDULES', DATA_DIR . '/schedules.json');
if (!defined('FILE_AUDIT'))     define('FILE_AUDIT', DATA_DIR . '/auditSchedules.json');
if (!defined('FILE_LOG'))       define('FILE_LOG', LOG_DIR . '/botMain.log');

// Configurações Básicas
date_default_timezone_set('America/Sao_Paulo');

// --- CORREÇÃO DE CAMINHOS (FIX PATHS) ---
// O admin.php está em /public, então precisamos subir um nível (../) para acessar /src e /config

// Carregar Configuração de Ambiente
if (file_exists(__DIR__ . '/../config/environment.php')) {
    include_once __DIR__ . '/../config/environment.php';
} elseif (file_exists(__DIR__ . '/config/environment.php')) {
    include_once __DIR__ . '/config/environment.php'; // Fallback
}

// Carregar LogHandler
if (file_exists(__DIR__ . '/../src/Utils/LogHandler.php')) {
    include_once __DIR__ . '/../src/Utils/LogHandler.php';
} elseif (file_exists(__DIR__ . '/src/Utils/LogHandler.php')) {
    include_once __DIR__ . '/src/Utils/LogHandler.php'; // Fallback
}

// Carregar BackupManager (CRÍTICO PARA O ERRO RELATADO)
if (file_exists(__DIR__ . '/../src/Utils/BackupManager.php')) {
    include_once __DIR__ . '/../src/Utils/BackupManager.php';
} elseif (file_exists(__DIR__ . '/src/Utils/BackupManager.php')) {
    include_once __DIR__ . '/src/Utils/BackupManager.php'; // Fallback
}

// Carregar Auth
if (file_exists(__DIR__ . '/../src/Auth/AdminAuth.php')) {
    include_once __DIR__ . '/../src/Auth/AdminAuth.php';
} elseif (file_exists(__DIR__ . '/src/Auth/AdminAuth.php')) {
    include_once __DIR__ . '/src/Auth/AdminAuth.php'; // Fallback
}

// ============================================================
// CARREGAR VARIÁVEIS DE AMBIENTE
// ============================================================

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, ' "\'');
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// Iniciar Sessão
if (session_status() === PHP_SESSION_NONE) session_start();

$loginError = '';
$useAdvancedAuth = class_exists('AdminAuth');

// --- PROCESSAMENTO DE LOGIN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $password = $_POST['admin_password'] ?? '';
    $adminPass = $_ENV['ADMIN_PASSWORD'];

	if ($adminPass === null) {
		$loginError = 'Senha de administrador não configurada.';
	} elseif ($useAdvancedAuth) {
        try {
            AdminAuth::login($password);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Exception $e) {
            $loginError = $e->getMessage();
        }
    } else {
        if (hash_equals($adminPass, $password)) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $loginError = 'Senha incorreta.';
        }
    }
}

// --- LOGOUT ---
if (isset($_GET['logout'])) {
    if ($useAdvancedAuth) AdminAuth::logout();
    unset($_SESSION['admin_logged_in']);
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// --- VERIFICAÇÃO DE ACESSO ---
$isAuth = $useAdvancedAuth ? AdminAuth::check() : ($_SESSION['admin_logged_in'] ?? false);

if (!$isAuth) {
    // TELA DE LOGIN SIMPLIFICADA
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login Admin - Top Gear</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-900 flex items-center justify-center h-screen">
        <div class="bg-white p-8 rounded-lg shadow-lg w-96">
            <h1 class="text-2xl font-bold text-center mb-6 text-gray-800">🏎️ Admin Login</h1>
            <?php if($loginError): ?>
                <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm border-l-4 border-red-500"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="admin_password" placeholder="Senha" class="w-full border p-3 rounded mb-4 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <button type="submit" name="admin_login" class="w-full bg-indigo-600 text-white p-3 rounded font-bold hover:bg-indigo-700 transition">Entrar</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 3. LÓGICA DO DASHBOARD (Listas de apoio)
$torneios = [
    "T1 - Torneio de Verão: Dakar Series",
    "T2 - American LeMans Series",
    "T3 - La Liga - Série Ouro",
    "T4 - La Liga - Série Prata",
    "T5 - La Liga - Série Bronze",
    "T6 - TGC Pole Position",
    "T7 - Torneio de Outono: Acropolis Cup",
    "T8 - F1 Academy",
    "T9 - Copa TGC",
    "T10 - TGC Numerado",
    "T11 - TGC Prototype Challenge",
    "T12 - Torneio de Inverno: Arctic Rally",
    "T13 - La Liga - Série Ouro",
    "T14 - La Liga - Série Prata",
    "T15 - La Liga - Série Bronze",
    "T16 - Torneio de Primavera: Targa Florio",
    "T17 - Champions Cup",
    "Mundial de Pilotos"
];

$fases = [
    "Fase de Grupos", 
    "Eliminatórias", 
    "Oitavas de Final", 
    "Quartas de Final", 
    "Semifinal", 
    "Final", 
    "3º Lugar"
];

$paisesTopGear = ["USA", "SAM", "JAP", "GER", "SCN", "FRA", "ITA", "UKG"];
$pistas_disponiveis = [
    1 => "01 USA - Las Vegas", 2 => "02 USA - Los Angeles", 3 => "03 USA - New York", 4 => "04 USA - San Francisco",
    5 => "05 SAM - Rio", 6 => "06 SAM - Machu Picchu", 7 => "07 SAM - Chichen Itza", 8 => "08 SAM - Rain Forest",
    9 => "09 JAP - Tokyo", 10 => "10 JAP - Hiroshima", 11 => "11 JAP - Yokohama", 12 => "12 JAP - Kyoto",
    13 => "13 GER - Munich", 14 => "14 GER - Cologne", 15 => "15 GER - Black Forest", 16 => "16 GER - Frankfurt",
    17 => "17 SCN - Stockholm", 18 => "18 SCN - Copenhagen", 19 => "19 SCN - Helsinki", 20 => "20 SCN - Oslo",
    21 => "21 FRA - Paris", 22 => "22 FRA - Nice", 23 => "23 FRA - Bordeaux", 24 => "24 FRA - Monaco",
    25 => "25 ITA - Pisa", 26 => "26 ITA - Rome", 27 => "27 ITA - Sicily", 28 => "28 ITA - Florence",
    29 => "29 UK - London", 30 => "30 UK - Sheffield", 31 => "31 UK - Loch Ness", 32 => "32 UK - Stonehenge"
];

// Helpers Seguros
function getJson($file) { return file_exists($file) ? json_decode(file_get_contents($file), true) : []; }
function saveJson($file, $data) { file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX); }
function getNextId($array) { return empty($array) ? 1 : max(array_column($array, 'id')) + 1; }

// Helper para ler as últimas linhas do log
function tailLog($lines = 50) {
    if (!file_exists(FILE_LOG)) return "Arquivo de log vazio ou inexistente.";
    $file = file(FILE_LOG);
    $total = count($file);
    $start = max(0, $total - $lines);
    return implode("", array_slice($file, $start));
}

// Helper para encontrar agendamento único
function getMatchSchedule($matchId, $allSchedules) {
    if (!is_array($allSchedules)) return null;
    foreach ($allSchedules as $s) {
        if (isset($s['match_id']) && $s['match_id'] == $matchId) return $s;
    }
    return null;
}

// Helper para nome de exibição
function getPilotNameDisplay($id, $pilotsMap) {
    $p = $pilotsMap[$id] ?? null;
    if (!$p) return '??';
    if (!empty($p['nickname_TGC'])) return $p['nickname_TGC']; 
    return $p['nome'];
}

// Helper para formatar bytes
function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow); 
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}

// Helper para pegar tamanho do diretório de backups
function getBackupDirSize() {
    $size = 0;
    foreach (glob(BACKUP_DIR . '/*/*') as $file) {
        $size += filesize($file);
    }
    return formatBytes($size);
}

// Helper para logs do admin (Compatibilidade com BackupManager)
function adminLog($msg) {
    $entry = "[" . date('Y-m-d H:i:s') . "] ADMIN: $msg" . PHP_EOL;
    file_put_contents(FILE_LOG, $entry, FILE_APPEND);
}

// ============================================================
// 2. PROCESSAMENTO DE AÇÕES
// ============================================================

// INICIALIZAÇÃO DA VARIÁVEL DE FEEDBACK
$msgFeedback = '';

// --- AÇÃO: UPLOAD PARTIDAS (MASSIVO) ---
if (isset($_FILES['matches_file']) && $_FILES['matches_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['matches_file']['tmp_name'];
    $content = file_get_contents($fileTmpPath);
    $newMatches = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($newMatches)) {
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro: Arquivo JSON inválido ou corrompido.</div>";
    } else {
        $currentMatches = getJson(FILE_MATCHES);
        $currentIds = array_column($currentMatches, 'id');
        $newIds = [];
        $duplicates = [];
        
        // Validação de Integridade e Duplicidade
        foreach ($newMatches as $m) {
            // Verifica se tem ID
            if (!isset($m['id'])) {
                $duplicates[] = "Item sem ID";
                continue;
            }
            // Verifica duplicidade com o banco atual
            if (in_array($m['id'], $currentIds)) {
                $duplicates[] = "#" . $m['id'] . " (Já existe no sistema)";
            }
            // Verifica duplicidade dentro do próprio arquivo
            if (in_array($m['id'], $newIds)) {
                 $duplicates[] = "#" . $m['id'] . " (Duplicado no arquivo enviado)";
            }
            $newIds[] = $m['id'];
        }

        if (!empty($duplicates)) {
            // Rejeita tudo se houver conflito
            $listaErros = implode(', ', array_unique($duplicates));
            $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>🚫 <b>Upload Rejeitado!</b><br>Foram encontrados IDs duplicados:<br><span class='text-xs'>$listaErros</span></div>";
        } else {
            // Sucesso: Merge e Salvar
            $merged = array_merge($currentMatches, $newMatches);
            
            // Ordenar por ID para manter organização (opcional, mas recomendado)
            usort($merged, function($a, $b) { return $a['id'] - $b['id']; });
            
            saveJson(FILE_MATCHES, $merged);
            adminLog("Upload massivo de partidas realizado: " . count($newMatches) . " novas partidas importadas.");
            $msgFeedback = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>✅ <b>Sucesso!</b> " . count($newMatches) . " partidas foram importadas.</div>";
        }
    }
}

// --- AÇÃO: BAIXAR LOGS ---
if (isset($_POST['baixar_logs'])) {
    if (file_exists(FILE_LOG)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="botMain_'.date('Y-m-d_Hi').'.log"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize(FILE_LOG));
        readfile(FILE_LOG);
        exit;
    }
}

// --- AÇÃO: BAIXAR BACKUP (ZIP DINÂMICO DA PASTA) ---
if (isset($_POST['baixar_backup'])) {
    $timestamp = $_POST['timestamp'] ?? '';
    // Segurança: validar formato do timestamp
    if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $timestamp)) {
        $targetDir = BACKUP_DIR . '/' . $timestamp;
        
        if (is_dir($targetDir)) {
            $zipFile = sys_get_temp_dir() . "/backup_{$timestamp}.zip";
            $zip = new ZipArchive();
            
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                $files = glob($targetDir . '/*.backup');
                foreach ($files as $file) {
                    $localName = str_replace('.backup', '', basename($file));
                    $zip->addFile($file, $localName);
                }
                $zip->close();
                
                if (file_exists($zipFile)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="backup_'.$timestamp.'.zip"');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($zipFile));
                    readfile($zipFile);
                    unlink($zipFile); // Limpar temp
                    exit;
                }
            }
        }
    }
}

// --- ADMIN: CRIAR BACKUP (SNAPSHOT) ---
if (isset($_POST['criar_backup'])) {
    if (class_exists('BackupManager')) {
        $res = BackupManager::createBackupSnapshot($_SESSION['admin_user'] ?? 'Admin');
        if ($res['success']) {
            adminLog("Backup manual criado: " . $res['timestamp']);
            $msgFeedback = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>💾 <b>Backup Criado!</b> Pasta: {$res['timestamp']}</div>";
        } else {
            $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro ao criar backup: {$res['error']}</div>";
        }
    } else {
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Classe BackupManager não encontrada.</div>";
    }
}

// --- ADMIN: EXCLUIR BACKUP ---
if (isset($_POST['excluir_backup'])) {
    $timestamp = $_POST['timestamp'] ?? '';
    if (class_exists('BackupManager')) {
        if (BackupManager::deleteBackup($timestamp)) {
            adminLog("Backup excluído: $timestamp");
            $msgFeedback = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4'>🗑️ Backup <b>$timestamp</b> excluído.</div>";
        } else {
             $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Falha ao excluir backup.</div>";
        }
    }
}

// --- ADMIN: LIMPAR TUDO (RESET TEMPORADA) ---
if (isset($_POST['limpar_partidas'])) {
    if (class_exists('BackupManager')) {
        $res = BackupManager::rotateSeasonFull($_SESSION['admin_user'] ?? 'Admin');
        if ($res['success']) {
            adminLog("Temporada resetada e backup criado: " . $res['timestamp']);
            $msgFeedback = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>🔄 <b>Temporada Resetada!</b> Backup de segurança em: {$res['timestamp']}</div>";
        } else {
            $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro ao resetar: {$res['error']}</div>";
        }
    } else {
        // Fallback manual se a classe não existir (segurança)
        saveJson(FILE_MATCHES, []);
        saveJson(FILE_SCHEDULES, []);
        saveJson(FILE_AUDIT, []);
        adminLog("Resetou a temporada (Fallback manual).");
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>🗑️ <b>Limpeza Completa!</b> Temporada resetada.</div>";
    }
}

// --- ADMIN: LIMPAR LOGS ---
if (isset($_POST['limpar_logs'])) {
    file_put_contents(FILE_LOG, "[" . date('Y-m-d H:i:s') . "] Log reiniciado pelo Admin." . PHP_EOL);
    $msgFeedback = "<div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4'>📄 <b>Logs Limpos!</b> Arquivo de log reiniciado.</div>";
}

// --- ADMIN: ARQUIVAR LOGS ---
if (isset($_POST['arquivar_logs'])) {
    if (file_exists(FILE_LOG)) {
        $timestamp = date('Y-m-d_H-i-s');
        $archiveName = LOG_DIR . "/archive_botMain_{$timestamp}.log";
        
        if (rename(FILE_LOG, $archiveName)) {
            file_put_contents(FILE_LOG, "[" . date('Y-m-d H:i:s') . "] Novo arquivo de log iniciado arquivamento." . PHP_EOL);
            $msgFeedback = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4'>📦 <b>Log Arquivado!</b> Salvo como: " . basename($archiveName) . "</div>";
        } else {
            $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro ao arquivar log.</div>";
        }
    }
}

// --- ADMIN: GERAR PARTIDAS ---
if (isset($_POST['gerar_partidas'])) {
    $pilots = getJson(FILE_PILOTS);
    $matches = getJson(FILE_MATCHES);
    
    // Processamento da Ordem (P1/P2)
    $order = !empty($_POST['pilot_order']) ? explode(',', $_POST['pilot_order']) : [];

    $selTournament = $_POST['tournament'] ?? '';
    $selPhase = $_POST['phase'] ?? '';
    $selGroupNum = $_POST['group_num'] ?? '';
    $drawType = $_POST['draw_type'] ?? '';
    $dateInput = $_POST['deadline_date'] ?? ''; 
    $prazoFinal = $dateInput ? $dateInput . " 23:59:59" : date('Y-m-d 23:59:59', strtotime('+7 days'));
    $groupName = ($selPhase === "Fase de Grupos") ? "Grupo $selGroupNum" : $selPhase;

    $localArray = []; 
    
    // Lógica Atualizada: Usa a ordem de seleção dos hidden inputs
    if ($drawType === 'paises') {
        $paisesOrderStr = $_POST['paises_order'] ?? '';
        if ($paisesOrderStr) {
            $localArray = explode(',', $paisesOrderStr);
        }
    } elseif ($drawType === 'pistas') {
        $pistasOrderStr = $_POST['pistas_order'] ?? '';
        if ($pistasOrderStr) {
            $ids = explode(',', $pistasOrderStr);
            foreach($ids as $id) {
                if (isset($pistas_disponiveis[$id])) {
                    $localArray[] = $pistas_disponiveis[$id];
                }
            }
        }
    }
    
    if (count($order) < 2) {
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Selecione pelo menos 2 pilotos.</div>";
    } else {
        $novas = 0;
        
        // Se selecionou apenas 2, respeita quem foi clicado primeiro (P1 vs P2)
        if (count($order) == 2) {
             $matches[] = [
                'id' => getNextId($matches),
                'player_1_id' => intval($order[0]), // Player 1 (Primeiro clicado)
                'player_2_id' => intval($order[1]), // Player 2 (Segundo clicado)
                'group_name' => $groupName,
                'tournament' => $selTournament,
                'phase' => $selPhase,
                'local_track' => $localArray,
                'deadline' => $prazoFinal,
                'status' => 'PENDENTE',
                'winner_id' => null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $novas++;
        } else {
            // Se selecionou mais de 2, gera todos contra todos baseado na lista
            for ($i = 0; $i < count($order); $i++) {
                for ($j = $i + 1; $j < count($order); $j++) {
                    $matches[] = [
                        'id' => getNextId($matches),
                        'player_1_id' => intval($order[$i]),
                        'player_2_id' => intval($order[$j]),
                        'group_name' => $groupName,
                        'tournament' => $selTournament,
                        'phase' => $selPhase,
                        'local_track' => $localArray,
                        'deadline' => $prazoFinal,
                        'status' => 'PENDENTE',
                        'winner_id' => null,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $novas++;
                }
            }
        }

        if ($novas > 0) {
            saveJson(FILE_MATCHES, $matches);
            adminLog("Gerou $novas novas partidas para $selTournament - $groupName.");
            $msgFeedback = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'><b>Sucesso!</b> $novas partidas geradas.</div>";
        }
    }
}

// CARREGAR DADOS
$pilots = getJson(FILE_PILOTS);
$matches = getJson(FILE_MATCHES);
$schedules = getJson(FILE_SCHEDULES);
$logTail = tailLog(100); 

// Carregar Backups (Usando BackupManager)
$backupList = class_exists('BackupManager') ? BackupManager::listBackups() : [];

$pilotsMap = []; 
if (is_array($pilots)) {
    foreach ($pilots as $p) $pilotsMap[$p['id']] = $p;
}

$viewMatches = [];
if (is_array($matches)) {
    foreach ($matches as $m) {
        $t = $m['tournament'] ?? 'Outros';
        $f = $m['phase'] ?? 'Geral';
        $viewMatches[$t][$f][] = $m;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Gear Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Lógica de seleção P1 e P2 (Pilotos)
        let selectionOrder = [];
        function handleSelection(cb) {
            const id = cb.value;
            if (cb.checked) {
                selectionOrder.push(id);
            } else {
                selectionOrder = selectionOrder.filter(x => x !== id);
            }
            document.getElementById('pilot_order').value = selectionOrder.join(',');
            
            // Atualizar labels visuais
            document.querySelectorAll('.p-label').forEach(el => el.innerText = '');
            if (selectionOrder[0]) document.getElementById('label-'+selectionOrder[0]).innerText = '(P1)';
            if (selectionOrder[1]) document.getElementById('label-'+selectionOrder[1]).innerText = '(P2)';
        }

        // Lógica de Seleção de Países (Ordenada)
        let paisesOrder = [];
        function handlePaisClick(cb) {
            const val = cb.value;
            if(cb.checked) {
                paisesOrder.push(val);
            } else {
                paisesOrder = paisesOrder.filter(x => x !== val);
            }
            document.getElementById('paises_order').value = paisesOrder.join(',');
            updateBadges('pais', paisesOrder);
        }

        // Lógica de Seleção de Pistas (Ordenada)
        let pistasOrder = [];
        function handlePistaClick(cb) {
            const val = cb.value;
            if(cb.checked) {
                pistasOrder.push(val);
            } else {
                pistasOrder = pistasOrder.filter(x => x !== val);
            }
            document.getElementById('pistas_order').value = pistasOrder.join(',');
            updateBadges('pista', pistasOrder);
        }

        // Atualiza os emblemas de ordem (1, 2, 3...)
        function updateBadges(type, orderArr) {
            document.querySelectorAll(`.${type}-badge`).forEach(el => {
                el.innerText = '';
                el.parentElement.classList.remove('ring-2', 'ring-indigo-600', 'bg-indigo-50');
            });

            orderArr.forEach((val, index) => {
                // Escapar IDs que podem ter espaços (pistas têm IDs numéricos simples, países strings)
                // Usando input[value="..."] selector para achar o pai
                const input = document.querySelector(`input[name="${type === 'pais' ? 'paises' : 'pistas'}_selected[]"][value="${val}"]`);
                if(input) {
                    const label = input.closest('label');
                    const badge = label.querySelector(`.${type}-badge`);
                    if(badge) badge.innerText = (index + 1);
                    label.classList.add('ring-2', 'ring-indigo-600', 'bg-indigo-50');
                }
            });
        }

        function toggleGroupSelect(val) { document.getElementById('group_container').classList.toggle('hidden', val !== 'Fase de Grupos'); }
        
        function switchDrawType(val) {
            // Esconder todos
            document.getElementById('container_paises').classList.add('hidden');
            document.getElementById('container_pistas').classList.add('hidden');
            
            // Setar radio
            document.getElementById('draw_type_' + (val || 'livre')).checked = true;

            if (val === 'paises') document.getElementById('container_paises').classList.remove('hidden');
            if (val === 'pistas') document.getElementById('container_pistas').classList.remove('hidden');
        }

        // Modal de Logs e Backups
        function openLogModal() { document.getElementById('logModal').classList.remove('hidden'); }
        function closeLogModal() { document.getElementById('logModal').classList.add('hidden'); }
        function openBackupModal() { document.getElementById('backupModal').classList.remove('hidden'); }
        function closeBackupModal() { document.getElementById('backupModal').classList.add('hidden'); }
    </script>
</head>
<body class="bg-gray-100 text-gray-800 font-sans pb-20">

    <!-- NAVBAR -->
    <nav class="bg-gray-900 text-white shadow-lg mb-8">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <span class="text-xl font-bold tracking-wider">🏎️ Top Gear <span class="text-red-500">ADMIN</span></span>
            <div class="flex items-center gap-4">
                <span class="text-sm text-gray-400 hidden md:inline">Logado como Admin</span>
                <a href="?logout=true" class="text-xs bg-red-600 px-3 py-1 rounded hover:bg-red-700">Sair</a>
            </div>
        </div>
    </nav>

    <div class="max-w-[95%] mx-auto px-2">
        <?= $msgFeedback ?>

        <!-- FORMULÁRIO DE GERAÇÃO -->
        <form method="POST" class="bg-white shadow-xl rounded-lg overflow-hidden mb-10 border border-gray-200">
            <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100 flex justify-between items-center">
                <h2 class="text-lg font-bold text-indigo-900">⚙️ Gerador de Partidas</h2>
                <span class="text-xs text-indigo-400 uppercase font-bold tracking-widest">Configuração</span>
            </div>
            
            <!-- Campos Ocultos para Ordem de Seleção -->
            <input type="hidden" name="pilot_order" id="pilot_order" value="">
            <input type="hidden" name="paises_order" id="paises_order" value="">
            <input type="hidden" name="pistas_order" id="pistas_order" value="">

            <div class="grid grid-cols-1 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x divide-gray-200">
                <!-- 1. TORNEIO -->
                <div class="p-5">
                    <label class="block text-xs font-bold text-gray-500 mb-2 uppercase">1. Torneio</label>
                    <select name="tournament" class="block w-full border-gray-300 rounded border bg-gray-50 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <?php foreach ($torneios as $t): ?>
                            <option value="<?= $t ?>"><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. FASE -->
                <div class="p-5">
                    <label class="block text-xs font-bold text-gray-500 mb-2 uppercase">2. Fase</label>
                    <select name="phase" onchange="toggleGroupSelect(this.value)" class="block w-full border-gray-300 rounded border py-2 mb-3 text-sm">
                        <?php foreach ($fases as $f): ?><option value="<?= $f ?>"><?= $f ?></option><?php endforeach; ?>
                    </select>
                    <div id="group_container">
                        <select name="group_num" class="block w-full border-gray-300 rounded bg-gray-50 py-2 text-sm">
                            <?php for($g=1; $g<=8; $g++): ?><option value="<?= $g ?>">Grupo <?= $g ?></option><?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- 3. PILOTOS (COM NICKNAME) -->
                <div class="p-5 bg-gray-50/50">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-xs font-bold text-gray-500 uppercase">3. Pilotos (Player 1 & 2)</label>
                    </div>
                    <div class="max-h-60 overflow-y-auto border border-gray-200 rounded bg-white p-2 space-y-1">
                        <?php if(empty($pilots)): ?><p class="text-xs text-red-500 text-center py-4">Sem pilotos cadastrados.</p><?php else: ?>
                            <?php foreach ($pilots as $p): 
                                $displayLabel = htmlspecialchars($p['nome']);
                                if (!empty($p['nickname_TGC'])) {
                                    $displayLabel = "<b>" . htmlspecialchars($p['nickname_TGC']) . "</b> <span class='text-gray-400 text-[10px]'>(" . htmlspecialchars($p['nome']) . ")</span>";
                                }
                            ?>
                                <label class="flex items-center space-x-2 p-1.5 hover:bg-indigo-50 rounded cursor-pointer transition-colors border border-transparent hover:border-indigo-100">
                                    <input type="checkbox" name="pilots[]" value="<?= $p['id'] ?>" onchange="handleSelection(this)" class="h-4 w-4 text-indigo-600 rounded">
                                    <span class="text-sm text-gray-700 flex-1"><?= $displayLabel ?></span>
                                    <span id="label-<?= $p['id'] ?>" class="p-label text-[10px] font-bold text-blue-600"></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 4. PRAZO (Somente Data) -->
                <div class="p-5 flex flex-col justify-center">
                    <label class="block text-xs font-bold text-gray-500 mb-2 uppercase">4. Prazo</label>
                    <label class="block text-[10px] text-gray-400 mb-1">Data Final para a Partida</label>
                    <input type="date" name="deadline_date" value="<?= date('Y-m-d') ?>" class="block w-full border-gray-300 rounded border py-3 text-lg font-bold text-center shadow-sm">
                </div>
            </div>

            <!-- 5. LOCAL DA CORRIDA (NOVA SEÇÃO) -->
            <div class="border-t border-gray-200 p-6 bg-gray-50">
                <label class="block text-xs font-bold text-gray-500 mb-4 uppercase">5. Local da Corrida (Seleção Ordenada)</label>

                <!-- Seletor de Tipo (Radio Buttons Estilizados) -->
                <div class="flex gap-4 mb-6">
                    <label class="cursor-pointer">
                        <input type="radio" name="draw_type" id="draw_type_livre" value="" class="peer sr-only" onchange="switchDrawType('')" checked>
                        <div class="px-6 py-2 rounded-lg border border-gray-300 bg-white text-gray-600 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600 font-bold transition-all hover:shadow-md">
                            🎲 Livre Escolha
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="draw_type" id="draw_type_paises" value="paises" class="peer sr-only" onchange="switchDrawType('paises')">
                        <div class="px-6 py-2 rounded-lg border border-gray-300 bg-white text-gray-600 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600 font-bold transition-all hover:shadow-md">
                            🌎 Sorteio Países
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="draw_type" id="draw_type_pistas" value="pistas" class="peer sr-only" onchange="switchDrawType('pistas')">
                        <div class="px-6 py-2 rounded-lg border border-gray-300 bg-white text-gray-600 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-600 font-bold transition-all hover:shadow-md">
                            🏁 Sorteio Pistas
                        </div>
                    </label>
                </div>

                <!-- Container Países -->
                <div id="container_paises" class="hidden">
                    <p class="text-xs text-gray-500 mb-2">Clique na ordem que deseja que apareçam:</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($paisesTopGear as $pais): ?>
                            <label class="cursor-pointer relative group">
                                <input type="checkbox" name="paises_selected[]" value="<?= $pais ?>" onchange="handlePaisClick(this)" class="peer sr-only">
                                <div class="w-24 h-16 flex items-center justify-center rounded-lg border-2 border-gray-200 bg-white hover:border-indigo-300 transition-all">
                                    <span class="font-bold text-gray-700"><?= $pais ?></span>
                                </div>
                                <span class="pais-badge absolute -top-2 -right-2 bg-indigo-600 text-white text-xs font-bold w-6 h-6 flex items-center justify-center rounded-full shadow-sm"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Container Pistas -->
                <div id="container_pistas" class="hidden">
                    <p class="text-xs text-gray-500 mb-2">Clique na ordem que deseja que apareçam:</p>
                    <div class="grid grid-cols-4 md:grid-cols-8 gap-2">
                        <?php foreach($pistas_disponiveis as $id => $nomePista): ?>
                            <label class="cursor-pointer relative group" title="<?= $nomePista ?>">
                                <input type="checkbox" name="pistas_selected[]" value="<?= $id ?>" onchange="handlePistaClick(this)" class="peer sr-only">
                                <div class="h-12 flex flex-col items-center justify-center rounded border border-gray-200 bg-white hover:border-indigo-300 transition-all p-1">
                                    <span class="text-xs font-bold text-gray-600"><?= str_pad($id, 2, '0', STR_PAD_LEFT) ?></span>
                                    <span class="text-[9px] text-gray-400 truncate w-full text-center"><?= substr(explode('-', $nomePista)[1] ?? '', 0, 8) ?></span>
                                </div>
                                <span class="pista-badge absolute -top-2 -right-2 bg-indigo-600 text-white text-[10px] font-bold w-5 h-5 flex items-center justify-center rounded-full shadow-sm z-10"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-100 px-6 py-4 border-t border-gray-200 flex justify-end items-center gap-3">
                <a href="admin.php" class="text-gray-600 hover:text-indigo-600 font-medium text-sm flex items-center gap-1 transition-colors mr-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Atualizar
                </a>

                <button type="submit" name="gerar_partidas" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-3 px-8 rounded-lg shadow-md hover:shadow-lg transition-all transform hover:-translate-y-0.5">
                    🎲 GERAR PARTIDAS
                </button>
            </div>
        </form>

        <!-- LISTAGEM DE PARTIDAS -->
        <div class="flex items-center gap-3 mb-6">
            <h3 class="text-xl font-bold text-gray-800">📋 Partidas Ativas</h3>
            <span class="bg-gray-200 text-gray-600 text-xs px-2 py-1 rounded-full"><?= count($matches) ?> total</span>
        </div>

        <?php if (empty($viewMatches)): ?>
            <div class="text-center py-12 bg-white rounded-lg shadow-sm border border-gray-200 text-gray-500">
                <p class="text-lg">Nenhuma partida criada ainda.</p>
                <p class="text-sm">Use o gerador acima para começar.</p>
            </div>
        <?php else: ?>
            <div class="space-y-8">
            <?php foreach ($viewMatches as $torneioName => $fasesDoTorneio): ?>
                <div class="bg-white shadow-md rounded-lg overflow-hidden border border-gray-200">
                    <div class="bg-gray-800 text-white px-4 py-3 font-bold flex justify-between items-center">
                        <span class="tracking-wide"><?= $torneioName ?></span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase">
                                    <th class="px-4 py-3 font-semibold">ID</th>
                                    <th class="px-4 py-3 font-semibold">Fase / Grupo</th>
                                    <th class="px-4 py-3 font-semibold">Player 1</th>
                                    <th class="px-4 py-3 font-semibold">Player 2</th>
                                    <th class="px-4 py-3 font-semibold">Local</th>
                                    <th class="px-4 py-3 font-semibold">Prazo</th>
                                    <th class="px-4 py-3 font-semibold">Agendamento (Status)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm text-gray-700">
                                <?php foreach ($fasesDoTorneio as $faseName => $lista): ?>
                                    <?php foreach ($lista as $m): 
                                        // Uso estrito de P1 e P2 (sem fallback)
                                        $p1Id = $m['player_1_id'] ?? null;
                                        $p2Id = $m['player_2_id'] ?? null;
                                        
                                        $pA = getPilotNameDisplay($p1Id, $pilotsMap);
                                        $pB = getPilotNameDisplay($p2Id, $pilotsMap);
                                        
                                        // Formatar Local
                                        $localDisplay = "Livre";
                                        if (is_array($m['local_track']) && !empty($m['local_track'])) {
                                            $countLoc = count($m['local_track']);
                                            $localDisplay = $countLoc > 2 ? $m['local_track'][0] . " (+$countLoc)" : implode(', ', $m['local_track']);
                                        } elseif (is_string($m['local_track'])) {
                                            $localDisplay = $m['local_track'];
                                        }

                                        // Formatar Grupo
                                        $grpDisplay = $faseName;
                                        if ($m['group_name'] && $m['group_name'] != $faseName) $grpDisplay .= " <span class='text-gray-400'>({$m['group_name']})</span>";
                                        
                                        // Buscar Agendamento
                                        $sched = getMatchSchedule($m['id'], $schedules);
                                        $schedHtml = "<span class='text-gray-400 italic text-xs'>Sem agendamento</span>";
                                        
                                        if ($sched) {
                                            $dt = date('d/m H:i', strtotime($sched['data_hora']));
                                            $quemPropos = getPilotNameDisplay($sched['proposed_by_pilot_id'], $pilotsMap);
                                            
                                            if ($sched['status'] == 'CONFIRMADO') {
                                                $schedHtml = "<span class='bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs font-bold'>CONFIRMADO</span><br><span class='text-xs'>{$dt}</span>";
                                            } elseif ($sched['status'] == 'RECUSADO') {
                                                $schedHtml = "<span class='bg-red-100 text-red-700 px-2 py-0.5 rounded text-xs font-bold'>RECUSADO</span>";
                                            } else {
                                                $schedHtml = "<span class='bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs font-bold'>PROPOSTO</span><br><span class='text-xs'>{$dt} por {$quemPropos}</span>";
                                            }
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-4 py-3 font-mono text-gray-500">#<?= $m['id'] ?></td>
                                        <td class="px-4 py-3"><?= $grpDisplay ?></td>
                                        <td class="px-4 py-3"><span class="font-medium text-indigo-900"><?= $pA ?></span></td>
                                        <td class="px-4 py-3"><span class="font-medium text-indigo-900"><?= $pB ?></span></td>
                                        <td class="px-4 py-3 text-xs max-w-[150px] truncate" title="<?= is_array($m['local_track']) ? implode(', ', $m['local_track']) : $m['local_track'] ?>">
                                            📍 <?= $localDisplay ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs font-mono text-red-600">
                                            <?= date('d/m H:i', strtotime($m['deadline'])) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?= $schedHtml ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ZONA DE PERIGO (LOGS E RESET) -->
        <div class="mt-12 mb-20 pt-8 border-t border-gray-200">
            <h3 class="text-center text-gray-400 text-xs font-bold uppercase tracking-widest mb-6">Zona de Perigo & Logs</h3>
            
            <div class="flex flex-wrap justify-center gap-4">
                
                <!-- Criar Backup -->
                <form method="POST">
                    <button type="submit" name="criar_backup" class="group flex items-center text-green-600 hover:text-white border border-green-200 bg-white hover:bg-green-600 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                        <span class="text-xl mr-3">💾</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Criar Backup</span>
                    </button>
                </form>

                <!-- Gerenciar Backups -->
                <button type="button" onclick="openBackupModal()" class="group flex items-center text-cyan-600 hover:text-white border border-cyan-200 bg-white hover:bg-cyan-600 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                    <span class="text-xl mr-3">🗂️</span>
                    <span class="font-bold text-sm uppercase tracking-wider">Backups (<?= count($backupList) ?>)</span>
                </button>

                <!-- Upload Partidas (Novo) -->
                <form method="POST" enctype="multipart/form-data" class="group flex items-center md:w-auto w-full">
                    <input type="file" name="matches_file" id="matches_file" class="hidden" accept=".json" onchange="this.form.submit()">
                    <button type="button" onclick="document.getElementById('matches_file').click()" class="group flex items-center justify-center text-purple-600 hover:text-white border border-purple-200 bg-white hover:bg-purple-600 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full">
                        <span class="text-xl mr-3">📂</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Upload Partidas</span>
                    </button>
                </form>

                <!-- Ver Logs (Popup) -->
                <button type="button" onclick="openLogModal()" class="group flex items-center text-indigo-600 hover:text-white border border-indigo-200 bg-white hover:bg-indigo-600 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                    <span class="text-xl mr-3">👁️</span>
                    <span class="font-bold text-sm uppercase tracking-wider">Ver Logs</span>
                </button>

                <!-- Baixar Logs -->
                <form method="POST" target="_blank">
                    <button type="submit" name="baixar_logs" class="group flex items-center text-gray-600 hover:text-white border border-gray-200 bg-white hover:bg-gray-600 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                        <span class="text-xl mr-3">⬇️</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Baixar Logs</span>
                    </button>
                </form>

                 <!-- Arquivar Logs -->
                 <form method="POST" onsubmit="return confirm('Deseja renomear o log atual para arquivamento e iniciar um novo limpo?');">
                    <button type="submit" name="arquivar_logs" class="group flex items-center text-yellow-600 hover:text-white border border-yellow-200 bg-white hover:bg-yellow-500 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                        <span class="text-xl mr-3">📦</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Arquivar Logs</span>
                    </button>
                </form>

                <!-- Limpar Logs -->
                <form method="POST" onsubmit="return confirm('Apagar todo o histórico de logs de erro/debug?');">
                    <button type="submit" name="limpar_logs" class="group flex items-center text-orange-500 hover:text-white border border-orange-200 bg-white hover:bg-orange-500 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                        <span class="text-xl mr-3">🧹</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Limpar Logs</span>
                    </button>
                </form>

                <!-- Resetar Temporada -->
                <form method="POST" onsubmit="return confirm('⚠️ ATENÇÃO EXTREMA ⚠️\n\nIsso apagará:\n- Todas as Partidas\n- Todos os Agendamentos\n- Todo o Histórico de Auditoria\n\nIsso NÃO pode ser desfeito. Tem certeza?');">
                    <button type="submit" name="limpar_partidas" class="group flex items-center text-red-600 hover:text-white border border-red-200 bg-white hover:bg-red-600 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                        <span class="text-xl mr-3 group-hover:scale-110 transition-transform">💣</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Resetar Temporada</span>
                    </button>
                </form>
            </div>
        </div>

    </div>

    <!-- MODAL DE LOGS -->
    <div id="logModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white w-11/12 md:w-3/4 h-3/4 rounded-lg shadow-2xl flex flex-col overflow-hidden">
            <div class="bg-indigo-900 text-white px-4 py-3 flex justify-between items-center">
                <h3 class="font-bold text-lg">Últimas 100 linhas do Log</h3>
                <button onclick="closeLogModal()" class="text-gray-300 hover:text-white text-2xl">&times;</button>
            </div>
            <div class="flex-1 p-4 bg-gray-900 overflow-auto">
                <pre class="text-green-400 font-mono text-xs whitespace-pre-wrap"><?= htmlspecialchars($logTail) ?: 'Nenhum log disponível.' ?></pre>
            </div>
            <div class="bg-gray-100 px-4 py-2 text-right border-t">
                <button onclick="closeLogModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-1 px-4 rounded">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL DE BACKUPS -->
    <div id="backupModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white w-11/12 md:w-2/3 max-h-[80vh] rounded-lg shadow-2xl flex flex-col overflow-hidden">
            <div class="bg-cyan-800 text-white px-4 py-3 flex justify-between items-center">
                <h3 class="font-bold text-lg">Gerenciamento de Backups</h3>
                <span class="text-sm bg-cyan-900 px-2 py-1 rounded">Total: <?= getBackupDirSize() ?></span>
                <button onclick="closeBackupModal()" class="text-gray-300 hover:text-white text-2xl ml-4">&times;</button>
            </div>
            <div class="flex-1 p-0 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-100 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-2">Pasta/ID</th>
                            <th class="px-4 py-2">Arquivos</th>
                            <th class="px-4 py-2">Tamanho</th>
                            <th class="px-4 py-2 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($backupList as $bf): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-gray-700"><?= $bf['timestamp'] ?></td>
                            <td class="px-4 py-3"><?= $bf['files'] ?> arquivo(s)</td>
                            <td class="px-4 py-3"><?= $bf['size_mb'] ?> MB</td>
                            <td class="px-4 py-3 text-right flex justify-end gap-2">
                                <form method="POST">
                                    <input type="hidden" name="timestamp" value="<?= $bf['timestamp'] ?>">
                                    <button type="submit" name="baixar_backup" class="text-blue-600 hover:text-blue-900 font-bold text-xs border border-blue-200 px-2 py-1 rounded hover:bg-blue-50">Baixar ZIP</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Excluir este backup permanentemente?');">
                                    <input type="hidden" name="timestamp" value="<?= $bf['timestamp'] ?>">
                                    <button type="submit" name="excluir_backup" class="text-red-600 hover:text-red-900 font-bold text-xs border border-red-200 px-2 py-1 rounded hover:bg-red-50">Excluir</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($backupList)): ?>
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Nenhum backup encontrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="bg-gray-100 px-4 py-2 text-right border-t">
                <button onclick="closeBackupModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-1 px-4 rounded">Fechar</button>
            </div>
        </div>
    </div>

</body>
</html>