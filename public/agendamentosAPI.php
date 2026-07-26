<?php
/**
 * TGC - AGENDAMENTOS API
 * Abstração das funções do bot para integração externa via POST.
 */

// =================================================================================
// 1. SEGURANÇA, CONFIGURAÇÃO E LOGS
// =================================================================================

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/../storage/json');
define('LOG_DIR', BASE_DIR . '/../storage/logs');

define('FILE_PILOTS', DATA_DIR . '/pilots.json');
define('FILE_MATCHES', DATA_DIR . '/matches.json');
define('FILE_SCHEDULES', DATA_DIR . '/schedules.json');
define('FILE_AUDIT', DATA_DIR . '/auditSchedules.json');
define('FILE_LOG_API', LOG_DIR . '/agendamentosAPI.log');

function writeLog($msg, $data = null) {
    $date = date('Y-m-d H:i:s');
    $content = "[$date] $msg";
    if ($data !== null) {
        $content .= " | DADOS: " . (is_array($data) || is_object($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data);
    }
    file_put_contents(FILE_LOG_API, $content . PHP_EOL, FILE_APPEND);
}

// Carregar .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, ' "\'');
    }
}

$secretToken = $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? '';
$receivedToken = '';
$headerName = 'X-Telegram-Bot-API-Secret-Token';

// 1. Tenta via getallheaders (com verificação de existência e case-insensitive)
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, $headerName) === 0) {
            $receivedToken = $value;
            break;
        }
    }
}

// 2. Fallback via $_SERVER (Padrão para servidores que não suportam getallheaders)
if (empty($receivedToken)) {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    $receivedToken = $_SERVER[$serverKey] ?? '';
}

if (!$secretToken || $receivedToken !== $secretToken) {
    writeLog("ERRO SEGURANÇA: Token inválido ou ausente.");
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// =================================================================================
// 2. HELPERS (ADAPTADOS DO botMain.php)
// =================================================================================

function getJson($filepath) {
    if (!file_exists($filepath)) return [];
    return json_decode(file_get_contents($filepath), true) ?? [];
}

function saveJson($filepath, $data) {
    file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function getNextId($array) {
    if (empty($array)) return 1;
    return max(array_column($array, 'id')) + 1;
}

function getPilotByTgId($tgId) {
    $pilots = getJson(FILE_PILOTS);
    foreach ($pilots as $p) {
        if ($p['telegram_id'] == $tgId) return $p;
    }
    return null;
}

function getPilotById($id, $pilots = null) {
    if ($pilots === null) $pilots = getJson(FILE_PILOTS);
    foreach ($pilots as $p) {
        if ($p['id'] == $id) return $p;
    }
    return null;
}

function getPilotDisplayName($pilot) {
    if (!$pilot) return 'Desconhecido';
    return !empty($pilot['nickname_TGC']) ? $pilot['nickname_TGC'] : $pilot['nome'];
}

function isAdmin($tgId) {
    $admins = [880630967, 5857084855, 48568446];
    return in_array($tgId, $admins);
}

function formatLocal($localData) {
    if (empty($localData)) return "Livre escolha";
    if (is_string($localData)) {
        if ($localData === 'Livre') return "Livre escolha";
        $localData = explode(',', $localData);
    }
    if (!is_array($localData)) return (string)$localData;
    $firstItem = trim($localData[0] ?? '');
    if (preg_match('/^\d/', $firstItem)) {
        $output = "Sorteio Pistas:";
        foreach ($localData as $track) $output .= "\n    " . trim($track) . ",";
        return rtrim($output, ",");
    }
    return "Sorteio Países: " . implode(', ', $localData);
}

function getMatchSchedule($matchId) {
    $schedules = getJson(FILE_SCHEDULES);
    foreach ($schedules as $s) {
        if ($s['match_id'] == $matchId) return $s;
    }
    return null;
}

function saveAudit($matchId, $pilotId, $action, $details = '') {
    $audit = getJson(FILE_AUDIT);
    $audit[] = [
        'id' => getNextId($audit),
        'timestamp' => date('Y-m-d H:i:s'),
        'match_id' => $matchId,
        'pilot_id' => $pilotId,
        'action' => $action,
        'details' => $details
    ];
    saveJson(FILE_AUDIT, $audit);
}

function hasAuditAction($matchId, $action, $timeThreshold = null) {
    $audits = getJson(FILE_AUDIT);
    foreach ($audits as $a) {
        if ($a['match_id'] == $matchId && $a['action'] == $action) {
            if ($timeThreshold && strtotime($a['timestamp']) <= $timeThreshold) continue;
            return true;
        }
    }
    return false;
}

// =================================================================================
// 3. PROCESSAMENTO DO INPUT
// =================================================================================

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message']['from']['pilotID']) || !isset($input['message']['function'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid Input']);
    exit;
}

