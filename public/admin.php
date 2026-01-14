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

// Cria diretórios se não existirem
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
if (!is_dir(LOG_DIR))  mkdir(LOG_DIR, 0755, true);

// Arquivos de Dados
if (!defined('FILE_PILOTS'))    define('FILE_PILOTS', DATA_DIR . '/pilots.json');
if (!defined('FILE_MATCHES'))   define('FILE_MATCHES', DATA_DIR . '/matches.json');
if (!defined('FILE_SCHEDULES')) define('FILE_SCHEDULES', DATA_DIR . '/schedules.json');
if (!defined('FILE_AUDIT'))     define('FILE_AUDIT', DATA_DIR . '/auditSchedules.json');
if (!defined('FILE_LOG'))       define('FILE_LOG', LOG_DIR . '/botMain.log');

// Configurações Básicas
date_default_timezone_set('America/Sao_Paulo');

// Tenta carregar configurações externas de forma segura
if (file_exists(__DIR__ . '/config/environment.php')) include_once __DIR__ . '/config/environment.php';
if (file_exists(__DIR__ . '/src/Auth/AdminAuth.php')) include_once __DIR__ . '/src/Auth/AdminAuth.php';

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

// ============================================================
// 2. LÓGICA DO DASHBOARD
// ============================================================

// Listas de apoio
$torneios = []; for ($i = 1; $i <= 16; $i++) $torneios[] = "Torneio $i";
$fases = ["Fase de Grupos", "Oitavas de Final", "Quartas de Final", "Semifinal", "Final", "3º Lugar"];
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

