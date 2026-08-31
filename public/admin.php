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

// Carregar Configuração de Ambiente Central
if (file_exists(__DIR__ . '/../src/config/environment.php')) {
    require_once __DIR__ . '/../src/config/environment.php';
}

// Carregar Utilitários
if (file_exists(__DIR__ . '/../src/utils/logHandler.php')) {
    require_once __DIR__ . '/../src/utils/logHandler.php';
}
if (file_exists(__DIR__ . '/../src/utils/backupManager.php')) {
    require_once __DIR__ . '/../src/utils/backupManager.php';
}
if (file_exists(__DIR__ . '/../src/utils/adminAuth.php')) {
    require_once __DIR__ . '/../src/utils/adminAuth.php';
}

// Iniciar Sessão
if (session_status() === PHP_SESSION_NONE) session_start();

$loginError = '';
$useAdvancedAuth = class_exists('adminAuth');

// --- PROCESSAMENTO DE LOGIN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $password = $_POST['admin_password'] ?? '';
    $adminPass = $_ENV['ADMIN_PASSWORD'];

	if ($adminPass === null) {
		$loginError = 'Senha de administrador não configurada.';
	} elseif ($useAdvancedAuth) {
        try {
            adminAuth::login($password);
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
    if ($useAdvancedAuth) adminAuth::logout();
    unset($_SESSION['admin_logged_in']);
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// --- VERIFICAÇÃO DE ACESSO ---
$isAuth = $useAdvancedAuth ? adminAuth::check() : ($_SESSION['admin_logged_in'] ?? false);

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
$tournamentCatalog = loadTournamentCatalog();
$torneios = $tournamentCatalog['tournaments'];
$fases = $tournamentCatalog['phases'];

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

function loadTournamentCatalog() {
    $tournaments = getJson(FILE_TOURNAMENTS);
    if (!is_array($tournaments) || empty($tournaments)) {
        $tournaments = [
            ['id' => 'T0', 'name' => 'ADM - Bot WhatsApp 🤖 🏁'],
            ['id' => 'T1', 'name' => 'Torneio de Verão: Dakar Series'],
            ['id' => 'T2', 'name' => 'American LeMans Series'],
            ['id' => 'T3', 'name' => 'La Liga - Série Ouro'],
            ['id' => 'T4', 'name' => 'La Liga - Série Prata'],
            ['id' => 'T5', 'name' => 'La Liga - Série Bronze'],
            ['id' => 'T6', 'name' => 'TGC Pole Position'],
            ['id' => 'T7', 'name' => 'Torneio de Outono: Acropolis Cup'],
            ['id' => 'T8', 'name' => 'F1 Academy'],
            ['id' => 'T9', 'name' => 'Copa TGC'],
            ['id' => 'T10', 'name' => 'TGC Numerado'],
            ['id' => 'T11', 'name' => 'TGC Prototype Challenge'],
            ['id' => 'T12', 'name' => 'Torneio de Inverno: Arctic Rally'],
            ['id' => 'T13', 'name' => 'La Liga - Série Ouro'],
            ['id' => 'T14', 'name' => 'La Liga - Série Prata'],
            ['id' => 'T15', 'name' => 'La Liga - Série Bronze'],
            ['id' => 'T16', 'name' => 'Torneio de Primavera: Targa Florio'],
            ['id' => 'T17', 'name' => 'Champions Cup'],
            ['id' => 'T18', 'name' => 'Asia LeMans Series']
        ];
    }

    $phases = getJson(FILE_TOURNAMENTS_PHASES);
    if (!is_array($phases) || empty($phases)) {
        $phases = [
            ['id' => 'F1', 'name' => 'Fase de Grupos'],
            ['id' => 'F2', 'name' => 'Oitavas de Final'],
            ['id' => 'F3', 'name' => 'Quartas de Final'],
            ['id' => 'F4', 'name' => 'Semifinal'],
            ['id' => 'F5', 'name' => 'Final'],
            ['id' => 'F6', 'name' => '3º Lugar'],
            ['id' => 'F7', 'name' => 'Rodada Atual'],
            ['id' => 'F8', 'name' => 'Eliminatórias']
        ];
    }

    return ['tournaments' => $tournaments, 'phases' => $phases];
}

function normalizeTournamentId($value, $tournaments) {
    if (empty($value)) return '';
    $valueStr = (string) $value;

    if (preg_match('/^(T\d+)/', $valueStr, $matches)) {
        return $matches[1];
    }

    foreach ($tournaments as $t) {
        $id = (string) ($t['id'] ?? '');
        $name = (string) ($t['name'] ?? '');
        if ($id === $valueStr || $name === $valueStr || strpos($id, $valueStr) !== false || strpos($name, $valueStr) !== false) {
            return $id;
        }
    }
    return $valueStr;
}

function normalizePhaseId($value, $phases) {
    if (empty($value)) return '';
    foreach ($phases as $phase) {
        if (($phase['id'] ?? '') === (string) $value || (($phase['name'] ?? '') === (string) $value)) {
            return (string) ($phase['id'] ?? '');
        }
    }
    return (string) $value;
}

function getTournamentNameById($id, $tournaments) {
    foreach ($tournaments as $t) {
        if (($t['id'] ?? '') === (string) $id) return (string) ($t['name'] ?? $id);
    }
    return (string) $id;
}

function getPhaseNameById($id, $phases) {
    foreach ($phases as $p) {
        if (($p['id'] ?? '') === (string) $id) return (string) ($p['name'] ?? $id);
    }
    return (string) $id;
}

function normalizeMatchRecord($match, $tournaments, $phases) {
    if (!is_array($match)) return $match;

    $tournamentId = normalizeTournamentId($match['tournament_id'] ?? ($match['tournament'] ?? ''), $tournaments);
    $phaseId = normalizePhaseId($match['phase_id'] ?? ($match['phase'] ?? ''), $phases);

    if (!empty($tournamentId)) {
        $match['tournament_id'] = $tournamentId;
        $match['tournament'] = getTournamentNameById($tournamentId, $tournaments);
    }
    if (!empty($phaseId)) {
        $match['phase_id'] = $phaseId;
        $match['phase'] = getPhaseNameById($phaseId, $phases);
    }

    $match['player_1_id'] = $match['player_1_id'] ?? $match['player1ID'] ?? null;
    $match['player_2_id'] = $match['player_2_id'] ?? $match['player2ID'] ?? null;
    $match['group_name'] = $match['group_name'] ?? $match['groupName'] ?? null;
    $match['winner_id'] = $match['winner_id'] ?? $match['winnerID'] ?? null;
    $match['created_at'] = $match['created_at'] ?? $match['createdAt'] ?? null;
    $match['local_track'] = $match['local_track'] ?? $match['localTrack'] ?? [];

    $match['player1ID'] = $match['player_1_id'] ?? $match['player1ID'] ?? 0;
    $match['player2ID'] = $match['player_2_id'] ?? $match['player2ID'] ?? 0;
    $match['groupName'] = $match['group_name'] ?? $match['groupName'] ?? '';
    $match['winnerID'] = $match['winner_id'] ?? $match['winnerID'] ?? null;
    $match['createdAt'] = $match['created_at'] ?? $match['createdAt'] ?? date('Y-m-d H:i:s');
    $match['localTrack'] = $match['local_track'] ?? $match['localTrack'] ?? [];

    return $match;
}

function appendAdminAudit($matchId, $action, $details) {
    $audit = getJson(FILE_AUDIT);
    $audit[] = [
        'id' => getNextId($audit),
        'timestamp' => date('Y-m-d H:i:s'),
        'matchID' => (int) $matchId,
        'pilotID' => 0,
        'action' => $action,
        'details' => $details
    ];
    saveJson(FILE_AUDIT, $audit);
}

// Helper para ler as últimas linhas do log
function tailLog($lines = 50) {
    if (!file_exists(FILE_LOG_BOT)) return "Arquivo de log vazio ou inexistente.";
    $file = file(FILE_LOG_BOT);
    $total = count($file);
    $start = max(0, $total - $lines);
    return implode("", array_slice($file, $start));
}

// Helper para encontrar agendamento único
function getMatchSchedule($matchId, $allSchedules) {
    if (!is_array($allSchedules)) return null;
    foreach ($allSchedules as $s) {
        $scheduleMatchId = $s['match_id'] ?? $s['matchID'] ?? null;
        if ($scheduleMatchId == $matchId) return $s;
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

// Helper para logs do admin (Compatibilidade com backupManager)
function adminLog($msg) {
    $entry = "[" . date('Y-m-d H:i:s') . "] ADMIN: $msg" . PHP_EOL;
    file_put_contents(FILE_LOG_BOT, $entry, FILE_APPEND);
}

// ============================================================
// 2. PROCESSAMENTO DE AÇÕES
// ============================================================

// INICIALIZAÇÃO DA VARIÁVEL DE FEEDBACK
$msgFeedback = '';

// --- AÇÃO: EXCLUIR PARTIDA INDIVIDUAL ---
if (isset($_POST['delete_match_id'])) {
    $delId = intval($_POST['delete_match_id']);
    $currentMatches = getJson(FILE_MATCHES);
    $newMatchesList = [];
    $found = false;
    
    foreach($currentMatches as $m) {
        if ($m['id'] == $delId) {
            $found = true;
            // Opcional: Remover agendamentos relacionados?
            // Por segurança, mantemos os agendamentos no arquivo mas órfãos, ou limpamos.
            // Aqui vamos apenas remover a partida.
        } else {
            $newMatchesList[] = $m;
        }
    }
    
    if ($found) {
        saveJson(FILE_MATCHES, $newMatchesList);
        adminLog("Partida #$delId excluída individualmente.");
        $msgFeedback = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4'>🗑️ Partida <b>#$delId</b> excluída.</div>";
    }
}

// --- AÇÃO: EDITAR PARTIDA (INCLUINDO VENCEDOR) ---
if (isset($_POST['edit_match_id'])) {
    $editId = intval($_POST['edit_match_id']);
    $newPhase = $_POST['edit_phase'] ?? '';
    $newGroupNum = $_POST['edit_group_num'] ?? '';
    $newP1 = intval($_POST['edit_p1'] ?? 0);
    $newP2 = intval($_POST['edit_p2'] ?? 0);
    $newDate = $_POST['edit_deadline'] ?? '';
    $newWinnerVal = $_POST['edit_winner'] ?? 'null'; // 'null', '0', '-1' ou ID
    $tournamentCatalog = loadTournamentCatalog();
    $tournaments = $tournamentCatalog['tournaments'];
    $phases = $tournamentCatalog['phases'];
    $resolvedPhaseId = normalizePhaseId($newPhase, $phases);
    $resolvedPhaseName = getPhaseNameById($resolvedPhaseId, $phases);

    if ($editId && $resolvedPhaseId && $newP1 && $newP2 && $newDate) {
        $currentMatches = getJson(FILE_MATCHES);
        $schedules = getJson(FILE_SCHEDULES);
        $updated = false;

        // --- TRAVA DE SEGURANÇA: VENCEDOR SEM AGENDAMENTO ---
        $canSave = true;

        if ($newWinnerVal !== 'null') {
            $hasActiveSchedule = false;
            foreach ($schedules as $s) {
                if (($s['match_id'] ?? $s['matchID'] ?? null) == $editId && (($s['status'] ?? '') != 'RECUSADO')) {
                    $hasActiveSchedule = true;
                    break;
                }
            }

            if (!$hasActiveSchedule) {
                $canSave = false;
                $msgFeedback = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4'>⚠️ <b>Ação Bloqueada:</b> Não é possível definir um resultado (Vencedor/Empate/WO) pois esta partida <b>não possui um agendamento ativo</b>.<br><span class='text-xs'>Os pilotos devem agendar a partida primeiro, ou um agendamento deve ser criado manualmente.</span></div>";
            }
        }

        if ($canSave) {
            $newDeadline = $newDate . " 23:59:59";
            $newGroupName = ($resolvedPhaseId === "F1") ? "Grupo $newGroupNum" : $resolvedPhaseName;

            foreach($currentMatches as &$m) {
                if (($m['id'] ?? null) == $editId) {
                    $m['phase_id'] = $resolvedPhaseId;
                    $m['phase'] = $resolvedPhaseName;
                    $m['group_name'] = $newGroupName;
                    $m['groupName'] = $newGroupName;
                    $m['player_1_id'] = $newP1;
                    $m['player_2_id'] = $newP2;
                    $m['player1ID'] = $newP1;
                    $m['player2ID'] = $newP2;
                    $m['deadline'] = $newDeadline;

                    if ($newWinnerVal === 'null') {
                        $m['winner_id'] = null;
                        $m['winnerID'] = null;
                        $m['status'] = (($m['status'] ?? '') == 'CONCLUIDO') ? 'PENDENTE' : ($m['status'] ?? 'PENDENTE');
                    } else {
                        $m['winner_id'] = intval($newWinnerVal);
                        $m['winnerID'] = intval($newWinnerVal);
                        $m['status'] = 'CONCLUIDO';

                        foreach ($schedules as &$s) {
                            $scheduleMatchId = $s['match_id'] ?? $s['matchID'] ?? null;
                            if ($scheduleMatchId == $editId && (($s['status'] ?? '') != 'RECUSADO')) {
                                $s['match_id'] = $editId;
                                $s['matchID'] = $editId;
                                $s['status'] = 'PARTIDA_FINALIZADA';
                                $s['result_winner_id'] = intval($newWinnerVal);
                                $s['resultWinnerID'] = intval($newWinnerVal);
                                $s['result_confirmed_by'] = 'ADMIN_PAINEL';
                                $s['resultConfirmedBy'] = 'ADMIN_PAINEL';
                                $s['updated_at'] = date('Y-m-d H:i:s');
                                $s['updatedAt'] = date('Y-m-d H:i:s');
                            }
                        }
                    }

                    $updated = true;
                    break;
                }
            }

            if ($updated) {
                saveJson(FILE_MATCHES, $currentMatches);
                saveJson(FILE_SCHEDULES, $schedules);
                appendAdminAudit($editId, 'ADMIN_UPDATE_MATCH', 'Fase: ' . $resolvedPhaseName . ' | Grupo: ' . $newGroupName . ' | Pilotos: ' . $newP1 . ' x ' . $newP2 . ' | Prazo: ' . $newDeadline . ' | Resultado: ' . $newWinnerVal);

                adminLog("Partida #$editId editada.");
                $msgFeedback = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>✏️ Partida <b>#$editId</b> atualizada com sucesso.</div>";
            }
        }
    } else {
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro ao editar: Campos obrigatórios faltando.</div>";
    }
}

// --- AÇÃO: CLONAR PARTIDA ---
if (isset($_POST['clone_match_id'])) {
    $sourceId = intval($_POST['clone_match_id']);
    $clonePhase = $_POST['clone_phase'] ?? '';
    $cloneGroupNum = $_POST['clone_group_num'] ?? '';
    $cloneP1 = intval($_POST['clone_p1'] ?? 0);
    $cloneP2 = intval($_POST['clone_p2'] ?? 0);
    $cloneDate = $_POST['clone_deadline'] ?? '';

    if ($sourceId && $clonePhase && $cloneP1 && $cloneP2 && $cloneDate) {
        $matches = getJson(FILE_MATCHES);
        $sourceMatch = null;

        // Encontrar partida de origem
        foreach($matches as $m) {
            if ($m['id'] == $sourceId) {
                $sourceMatch = $m;
                break;
            }
        }

        if ($sourceMatch) {
            $newDeadline = $cloneDate . " 23:59:59";
            $newGroupName = ($clonePhase === "Fase de Grupos") ? "Grupo $cloneGroupNum" : $clonePhase;
            
            // Criar nova partida baseada na origem
            $resolvedClonePhaseId = normalizePhaseId($clonePhase, $phases);
            $resolvedClonePhaseName = getPhaseNameById($resolvedClonePhaseId, $phases);
            $resolvedCloneTournamentId = normalizeTournamentId($sourceMatch['tournament_id'] ?? ($sourceMatch['tournament'] ?? ''), $tournaments);
            $newMatch = [
                'id' => getNextId($matches),
                'player_1_id' => $cloneP1,
                'player_2_id' => $cloneP2,
                'player1ID' => $cloneP1,
                'player2ID' => $cloneP2,
                'group_name' => $newGroupName,
                'groupName' => $newGroupName,
                'tournament_id' => $resolvedCloneTournamentId,
                'phase_id' => $resolvedClonePhaseId,
                'tournament' => getTournamentNameById($resolvedCloneTournamentId, $tournaments),
                'phase' => $resolvedClonePhaseName,
                'local_track' => $sourceMatch['local_track'], // Copia o local
                'localTrack' => $sourceMatch['local_track'],
                'deadline' => $newDeadline,
                'status' => 'PENDENTE',
                'winner_id' => null,
                'winnerID' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'createdAt' => date('Y-m-d H:i:s')
            ];

            $matches[] = $newMatch;
            saveJson(FILE_MATCHES, $matches);
            adminLog("Partida #$sourceId clonada para nova partida #{$newMatch['id']}.");
            $msgFeedback = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>✅ <b>Sucesso!</b> Partida clonada. Nova ID: <b>#{$newMatch['id']}</b></div>";
        } else {
            $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro: Partida de origem não encontrada.</div>";
        }
    } else {
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro ao clonar: Campos obrigatórios faltando.</div>";
    }
}

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
    if (file_exists(FILE_LOG_BOT)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="botMain_'.date('Y-m-d_Hi').'.log"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize(FILE_LOG_BOT));
        readfile(FILE_LOG_BOT);
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
    if (class_exists('backupManager')) {
        $res = backupManager::createBackupSnapshot($_SESSION['admin_user'] ?? 'Admin');
        if ($res['success']) {
            adminLog("Backup manual criado: " . $res['timestamp']);
            $msgFeedback = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4'>💾 <b>Backup Criado!</b> Pasta: {$res['timestamp']}</div>";
        } else {
            $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Erro ao criar backup: {$res['error']}</div>";
        }
    } else {
        $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Classe backupManager não encontrada.</div>";
    }
}

// --- ADMIN: EXCLUIR BACKUP ---
if (isset($_POST['excluir_backup'])) {
    $timestamp = $_POST['timestamp'] ?? '';
    if (class_exists('backupManager')) {
        if (backupManager::deleteBackup($timestamp)) {
            adminLog("Backup excluído: $timestamp");
            $msgFeedback = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4'>🗑️ Backup <b>$timestamp</b> excluído.</div>";
        } else {
             $msgFeedback = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>Falha ao excluir backup.</div>";
        }
    }
}

// --- ADMIN: LIMPAR TUDO (RESET TEMPORADA) ---
if (isset($_POST['limpar_partidas'])) {
    if (class_exists('backupManager')) {
        $res = backupManager::rotateSeasonFull($_SESSION['admin_user'] ?? 'Admin');
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
    file_put_contents(FILE_LOG_BOT, "[" . date('Y-m-d H:i:s') . "] Log reiniciado pelo Admin." . PHP_EOL);
    $msgFeedback = "<div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4'>📄 <b>Logs Limpos!</b> Arquivo de log reiniciado.</div>";
}

// --- ADMIN: ARQUIVAR LOGS ---
if (isset($_POST['arquivar_logs'])) {
    if (file_exists(FILE_LOG_BOT)) {
        $timestamp = date('Y-m-d_H-i-s');
        $archiveName = LOG_DIR . "/archive_botMain_{$timestamp}.log";
        
        if (rename(FILE_LOG_BOT, $archiveName)) {
            file_put_contents(FILE_LOG_BOT, "[" . date('Y-m-d H:i:s') . "] Novo arquivo de log iniciado arquivamento." . PHP_EOL);
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
    $tournamentCatalog = loadTournamentCatalog();
    $tournaments = $tournamentCatalog['tournaments'];
    $phases = $tournamentCatalog['phases'];
    
    // Processamento da Ordem (P1/P2)
    $order = !empty($_POST['pilot_order']) ? explode(',', $_POST['pilot_order']) : [];

    $selTournament = $_POST['tournament'] ?? '';
    $selTournamentId = normalizeTournamentId($selTournament, $tournaments);
    $selPhase = $_POST['phase'] ?? '';
    $selPhaseId = normalizePhaseId($selPhase, $phases);
    $selGroupNum = $_POST['group_num'] ?? '';
    $drawType = $_POST['draw_type'] ?? '';
    $dateInput = $_POST['deadline_date'] ?? ''; 
    $prazoFinal = $dateInput ? $dateInput . " 23:59:59" : date('Y-m-d 23:59:59', strtotime('+7 days'));
    $groupName = ($selPhaseId === "F1") ? "Grupo $selGroupNum" : getPhaseNameById($selPhaseId, $phases);

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
                'player1ID' => intval($order[0]),
                'player2ID' => intval($order[1]),
                'group_name' => $groupName,
                'groupName' => $groupName,
                'tournament_id' => $selTournamentId,
                'phase_id' => $selPhaseId,
                'tournament' => getTournamentNameById($selTournamentId, $tournaments),
                'phase' => getPhaseNameById($selPhaseId, $phases),
                'local_track' => $localArray,
                'localTrack' => $localArray,
                'deadline' => $prazoFinal,
                'status' => 'PENDENTE',
                'winner_id' => null,
                'winnerID' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'createdAt' => date('Y-m-d H:i:s')
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
                        'player1ID' => intval($order[$i]),
                        'player2ID' => intval($order[$j]),
                        'group_name' => $groupName,
                        'groupName' => $groupName,
                        'tournament_id' => $selTournamentId,
                        'phase_id' => $selPhaseId,
                        'tournament' => getTournamentNameById($selTournamentId, $tournaments),
                        'phase' => getPhaseNameById($selPhaseId, $phases),
                        'local_track' => $localArray,
                        'localTrack' => $localArray,
                        'deadline' => $prazoFinal,
                        'status' => 'PENDENTE',
                        'winner_id' => null,
                        'winnerID' => null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'createdAt' => date('Y-m-d H:i:s')
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
$tournamentCatalog = loadTournamentCatalog();
$tournaments = $tournamentCatalog['tournaments'];
$phases = $tournamentCatalog['phases'];
$pilots = getJson(FILE_PILOTS);

// ORDENAR PILOTOS ALFABETICAMENTE (Nickname ou Nome) - NOVO
usort($pilots, function($a, $b) {
    $nameA = !empty($a['nickname_TGC']) ? $a['nickname_TGC'] : $a['nome'];
    $nameB = !empty($b['nickname_TGC']) ? $b['nickname_TGC'] : $b['nome'];
    return strcasecmp($nameA, $nameB);
});

$matches = getJson(FILE_MATCHES);
$schedules = getJson(FILE_SCHEDULES);
$logTail = tailLog(100); 

// Carregar Backups (Usando backupManager)
$backupList = class_exists('backupManager') ? backupManager::listBackups() : [];

$pilotsMap = []; 
if (is_array($pilots)) {
    foreach ($pilots as $p) $pilotsMap[$p['id']] = $p;
}

$viewMatches = [];
if (is_array($matches)) {
    foreach ($matches as $m) {
        $normalizedMatch = normalizeMatchRecord($m, $tournaments, $phases);
        $t = $normalizedMatch['tournament'] ?? 'Outros';
        $f = $normalizedMatch['phase'] ?? 'Geral';
        $viewMatches[$t][$f][] = $normalizedMatch;
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

        // Filtro de Pilotos (Para Geração)
        function filterPilots() {
            const input = document.getElementById('pilot_search');
            const filter = input.value.toLowerCase();
            const labels = document.querySelectorAll('#pilots_list label');

            labels.forEach(label => {
                const text = label.innerText.toLowerCase();
                if (text.includes(filter)) {
                    label.classList.remove('hidden');
                    label.classList.add('flex'); // Restaurar display flex
                } else {
                    label.classList.add('hidden');
                    label.classList.remove('flex');
                }
            });
        }

        // Filtro de Partidas (Para Tabela) - NOVO
        function filterMatches() {
            const input = document.getElementById('match_search');
            const filter = input.value.toLowerCase();
            const tournamentBlocks = document.querySelectorAll('.tournament-block');

            tournamentBlocks.forEach(block => {
                let hasVisibleMatch = false;
                const rows = block.querySelectorAll('.match-row');

                rows.forEach(row => {
                    const text = row.innerText.toLowerCase();
                    if (text.includes(filter)) {
                        row.classList.remove('hidden');
                        hasVisibleMatch = true;
                    } else {
                        row.classList.add('hidden');
                    }
                });

                // Ocultar o bloco do torneio se não houver partidas visíveis
                if (hasVisibleMatch) {
                    block.classList.remove('hidden');
                } else {
                    block.classList.add('hidden');
                }
            });
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

        function toggleGroupSelect(val) { document.getElementById('group_container').classList.toggle('hidden', val !== 'F1'); }
        
        function switchDrawType(val) {
            // Esconder todos
            document.getElementById('container_paises').classList.add('hidden');
            document.getElementById('container_pistas').classList.add('hidden');
            
            // Setar radio
            document.getElementById('draw_type_' + (val || 'livre')).checked = true;

            if (val === 'paises') document.getElementById('container_paises').classList.remove('hidden');
            if (val === 'pistas') document.getElementById('container_pistas').classList.remove('hidden');
        }
        
        // Modal de Edição de Partida
        function openEditModal(id, phase, groupName, p1, p2, deadline, winnerId) {
            document.getElementById('edit_match_id').value = id;
            document.getElementById('edit_match_title').innerText = "Editar Partida #" + id;
            
            // Setar Fase
            const phaseSelect = document.getElementById('edit_phase');
            phaseSelect.value = phase;
            toggleEditGroupSelect(phase);

            // Setar Grupo se for Fase de Grupos
            if (phase === 'F1' || phase === 'Fase de Grupos') {
                const groupNum = groupName.replace('Grupo ', '');
                document.getElementById('edit_group_num').value = groupNum;
            }

            // Setar Pilotos
            document.getElementById('edit_p1').value = p1;
            document.getElementById('edit_p2').value = p2;

            // Setar Prazo (Apenas data YYYY-MM-DD)
            const datePart = deadline.split(' ')[0];
            document.getElementById('edit_deadline').value = datePart;

            // --- LÓGICA VENCEDOR DINÂMICO ---
            const winnerSelect = document.getElementById('edit_winner');
            winnerSelect.innerHTML = ''; // Limpar opções

            // Pegar nomes dos selects de pilotos (hack visual)
            const p1Text = document.querySelector(`#edit_p1 option[value='${p1}']`)?.text || "P1 (ID " + p1 + ")";
            const p2Text = document.querySelector(`#edit_p2 option[value='${p2}']`)?.text || "P2 (ID " + p2 + ")";

            // Criar opções
            const opts = [
                { val: 'null', txt: '📝 Em Disputa (Padrão)' },
                { val: p1,     txt: '🏆 Vencedor: ' + p1Text },
                { val: p2,     txt: '🏆 Vencedor: ' + p2Text },
                { val: '0',    txt: '🤝 Empate' },
                { val: '-1',   txt: '⚠️ W.O. Duplo' }
            ];

            opts.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.val;
                opt.text = o.txt;
                winnerSelect.appendChild(opt);
            });
            
            // Selecionar valor atual (se null, winnerId vem vazio ou nulo)
            winnerSelect.value = (winnerId === null || winnerId === '') ? 'null' : winnerId;

            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
        
        function toggleEditGroupSelect(val) {
            document.getElementById('edit_group_container').classList.toggle('hidden', val !== 'F1');
        }

        // Modal de Clonagem
        function openCloneModal(id, phase, groupName, p1, p2, deadline) {
            document.getElementById('clone_match_id').value = id;
            document.getElementById('clone_match_title').innerText = "Clonar Partida (Origem: #" + id + ")";
             
            // Setar Fase
            const phaseSelect = document.getElementById('clone_phase');
            phaseSelect.value = phase;
            toggleCloneGroupSelect(phase);

            // Setar Grupo
            if (phase === 'F1' || phase === 'Fase de Grupos') {
                const groupNum = groupName.replace('Grupo ', '');
                document.getElementById('clone_group_num').value = groupNum;
            }

            // Setar Pilotos
            document.getElementById('clone_p1').value = p1;
            document.getElementById('clone_p2').value = p2;

            // Setar Prazo
            const datePart = deadline.split(' ')[0];
            document.getElementById('clone_deadline').value = datePart;

            document.getElementById('cloneModal').classList.remove('hidden');
        }

        function closeCloneModal() {
            document.getElementById('cloneModal').classList.add('hidden');
        }
        
        function toggleCloneGroupSelect(val) {
            document.getElementById('clone_group_container').classList.toggle('hidden', val !== 'F1');
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
                <h2 class="text-lg font-bold text-indigo-900">⚙️ Gerador de Partidas WhatsApp</h2>
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
                            <option value="<?= htmlspecialchars((string)($t['id'] ?? '')) ?>"><?= htmlspecialchars((string)($t['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. FASE -->
                <div class="p-5">
                    <label class="block text-xs font-bold text-gray-500 mb-2 uppercase">2. Fase</label>
                    <select name="phase" onchange="toggleGroupSelect(this.value)" class="block w-full border-gray-300 rounded border py-2 mb-3 text-sm">
                        <?php foreach ($fases as $f): ?><option value="<?= htmlspecialchars((string)($f['id'] ?? '')) ?>"><?= htmlspecialchars((string)($f['name'] ?? '')) ?></option><?php endforeach; ?>
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
                    <!-- CAMPO DE FILTRO (NOVO) -->
                    <div class="mb-2">
                        <input type="text" id="pilot_search" placeholder="🔍 Buscar piloto..." onkeyup="filterPilots()" class="w-full border-gray-300 rounded border py-1 px-2 text-xs focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div id="pilots_list" class="max-h-60 overflow-y-auto border border-gray-200 rounded bg-white p-2 space-y-1">
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
        <div class="flex items-center gap-3 mb-2">
            <h3 class="text-xl font-bold text-gray-800">📋 Partidas Ativas</h3>
            <span class="cursor-help text-gray-500 hover:text-indigo-600 transition-colors" title="A edição do local da corrida não é permitida. Caso necessário alterar o local, exclua a partida e crie-a novamente.">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </span>
            <span class="bg-gray-200 text-gray-600 text-xs px-2 py-1 rounded-full"><?= count($matches) ?> total</span>
        </div>

        <!-- CAMPO DE FILTRO PARTIDAS (NOVO) -->
        <div class="mb-6">
            <input type="text" id="match_search" placeholder="🔍 Filtrar partidas (ID, Piloto, Torneio...)" onkeyup="filterMatches()" class="w-full border-gray-300 rounded border py-2 px-3 text-xs focus:ring-indigo-500 focus:border-indigo-500">
        </div>

        <?php if (empty($viewMatches)): ?>
            <div class="text-center py-12 bg-white rounded-lg shadow-sm border border-gray-200 text-gray-500">
                <p class="text-lg">Nenhuma partida criada ainda.</p>
                <p class="text-sm">Use o gerador acima para começar.</p>
            </div>
        <?php else: ?>
            <div class="space-y-8">
            <?php foreach ($viewMatches as $torneioName => $fasesDoTorneio): ?>
                <div class="bg-white shadow-md rounded-lg overflow-hidden border border-gray-200 tournament-block">
                    <div class="bg-gray-800 text-white px-4 py-3 font-bold flex justify-between items-center">
                        <span class="tracking-wide"><?= $torneioName ?></span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase">
                                    <th class="px-4 py-3 font-semibold w-16">ID</th>
                                    <th class="px-4 py-3 font-semibold">Fase / Grupo</th>
                                    <th class="px-4 py-3 font-semibold">Player 1</th>
                                    <th class="px-4 py-3 font-semibold">Player 2</th>
                                    <th class="px-4 py-3 font-semibold">Local</th>
                                    <th class="px-4 py-3 font-semibold w-24">Prazo</th>
                                    <th class="px-4 py-3 font-semibold">Agendamento</th>
                                    <th class="px-4 py-3 font-semibold text-right w-24">Ações</th>
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
                                            $dtTimestamp = strtotime($sched['data_hora']);
                                            $dt = date('d/m H:i', $dtTimestamp);
                                            $quemPropos = getPilotNameDisplay($sched['proposed_by_pilot_id'], $pilotsMap);
                                            
                                            // Check for expiration (JOGO_NAO_REALIZADO logic)
                                            $isExpired = false;
                                            if ($sched['status'] == 'CONFIRMADO') {
                                                $windowEnd = $dtTimestamp + 1800; // +30 min
                                                if (time() > $windowEnd) {
                                                    $isExpired = true;
                                                }
                                            }

                                            if ($sched['status'] == 'PARTIDA_FINALIZADA') {
                                                $winId = $sched['result_winner_id'] ?? 0;
                                                $winName = $winId ? getPilotNameDisplay($winId, $pilotsMap) : 'Admin';
                                                if ($winId == 0) {
                                                    $winName = 'EMPATE';
                                                    $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                    <span class='bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-xs font-bold border border-emerald-200'>🏁 FINALIZADA</span>
                                                                    <span class='text-xs font-bold text-emerald-600'>🏆 {$winName}</span>
                                                                  </div>";
                                                } elseif ($winId == -1) $winName = 'W.O. Duplo';
                                                $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                <span class='bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-xs font-bold border border-emerald-200'>🏁 FINALIZADA</span>
                                                                <span class='text-xs font-bold text-emerald-600'>🏆 {$winName}</span>
                                                              </div>";
                                            } elseif ($sched['status'] == 'RESULTADO_EM_DISPUTA') {
                                                $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                <span class='bg-red-100 text-red-800 px-2 py-0.5 rounded text-xs font-bold border border-red-200 animate-pulse'>🚨 EM DISPUTA</span>
                                                                <span class='text-[10px] text-red-600 font-bold'>Verificar Logs!</span>
                                                              </div>";
                                            } elseif ($sched['status'] == 'RESULTADO_PROPOSTO') {
                                                $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                <span class='bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded text-xs font-bold border border-yellow-200'>📝 RESULTADO?</span>
                                                                <span class='text-[10px] text-yellow-600'>Aguardando Conf.</span>
                                                              </div>";
                                            } elseif ($sched['status'] == 'CONFIRMADO') {
                                                if ($isExpired) {
                                                    $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                    <span class='bg-gray-100 text-gray-500 px-2 py-0.5 rounded text-xs font-bold border border-gray-300'>❌ NÃO REALIZADO</span>
                                                                    <span class='text-xs text-red-400 line-through'>{$dt}</span>
                                                                    <span class='text-[9px] text-gray-400'>(Expirado)</span>
                                                                  </div>";
                                                } else {
                                                    $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                    <span class='bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs font-bold border border-green-200'>✅ CONFIRMADO</span>
                                                                    <span class='text-xs font-mono'>{$dt}</span>
                                                                  </div>";
                                                }
                                            } elseif ($sched['status'] == 'PROPOSTO') {
                                                $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                <span class='bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs font-bold border border-blue-200'>📅 PROPOSTO</span>
                                                                <span class='text-xs'>{$dt}</span>
                                                                <span class='text-[9px] text-gray-500'>por {$quemPropos}</span>
                                                              </div>";
                                            } else {
                                                // Padrão para qualquer outro status desconhecido
                                                $schedHtml = "<div class='flex flex-col items-start gap-1'>
                                                                <span class='bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs font-bold border border-blue-200'> Status Desconhecido</span>
                                                                <span class='text-xs'>{$dt}</span>
                                                              </div>";
                                            }
                                        }
                                        
                                        // Preparar ID do vencedor atual para o modal
                                        $currWinnerId = $m['winner_id'] ?? '';
                                        // Precisamos passar 'null' string se for nulo, para o JS entender
                                        $currWinnerJs = $currWinnerId === null ? 'null' : $currWinnerId;
                                    ?>
                                    <tr class="hover:bg-gray-50 transition-colors match-row">
                                        <td class="px-4 py-3 font-mono text-gray-500">#<?= $m['id'] ?></td>
                                        <td class="px-4 py-3"><?= $grpDisplay ?></td>
                                        <td class="px-4 py-3"><span class="font-medium text-indigo-900"><?= $pA ?></span></td>
                                        <td class="px-4 py-3"><span class="font-medium text-indigo-900"><?= $pB ?></span></td>
                                        <td class="px-4 py-3 text-xs max-w-[150px] truncate" title="<?= is_array($m['local_track']) ? implode(', ', $m['local_track']) : $m['local_track'] ?>">
                                            📍 <?= $localDisplay ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs font-mono text-red-600">
                                            <?= date('d/m', strtotime($m['deadline'])) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?= $schedHtml ?>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                <button onclick="openCloneModal(<?= $m['id'] ?>, '<?= $m['phase_id'] ?? $m['phase'] ?>', '<?= $m['group_name'] ?>', <?= $p1Id ?>, <?= $p2Id ?>, '<?= $m['deadline'] ?>')" class="text-green-500 hover:text-green-700 p-1 rounded hover:bg-green-50" title="Clonar Partida">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                                                    </svg>
                                                </button>
                                                <button onclick="openEditModal(<?= $m['id'] ?>, '<?= $m['phase_id'] ?? $m['phase'] ?>', '<?= $m['group_name'] ?>', <?= $p1Id ?>, <?= $p2Id ?>, '<?= $m['deadline'] ?>', '<?= $currWinnerJs ?>')" class="text-blue-500 hover:text-blue-700 p-1 rounded hover:bg-blue-50" title="Editar">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                    </svg>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Tem certeza que deseja EXCLUIR a partida #<?= $m['id'] ?>?');" class="inline">
                                                    <input type="hidden" name="delete_match_id" value="<?= $m['id'] ?>">
                                                    <button type="submit" class="text-red-500 hover:text-red-700 p-1 rounded hover:bg-red-50" title="Excluir">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </form>
                                            </div>
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

    <!-- MODAL DE EDIÇÃO -->
    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white w-full max-w-lg rounded-lg shadow-2xl flex flex-col overflow-hidden mx-4">
            <div class="bg-indigo-600 text-white px-4 py-3 flex justify-between items-center">
                <h3 class="font-bold text-lg" id="edit_match_title">Editar Partida</h3>
                <button onclick="closeEditModal()" class="text-white hover:text-gray-200 text-xl">&times;</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="edit_match_id" id="edit_match_id">
                
                <!-- Fase e Grupo -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Fase</label>
                    <select name="edit_phase" id="edit_phase" onchange="toggleEditGroupSelect(this.value)" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                       <?php foreach ($fases as $f): ?><option value="<?= htmlspecialchars((string)($f['id'] ?? '')) ?>"><?= htmlspecialchars((string)($f['name'] ?? '')) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div id="edit_group_container" class="hidden">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Número do Grupo</label>
                    <select name="edit_group_num" id="edit_group_num" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                        <?php for($g=1; $g<=8; $g++): ?><option value="<?= $g ?>"><?= $g ?></option><?php endfor; ?>
                    </select>
                </div>

                <!-- Pilotos -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Player 1</label>
                        <select name="edit_p1" id="edit_p1" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                            <?php foreach ($pilots as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nickname_TGC'] ?: $p['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Player 2</label>
                        <select name="edit_p2" id="edit_p2" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                            <?php foreach ($pilots as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nickname_TGC'] ?: $p['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Prazo -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Prazo Final</label>
                    <input type="date" name="edit_deadline" id="edit_deadline" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                </div>

                <!-- Vencedor / Resultado -->
                <div class="bg-gray-50 p-3 rounded border border-gray-200">
                    <label class="block text-xs font-bold text-gray-500 mb-1 uppercase tracking-wider">Definir Resultado</label>
                    <select name="edit_winner" id="edit_winner" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm font-bold text-indigo-800">
                        <!-- Populated via JS -->
                    </select>
                </div>

                <div class="pt-4 flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded">Cancelar</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE CLONAGEM -->
    <div id="cloneModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white w-full max-w-lg rounded-lg shadow-2xl flex flex-col overflow-hidden mx-4">
            <div class="bg-green-600 text-white px-4 py-3 flex justify-between items-center">
                <h3 class="font-bold text-lg" id="clone_match_title">Clonar Partida</h3>
                <button onclick="closeCloneModal()" class="text-white hover:text-gray-200 text-xl">&times;</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="clone_match_id" id="clone_match_id">
                
                <!-- Fase e Grupo -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Fase</label>
                    <select name="clone_phase" id="clone_phase" onchange="toggleCloneGroupSelect(this.value)" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm focus:ring-green-500 focus:border-green-500">
                        <?php foreach ($fases as $f): ?><option value="<?= htmlspecialchars((string)($f['id'] ?? '')) ?>"><?= htmlspecialchars((string)($f['name'] ?? '')) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div id="clone_group_container" class="hidden">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Número do Grupo</label>
                    <select name="clone_group_num" id="clone_group_num" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                        <?php for($g=1; $g<=8; $g++): ?><option value="<?= $g ?>"><?= $g ?></option><?php endfor; ?>
                    </select>
                </div>

                <!-- Pilotos -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Player 1</label>
                        <select name="clone_p1" id="clone_p1" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                            <?php foreach ($pilots as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nickname_TGC'] ?: $p['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Player 2</label>
                        <select name="clone_p2" id="clone_p2" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                            <?php foreach ($pilots as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nickname_TGC'] ?: $p['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Prazo -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Prazo Final</label>
                    <input type="date" name="clone_deadline" id="clone_deadline" class="block w-full border-gray-300 rounded border py-2 px-3 text-sm">
                </div>
                
                <div class="text-xs text-gray-500 italic mt-2">
                    * O local da corrida (Países/Pistas) será copiado da partida original.
                </div>

                <div class="pt-4 flex justify-end gap-2">
                    <button type="button" onclick="closeCloneModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded">Cancelar</button>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">Criar Cópia</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>