$pilotID = $input['message']['from']['pilotID'];
$function = trim($input['message']['function']);

// Mock do sendMessage para coletar a resposta em JSON limpo e formatado para WhatsApp
function respond($text, $data = null) {
    $responseArray = [
        'ok' => true,
        'response' => $text
    ];
    if ($data !== null) {
        $responseArray['data'] = $data;
    }
    echo json_encode($responseArray, JSON_UNESCAPED_UNICODE);
    exit;
}

// Verificação de Registro (Zona Protegida)
$publicFunctions = ['/inscrever', '/ajuda', '/ayuda', '/links', '/tutorial-ptbr', '/tutorial-es', '/tutorial'];
$isPublic = false;
foreach($publicFunctions as $pf) {
    if (stripos($function, $pf) === 0) { $isPublic = true; break; }
}

$currentPilot = getPilotByTgId($pilotID);
if (!$currentPilot && !isAdmin($pilotID) && !$isPublic) {
    respond("⚠️ Você não está inscrito. Use /inscrever ou veja /ajuda.");
}

if (!$currentPilot && isAdmin($pilotID)) {
    $currentPilot = ['id' => 0, 'nome' => 'Admin', 'nickname_TGC' => 'ADMIN'];
}

// =================================================================================
// 4. ROUTING DE COMANDOS
// =================================================================================

$cmd = explode(' ', strtolower($function))[0];