// Helper para logs do admin
function adminLog($msg) {
    $entry = "[" . date('Y-m-d H:i:s') . "] ADMIN: $msg" . PHP_EOL;
    file_put_contents(FILE_LOG, $entry, FILE_APPEND);
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

$msgFeedback = '';

// --- AÇÃO: LIMPAR TUDO (RESET TEMPORADA) ---
if (isset($_POST['limpar_partidas'])) {
    saveJson(FILE_MATCHES, []);
    saveJson(FILE_SCHEDULES, []);
    saveJson(FILE_AUDIT, []);
    
    adminLog("Resetou a temporada (Matches, Schedules e Audit apagados).");
    
    $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>🗑️ <b>Limpeza Completa!</b> Temporada resetada.</div>";
}

// --- AÇÃO: LIMPAR LOGS ---
if (isset($_POST['limpar_logs'])) {
    file_put_contents(FILE_LOG, "[" . date('Y-m-d H:i:s') . "] Log reiniciado pelo Admin." . PHP_EOL);
    $msgFeedback = "<div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4'>📄 <b>Logs Limpos!</b> Arquivo de log reiniciado.</div>";
}

// --- AÇÃO: GERAR PARTIDAS ---
if (isset($_POST['gerar_partidas'])) {
    $pilots = getJson(FILE_PILOTS);
    $matches = getJson(FILE_MATCHES);
    
    $selTournament = $_POST['tournament'] ?? '';
    $selPhase = $_POST['phase'] ?? '';
    $selGroupNum = $_POST['group_num'] ?? '';
    $selPilotsIDs = $_POST['pilots'] ?? [];
    $drawType = $_POST['draw_type'] ?? '';
    $dateInput = $_POST['deadline_date'] ?? ''; 
    $prazoFinal = $dateInput ? $dateInput . " 23:59:59" : date('Y-m-d 23:59:59', strtotime('+7 days'));
    $groupName = ($selPhase === "Fase de Grupos") ? "Grupo $selGroupNum" : $selPhase;

    $localArray = []; 
    if ($drawType === 'paises') $localArray = $_POST['paises_selected'] ?? [];
    elseif ($drawType === 'pistas') {
        foreach ($_POST['pistas_selected'] ?? [] as $id) if (isset($pistas_disponiveis[$id])) $localArray[] = $pistas_disponiveis[$id];
    }
    
    if (count($selPilotsIDs) < 2) {
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Selecione pelo menos 2 pilotos.</div>";
    } else {
        $selectedPilotsObjs = [];
        if(is_array($pilots)) {
            foreach ($pilots as $p) if (in_array($p['id'], $selPilotsIDs)) $selectedPilotsObjs[] = $p;
        }

        $countSel = count($selectedPilotsObjs);
        $novas = 0;
        for ($i = 0; $i < $countSel; $i++) {
            for ($j = $i + 1; $j < $countSel; $j++) {
                $matches[] = [
                    'id' => getNextId($matches),
                    'pilot_a_id' => $selectedPilotsObjs[$i]['id'],
                    'pilot_b_id' => $selectedPilotsObjs[$j]['id'],
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
        function toggleGroupSelect(val) { document.getElementById('group_container').classList.toggle('hidden', val !== 'Fase de Grupos'); }
        function toggleDrawOptions(val) {
            document.getElementById('paises_list').classList.add('hidden');
            document.getElementById('pistas_list').classList.add('hidden');
            if (val === 'paises') document.getElementById('paises_list').classList.remove('hidden');
            if (val === 'pistas') document.getElementById('pistas_list').classList.remove('hidden');
        }
        function toggleSelectAll(src) { document.getElementsByName('pilots[]').forEach(cb => cb.checked = src.checked); }
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
                        <label class="block text-xs font-bold text-gray-500 uppercase">3. Pilotos</label>
                        <label class="text-[10px] text-indigo-600 cursor-pointer hover:underline"><input type="checkbox" onclick="toggleSelectAll(this)" class="align-middle"> Selecionar Todos</label>
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
                                    <input type="checkbox" name="pilots[]" value="<?= $p['id'] ?>" class="h-4 w-4 text-indigo-600 rounded">
                                    <span class="text-sm text-gray-700"><?= $displayLabel ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 4. PRAZO E LOCAL -->
                <div class="p-5">
                    <label class="block text-xs font-bold text-gray-500 mb-2 uppercase">4. Detalhes</label>
                    <div class="mb-4">
                        <label class="block text-[10px] text-gray-400 mb-1">Prazo (Data Final)</label>
                        <input type="date" name="deadline_date" value="<?= date('Y-m-d') ?>" class="block w-full border-gray-300 rounded border py-1.5 text-sm text-center">
                    </div>
                    <label class="block text-[10px] text-gray-400 mb-1">Local da Corrida</label>
                    <select name="draw_type" onchange="toggleDrawOptions(this.value)" class="block w-full border-gray-300 rounded border py-1.5 text-sm mb-2">
                        <option value="">-- Livre --</option>
                        <option value="paises">Países</option>
                        <option value="pistas">Pistas (1-32)</option>
                    </select>

                    <div id="paises_list" class="hidden max-h-32 overflow-y-auto border border-gray-200 p-2 rounded bg-white">
                        <?php foreach ($paisesTopGear as $pais): ?>
                            <label class="flex items-center"><input type="checkbox" name="paises_selected[]" value="<?= $pais ?>" class="mr-2"> <span class="text-xs"><?= $pais ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div id="pistas_list" class="hidden max-h-32 overflow-y-auto border border-gray-200 p-2 rounded bg-white grid grid-cols-4 gap-1">
                        <?php foreach($pistas_disponiveis as $id => $nomePista): ?>
                            <label class="flex justify-center border rounded hover:bg-gray-100 cursor-pointer p-1" title="<?= $nomePista ?>">
                                <input type="checkbox" name="pistas_selected[]" value="<?= $id ?>" class="hidden peer">
                                <span class="text-xs text-gray-400 peer-checked:text-indigo-600 peer-checked:font-bold"><?= $id ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 text-right">
                <button type="submit" name="gerar_partidas" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2 px-6 rounded shadow-sm transition-colors">
                    🎲 Gerar Partidas
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
                                    <th class="px-4 py-3 font-semibold">Pilotos</th>
                                    <th class="px-4 py-3 font-semibold">Local</th>
                                    <th class="px-4 py-3 font-semibold">Prazo</th>
                                    <th class="px-4 py-3 font-semibold">Agendamento (Status)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-sm text-gray-700">
                                <?php foreach ($fasesDoTorneio as $faseName => $lista): ?>
                                    <?php foreach ($lista as $m): 
                                        $pA = getPilotNameDisplay($m['pilot_a_id'], $pilotsMap);
                                        $pB = getPilotNameDisplay($m['pilot_b_id'], $pilotsMap);
                                        
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
                                        <td class="px-4 py-3">
                                            <div class="flex flex-col">
                                                <span class="font-medium text-indigo-900"><?= $pA ?></span>
                                                <span class="text-xs text-gray-400">vs</span>
                                                <span class="font-medium text-indigo-900"><?= $pB ?></span>
                                            </div>
                                        </td>
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

        <!-- ZONA DE PERIGO (RESET E LOGS) -->
        <div class="mt-12 mb-20 pt-8 border-t border-gray-200">
            <h3 class="text-center text-gray-400 text-xs font-bold uppercase tracking-widest mb-6">Zona de Perigo</h3>
            
            <div class="flex flex-col md:flex-row justify-center gap-4">
                <!-- Botão Resetar Temporada -->
                <form method="POST" onsubmit="return confirm('⚠️ ATENÇÃO EXTREMA ⚠️\n\nIsso apagará:\n- Todas as Partidas\n- Todos os Agendamentos\n- Todo o Histórico de Auditoria\n\nIsso NÃO pode ser desfeito. Tem certeza?');">
                    <button type="submit" name="limpar_partidas" class="group flex items-center text-red-600 hover:text-white border border-red-200 bg-white hover:bg-red-600 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                        <span class="text-xl mr-3 group-hover:scale-110 transition-transform">💣</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Resetar Temporada</span>
                    </button>
                </form>

                <!-- Botão Limpar Logs -->
                <form method="POST" onsubmit="return confirm('Apagar todo o histórico de logs de erro/debug?');">
                    <button type="submit" name="limpar_logs" class="group flex items-center text-gray-500 hover:text-white border border-gray-200 bg-white hover:bg-gray-500 px-6 py-3 rounded-lg shadow-sm transition-all duration-300 w-full md:w-auto">
                        <span class="text-xl mr-3">📄</span>
                        <span class="font-bold text-sm uppercase tracking-wider">Limpar Logs de Erro</span>
                    </button>
                </form>
            </div>
        </div>

    </div>
</body>
</html>