switch ($cmd) {
    case '/links':
        $msg = "🔗 *Links Úteis TGC:*\n\n";
        $msg .= "🏆 *Records + PolePosition:*\nhttps://topgearchampionships.com/dados/TGC-PolePosition.php\n\n";
        $msg .= "🌎 *Mundial de Pilotos:*\nhttps://docs.google.com/spreadsheets/d/182V9hE4Ok5bkkOCByqUzUFXy-J2MvM32_S8oxaQYBgA/view?gid=1400759616#gid=1400759616\n\n";
        $msg .= "🏁 *Envio Carro:*\nhttps://topgearchampionships.com/comissario/envio_la_liga.php\n\n";
        $msg .= "🕵️ *Logs Públicos:*\nhttps://topgearchampionships.com/comissario/log-publico.php";
        respond($msg);
        break;

    case '/ajuda':
    case '/tutorial-ptbr':
    case '/tutorial':
        $msg = "📚 *COMO AGENDAR SUAS PARTIDAS*\n\n";
        $msg .= "*1. VISUALIZAR PARTIDAS:* /partidas\n";
        $msg .= "*2. INICIAR AGENDAMENTO:* /agendar ID\n";
        $msg .= "*3. NO DIA DO JOGO:* /play ID\n";
        $msg .= "*4. INFORMAR VENCEDOR:* /resultado ID\n";
        $msg .= "\nUse os comandos acima para gerenciar seus jogos.";
        respond($msg);
        break;

    case '/ayuda':
    case '/tutorial-es':
        $msg = "📚 *CÓMO AGENDAR TUS PARTIDOS*\n\n";
        $msg .= "1. VER PARTIDOS: /partidas\n";
        $msg .= "2. INICIAR GESTIÓN: /agendar ID\n";
        $msg .= "3. EN EL DÍA DEL JUEGO: /play ID\n";
        $msg .= "4. INFORMAR GANADOR: /resultado ID";
        respond($msg);
        break;

    case '/inscrever':
        $pilots = getJson(FILE_PILOTS);
        foreach ($pilots as $p) { if ($p['telegram_id'] == $pilotID) respond("Você já está inscrito."); }
        $newPilot = [
            'id' => getNextId($pilots),
            'telegram_id' => $pilotID,
            'nome' => "Piloto API",
            'nickname_TGC' => "Piloto API",
            'ativo' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $pilots[] = $newPilot;
        saveJson(FILE_PILOTS, $pilots);
        respond("🏁 *Inscrição Realizada!*\n\nBem-vindo! Use /meuNick NovoNome para alterar seu nick.");
        break;

    case '/meunick':
        $args = trim(substr($function, 8));
        if (empty($args)) {
            $nick = getPilotDisplayName($currentPilot);
            respond("🆔 *Seu Nickname*\n\nAtualmente: *$nick*\n\nPara alterar: /meuNick SeuNovoNome");
        } else {
            $pilots = getJson(FILE_PILOTS);
            foreach ($pilots as &$p) {
                if ($p['telegram_id'] == $pilotID) {
                    if (isset($p['last_nick_change']) && strtotime($p['last_nick_change']) > strtotime('-90 days') && !isAdmin($pilotID)) {
                        respond("⚠️ Alteração bloqueada. Aguarde 90 dias entre mudanças.");
                    }
                    $p['nickname_TGC'] = $args;
                    $p['last_nick_change'] = date('Y-m-d H:i:s');
                    saveJson(FILE_PILOTS, $pilots);
                    respond("✅ Nickname alterado para: *$args*");
                }
            }
        }
        break;

    case '/partidas':
        $matches = getJson(FILE_MATCHES);
        $pilots = getJson(FILE_PILOTS);
        $myMatches = array_filter($matches, function($m) use ($currentPilot) {
            return ($m['player_1_id'] == $currentPilot['id'] || $m['player_2_id'] == $currentPilot['id'])
                && in_array($m['status'], ['PENDENTE', 'AGENDADO']);
        });
        if (empty($myMatches)) respond("Sem partidas pendentes.", []);

        $msg = "";
        $matchDataList = [];

        foreach ($myMatches as $m) {
            $p1 = getPilotById($m['player_1_id'], $pilots);
            $p2 = getPilotById($m['player_2_id'], $pilots);
            $p1Name = getPilotDisplayName($p1);
            $p2Name = getPilotDisplayName($p2);
            $sched = getMatchSchedule($m['id']);
            $status = $sched ? "📌 Status: {$sched['status']}" : "⚠️ Aguardando Agendamento";

            $msg .= "🆔 *Partida #{$m['id']}*\n👤 {$p1Name} vs {$p2Name}\n🏆 {$m['tournament']}\n{$status}\n\n";

            // Adicionando os dados estruturados no response caso o bot Node precise
            $matchDataList[] = [
                'id' => $m['id'],
                'p1_name' => $p1Name,
                'p2_name' => $p2Name,
                'tournament' => $m['tournament'],
                'schedule_status' => $sched ? $sched['status'] : 'PENDENTE'
            ];
        }
        respond(trim($msg), $matchDataList);
        break;

    case '/audit':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);
        if (!$matchId) respond("❌ Use: /audit ID");
        $audits = array_filter(getJson(FILE_AUDIT), function($a) use ($matchId) { return $a['match_id'] == $matchId; });
        if (empty($audits)) respond("📭 Nenhum registro para partida #$matchId");
        $msg = "🕵️‍♂️ *Auditoria Partida #$matchId*\n\n";

        $auditData = [];
        foreach ($audits as $a) {
            $p = getPilotById($a['pilot_id']);
            $nome = getPilotDisplayName($p);
            $time = date('d/m H:i', strtotime($a['timestamp']));

            // Tratamento Markdown para o WhatsApp (_Italico_ em vez de <i>)
            $msg .= "[$time] *{$nome}*: {$a['action']}\n_{$a['details']}_\n\n";

            $auditData[] = [
                'time' => $time,
                'pilot' => $nome,
                'action' => $a['action'],
                'details' => $a['details']
            ];
        }
        respond(trim($msg), $auditData);
        break;

    case '/play':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);
        if (!$matchId) respond("❌ Use: /play ID");
        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) { if ($m['id'] == $matchId) { $match = $m; break; } }
        if (!$match) respond("❌ Partida #$matchId não encontrada.");
        if ($match['player_1_id'] != $currentPilot['id'] && $match['player_2_id'] != $currentPilot['id'] && !isAdmin($pilotID)) {
            respond("❌ Você não participa desta partida.");
        }
        $sched = getMatchSchedule($matchId);
        if (!$sched || $sched['status'] !== 'CONFIRMADO') respond("⚠️ Partida não está confirmada para hoje.");

        $now = time();
        $dtTimestamp = strtotime($sched['data_hora']);
        if ($now < ($dtTimestamp - 1800)) respond("⏳ Muito cedo. A janela abre 30min antes do horário.");
        if ($now > ($dtTimestamp + 1800)) respond("❌ Horário expirado.");

        saveAudit($matchId, $currentPilot['id'], 'PLAYER_READY', 'Piloto notificou que está pronto via API');
        respond("✅ Você notificou que está pronto para a partida #$matchId!");
        break;

    case '/resultado':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);
        if (!$matchId) respond("❌ Use: /resultado ID");
        respond("🏁 Use o bot do Telegram para informar resultados detalhados ou acione um Admin.");
        break;

    case '/agendar':
        respond("📅 Agendamentos interativos devem ser feitos pelo Bot do WhatsApp.");
        break;

    default:
        respond("❓ Comando não reconhecido ou não suportado via API.");
        break;
}