<?php
/*
 * TOP GEAR CHAMPIONSHIP BOT - MAIN HANDLER */

// =================================================================================
// 1. SEGURANÇA, CONFIGURAÇÃO E LOGS
// =================================================================================

// Configuração de Fuso Horário e Erros PHP
date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Definição de Diretórios
define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/../storage/json');
define('LOG_DIR', BASE_DIR . '/../storage/logs');

// Cria diretórios se não existirem
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
if (!is_dir(LOG_DIR)) mkdir(LOG_DIR, 0777, true);

// Arquivos
define('FILE_PILOTS', DATA_DIR . '/pilots.json');
define('FILE_MATCHES', DATA_DIR . '/matches.json');
define('FILE_SCHEDULES', DATA_DIR . '/schedules.json');
define('FILE_AUDIT', DATA_DIR . '/auditSchedules.json');
define('FILE_LOG', LOG_DIR . '/botMain.log');

// Função de Log
function writeLog($msg, $data = null) {
    $date = date('Y-m-d H:i:s');
    $content = "[$date] $msg";
    if ($data !== null) {
        $content .= " | DADOS: " . (is_array($data) || is_object($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data);
    }
    file_put_contents(FILE_LOG, $content . PHP_EOL, FILE_APPEND);
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
        
        // Tenta usar putenv, mas garante que $_ENV esteja populado
        @putenv("$key=$value"); 
        $_ENV[$key] = $value;
    }
}

// Token Secreto (Segurança) - Carregado via $_ENV
define('TELEGRAM_WEBHOOK_SECRET', isset($_ENV['TELEGRAM_WEBHOOK_SECRET']) ? $_ENV['TELEGRAM_WEBHOOK_SECRET'] : '');

// Token do Bot - Carregado via $_ENV
define('TELEGRAM_BOT_TOKEN', isset($_ENV['TELEGRAM_BOT_TOKEN']) ? $_ENV['TELEGRAM_BOT_TOKEN'] : '');

// ID do Grupo Principal para Notificações - Carregado via $_ENV
define('TELEGRAM_GROUP_ID', isset($_ENV['TELEGRAM_GROUP_ID']) ? $_ENV['TELEGRAM_GROUP_ID'] : '');

// Verificação do Header de Segurança
$headers = getallheaders();
$secret_header = null;
foreach ($headers as $key => $value) {
    if (strtolower($key) === 'x-telegram-bot-api-secret-token') {
        $secret_header = $value;
        break;
    }
}

// Verifica se o secret foi definido no env antes de comparar
if (!TELEGRAM_WEBHOOK_SECRET || $secret_header !== TELEGRAM_WEBHOOK_SECRET) {
    writeLog("ERRO SEGURANCA: Token secreto inválido ou ausente.", ['header_recebido' => $secret_header]);
    http_response_code(403);
    exit('Forbidden: Invalid Secret Token');
}

// =================================================================================
// 2. HELPERS (FUNÇÕES AUXILIARES)
// =================================================================================

function getJson($filepath) {
    if (!file_exists($filepath)) {
        writeLog("ALERTA: Arquivo não encontrado: $filepath");
        return [];
    }
    $content = file_get_contents($filepath);
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        writeLog("ERRO JSON: Falha ao decodificar $filepath", json_last_error_msg());
        return [];
    }
    return $data ?? [];
}

function saveJson($filepath, $data) {
    $result = file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($result === false) {
        writeLog("ERRO CRITICO: Falha ao salvar arquivo $filepath");
    }
}

function getNextId($array) {
    if (empty($array)) return 1;
    $ids = array_column($array, 'id');
    return max($ids) + 1;
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
    if (!empty($pilot['nickname_TGC'])) return $pilot['nickname_TGC'];
    return $pilot['nome'];
}

// Helper Novo: Retorna o nome com link de menção (Notifica em grupos)
function getPilotMention($pilot) {
    if (!$pilot) return 'Desconhecido';
    $name = !empty($pilot['nickname_TGC']) ? $pilot['nickname_TGC'] : $pilot['nome'];
    $tgId = $pilot['telegram_id'];
    
    // Ritchie (Computer ID 999) não tem link de telegram
    if (isset($pilot['id']) && $pilot['id'] == 999) return "<b>{$name}</b>";

    return "<a href=\"tg://user?id={$tgId}\">{$name}</a>";
}

function saveAudit($matchId, $pilotId, $action, $details = '') {
    $audit = getJson(FILE_AUDIT);
    $newEntry = [
        'id' => getNextId($audit),
        'timestamp' => date('Y-m-d H:i:s'),
        'match_id' => $matchId,
        'pilot_id' => $pilotId,
        'action' => $action,
        'details' => $details
    ];
    $audit[] = $newEntry;
    saveJson(FILE_AUDIT, $audit);
    writeLog("AUDIT: Novo registro salvo.", $newEntry);
}

// Verifica se já existe um audit específico para evitar flood (Ex: NINGUEM_APARECEU)
function hasAuditAction($matchId, $action, $timeThreshold = null) {
    $audits = getJson(FILE_AUDIT);
    foreach ($audits as $a) {
        if ($a['match_id'] == $matchId && $a['action'] == $action) {
            if ($timeThreshold) {
                if (strtotime($a['timestamp']) > $timeThreshold) return true;
            } else {
                return true;
            }
        }
    }
    return false;
}

// NOVO: Conta quantas vezes uma ação específica ocorreu numa partida (para numeração)
function getActionCount($matchId, $action) {
    $audits = getJson(FILE_AUDIT);
    $count = 0;
    foreach ($audits as $a) {
        if ($a['match_id'] == $matchId && $a['action'] == $action) {
            $count++;
        }
    }
    return $count;
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
    } else {
        return "Sorteio Países: " . implode(', ', $localData);
    }
}

function getMatchSchedule($matchId) {
    $schedules = getJson(FILE_SCHEDULES);
    foreach ($schedules as $s) {
        if ($s['match_id'] == $matchId) return $s;
    }
    return null;
}

// Verifica se a partida é contra o computador (ID 999 - Ritchie)
function isComputerMatch($match) {
    $p1Id = $match['player_1_id'] ?? null;
    $p2Id = $match['player_2_id'] ?? null;
    return ($p1Id == 999 || $p2Id == 999);
}

// --- ADMIN HELPERS ---
function getAdmins() {
    return [
        880630967,  // Admin 1
        5857084855, // Admin 2
        48568446    // Admin 3
    ];
}

function isAdmin($tgId) {
    return in_array($tgId, getAdmins());
}

// --- TELEGRAM API ---

function apiRequest($method, $parameters) {
    if (!is_string($method)) { 
        writeLog("API ERROR: Método inválido (não string).");
        return false; 
    }
    if (!$parameters) $parameters = [];
    
    writeLog("API SEND [PRE]: Tentando $method", $parameters);
    
    $ch = curl_init("https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/" . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        writeLog("API ERROR [CURL]: " . curl_error($ch));
    }
    
    curl_close($ch);
    writeLog("API RESPONSE [POS]: HTTP $httpCode | Resp: $response");
    return json_decode($response, true);
}

function sendMessage($chatId, $text, $keyboard = null) {
    writeLog("SEND MESSAGE [INIT]: Enviando para $chatId");
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($keyboard) $data['reply_markup'] = $keyboard;
    $result = apiRequest("sendMessage", $data);
    if (isset($result['ok']) && $result['ok']) {
        writeLog("SEND MESSAGE [SUCCESS]: MsgID: " . ($result['result']['message_id'] ?? '?'));
    } else {
        writeLog("SEND MESSAGE [FAIL]: Desc: " . ($result['description'] ?? 'Desconhecido'));
    }
    return $result; // Retorna o resultado para verificação de sucesso/falha
}

function editMessageText($chatId, $messageId, $text, $keyboard = null) {
    writeLog("EDIT MESSAGE [INIT]: Chat $chatId | Msg $messageId");
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($keyboard) $data['reply_markup'] = $keyboard;
    $result = apiRequest("editMessageText", $data);
    if (!isset($result['ok']) || !$result['ok']) {
        writeLog("EDIT MESSAGE [FAIL]: " . ($result['description'] ?? 'Erro desconhecido'));
    }
    return $result;
}

function answerCallbackQuery($callbackQueryId, $text = null) {
    $data = ['callback_query_id' => $callbackQueryId];
    if ($text) $data['text'] = $text;
    apiRequest("answerCallbackQuery", $data);
}

// Função Auxiliar para Notificar o Grupo (Se ID estiver definido)
function sendGroupMessage($text) {
    if (defined('TELEGRAM_GROUP_ID') && TELEGRAM_GROUP_ID) {
        // Envia mensagem para o grupo configurado
        sendMessage(TELEGRAM_GROUP_ID, $text);
    }
}

// =================================================================================
// 3. PROCESSAMENTO DE UPDATES
// =================================================================================

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') writeLog("ERRO INPUT: Recebido POST mas content vazio ou JSON inválido.");
    exit;
}

// ---------------------------------------------------------------------------------
// DETECÇÃO DE NOVOS MEMBROS NO GRUPO
// ---------------------------------------------------------------------------------
if (isset($update['message']['new_chat_members'])) {
    $newMembers = $update['message']['new_chat_members'];
    $chatId = $update['message']['chat']['id'];
    
    foreach ($newMembers as $member) {
        $userId = $member['id'];
        $firstName = $member['first_name'] ?? 'Piloto';
        
        // Não envia boas-vindas para bots
        if (isset($member['is_bot']) && $member['is_bot']) continue;
        
        $welcomeMsg = "🏁 <b>Bem-vindo ao Top Gear Championship, {$firstName}!</b> 🏁\n\n";
        $welcomeMsg .= "🚀 <b>COMECE AQUI:</b>\n\n";
        $welcomeMsg .= "1️⃣ <b>INSCREVA-SE</b>\n";
        $welcomeMsg .= "   <code>/inscrever</code>\n";
        $welcomeMsg .= "   👉 Registre-se no torneio e receba seu ID de piloto\n\n";
        
        $welcomeMsg .= "2️⃣ <b>VER SUAS PARTIDAS</b>\n";
        $welcomeMsg .= "   <code>/partidas</code>\n";
        $welcomeMsg .= "   👉 Liste todas as suas partidas ativas\n\n";
        
        $welcomeMsg .= "3️⃣ <b>AGENDAR UMA PARTIDA</b>\n";
        $welcomeMsg .= "   <code>/agendar ID</code>\n";
        $welcomeMsg .= "   👉 Exemplo: <code>/agendar 10</code>\n\n";
        
        $welcomeMsg .= "4️⃣ <b>MUDAR SEU NICKNAME</b>\n";
        $welcomeMsg .= "   <code>/meuNick SeuNome</code>\n";
        $welcomeMsg .= "   👉 Defina seu apelido no campeonato\n\n";
        
        $welcomeMsg .= "📚 <b>MAIS COMANDOS:</b>\n";
        $welcomeMsg .= "   <code>/ajuda</code> - Guia completo (PT-BR)\n";
        $welcomeMsg .= "   <code>/ayuda</code> - Guía completa (ES)\n";
        $welcomeMsg .= "   <code>/links</code> - Links do comissário\n\n";
        
        $welcomeMsg .= "🎮 <i>Boa sorte nas corridas!</i>";
        
        sendMessage($chatId, $welcomeMsg);
        writeLog("WELCOME: Mensagem de boas-vindas enviada para $firstName ($userId) no grupo $chatId");
    }
    exit;
}

// ---------------------------------------------------------------------------------
// A. TRATAMENTO DE CALLBACKS (BOTÕES) - Requer Login
// ---------------------------------------------------------------------------------
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $callbackData = $callback['data'];
    $userId = $callback['from']['id'];
    $cbId = $callback['id'];

    writeLog("CALLBACK: Usuário $userId acionou: $callbackData");

    $pilot = getPilotByTgId($userId);
    // Permite admins não cadastrados como pilotos operarem (se necessário), mas idealmente devem estar cadastrados
    // Se não for piloto e não for admin, bloqueia. Se for admin, $pilot pode ser null se não tiver cadastro.
    if (!$pilot && !isAdmin($userId)) { answerCallbackQuery($cbId, "Você não está registrado."); exit; }
    
    // Se for admin sem cadastro de piloto, cria um objeto dummy para log
    if (!$pilot && isAdmin($userId)) {
        $pilot = ['id' => 0, 'nome' => 'Administrador', 'nickname_TGC' => 'ADMIN', 'telegram_id' => $userId];
    }

    $parts = explode('|', $callbackData);
    $action = $parts[0] ?? '';
    $matchId = intval($parts[1] ?? 0);

    // [CALENDÁRIO]
    if ($action === 'calendar') {
        $context = $parts[2] ?? 'new';
        $buttons = [];
        $today = new DateTime(); 
        for ($i = 0; $i < 7; $i++) {
            $d = clone $today;
            $d->modify("+$i days");
            $val = $d->format('Y-m-d');
            $show = $d->format('d/m (D)');
            $buttons[] = [['text' => $show, 'callback_data' => "sel_date|$matchId|$val|$context"]];
        }
        $buttons[] = [['text' => "❌ Cancelar", 'callback_data' => "cancel_op|$matchId"]];
        $keyboard = ['inline_keyboard' => $buttons];
        $txtAction = ($context == 'resched') ? "Reagendamento" : (($context == 'counter') ? "Contra-proposta" : "Agendamento");
        editMessageText($chatId, $messageId, "📅 <b>{$txtAction} #{$matchId}</b>\nEscolha o dia:", $keyboard);
        answerCallbackQuery($cbId);
    }
    
    // [SELECIONAR DIA]
    if ($action === 'sel_date') {
        $selectedDate = $parts[2];
        $context = $parts[3] ?? 'new';
        $buttons = [];
        $row = [];
        $start = strtotime("$selectedDate 09:00:00");
        $end = strtotime("$selectedDate 23:45:00");
        for ($time = $start; $time <= $end; $time += 900) {
             $horaDisplay = date('H:i', $time);
             $fullTimestamp = date('Y-m-d H:i:s', $time);
             $row[] = ['text' => $horaDisplay, 'callback_data' => "sel_time|$matchId|$fullTimestamp|$context"];
             if (count($row) == 4) { $buttons[] = $row; $row = []; }
        }
        $nextDay = date('Y-m-d', strtotime("$selectedDate +1 day"));
        $startNext = strtotime("$nextDay 00:00:00");
        $endNext = strtotime("$nextDay 01:00:00");
        for ($time = $startNext; $time <= $endNext; $time += 900) {
             $horaDisplay = date('H:i', $time) . " (+1)";
             $fullTimestamp = date('Y-m-d H:i:s', $time);
             $row[] = ['text' => $horaDisplay, 'callback_data' => "sel_time|$matchId|$fullTimestamp|$context"];
             if (count($row) == 4) { $buttons[] = $row; $row = []; }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => "🔙 Voltar", 'callback_data' => "calendar|$matchId|$context"]];
        $keyboard = ['inline_keyboard' => $buttons];
        $diaFormatado = date('d/m', strtotime($selectedDate));
        editMessageText($chatId, $messageId, "🗓 Dia: <b>$diaFormatado</b>\n⏰ Escolha o horário:", $keyboard);
        answerCallbackQuery($cbId);
    }

    // [SELECIONAR HORA - SALVAR]
    if ($action === 'sel_time') {
        $finalDateTime = $parts[2];
        $context = $parts[3] ?? 'new';
        $displayData = date('d/m H:i', strtotime($finalDateTime));
        
        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) if ($m['id'] == $matchId) { $match = $m; break; }
        if (!$match) { answerCallbackQuery($cbId, "Erro: Partida não encontrada."); exit; }

        // --- VALIDAÇÃO DE HORÁRIO ---
        $selTimestamp = strtotime($finalDateTime);
        $nowTimestamp = time();
        $deadlineTimestamp = strtotime($match['deadline']);

        // 1. Mínimo 2 horas de antecedência
        if ($selTimestamp < ($nowTimestamp + 7200)) {
            answerCallbackQuery($cbId, "⚠️ Erro: Antecedência mínima de 2 horas necessária.");
            exit;
        }

        // 2. Não ultrapassar prazo final da partida
        if ($selTimestamp > $deadlineTimestamp) {
            answerCallbackQuery($cbId, "⚠️ Erro: A data excede o prazo final da partida.");
            exit;
        }

        $schedules = getJson(FILE_SCHEDULES);
        $cleanSchedules = [];
        $existingSched = null;
        foreach ($schedules as $s) {
            if ($s['match_id'] == $matchId) $existingSched = $s;
            else $cleanSchedules[] = $s;
        }
        
        $newSched = [
            'id' => ($existingSched ? $existingSched['id'] : getNextId($schedules)),
            'match_id' => $matchId,
            'proposed_by_pilot_id' => $pilot['id'],
            'data_hora' => $finalDateTime,
            'status' => 'PROPOSTO',
            'created_at' => ($existingSched ? $existingSched['created_at'] : date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
            'action_by_pilot_id' => null
        ];
        
        $cleanSchedules[] = $newSched;
        saveJson(FILE_SCHEDULES, $cleanSchedules);
        
        // --- LOGICA DE CONTADORES E ALERTAS DE ADMIN ---
        // Recupera contagem atual do histórico (antes de salvar o novo)
        $numResched = getActionCount($matchId, 'REAGENDADO');
        $numCounter = getActionCount($matchId, 'REC_NOVAPROPOSTA');

        $auditAction = 'PROPOSTO';
        $txtContext = "Nova Proposta";
        
        // Variável para controle de alerta
        $triggerAlert = false;
        $alertType = "";
        
        if ($context == 'edit' || $context == 'resched') { 
            $auditAction = 'REAGENDADO';
            $currCount = $numResched + 1;
            $txtContext = "Reagendamento $currCount";
            
            // Reagendamentos forçam status PENDENTE
            if ($context == 'resched') {
                $matches = getJson(FILE_MATCHES);
                foreach ($matches as &$mRef) { if ($mRef['id'] == $matchId) $mRef['status'] = 'PENDENTE'; }
                saveJson(FILE_MATCHES, $matches);
                $txtContext = "Solicitação de Reagendamento $currCount";
            }
            
            if ($currCount >= 7) { $triggerAlert = true; $alertType = "Reagendamentos excessivos ($currCount)"; }
        }
        
        if ($context == 'counter') { 
            $auditAction = 'REC_NOVAPROPOSTA';
            $currCount = $numCounter + 1;
            $txtContext = "Contra-Proposta $currCount";
            
            if ($currCount >= 7) { $triggerAlert = true; $alertType = "Contra-Propostas excessivas ($currCount)"; }
        }
        
        saveAudit($matchId, $pilot['id'], $auditAction, "Horário: $finalDateTime");

        // Identifica IDs estritamente por P1 e P2 (sem fallback)
        $p1Id = $match['player_1_id'] ?? null;
        $p2Id = $match['player_2_id'] ?? null;
        
        $advId = ($p1Id == $pilot['id']) ? $p2Id : $p1Id;
        $advPilot = getPilotById($advId);
        
        // MENÇÕES COM LINK (Notificação em Grupo)
        $advNome = getPilotMention($advPilot);
        $meuNome = getPilotMention($pilot);

        $txtConfirm = "✅ <b>Proposta Registrada!</b>\n\n📅 Data: {$displayData}\n👤 Solicitante: <b>{$meuNome}</b>\n👤 Adversário: <b>{$advNome}</b>\n\nAguardando confirmação.";
        if ($context == 'resched') $txtConfirm = "🔄 <b>Reagendamento Solicitado!</b>\nNova data: {$displayData}\nAguardando confirmação.";

        editMessageText($chatId, $messageId, $txtConfirm);
        answerCallbackQuery($cbId, "Sucesso!");

        // Notificação Privada ao Adversário
        if ($advPilot && $advPilot['telegram_id']) {
            $msgAdv = "📢 <b>Nova Proposta: Partida #{$matchId}</b>\n\n📅 Data Sugerida: <b>{$displayData}</b>\n👤 Por: <b>{$meuNome}</b>\n\nUse <code>/agendar {$matchId}</code> para responder.";
            if ($context == 'counter') $msgAdv = "🔄 <b>{$txtContext} Recebida: #{$matchId}</b>\n\nO adversário sugeriu novo horário:\n📅 <b>{$displayData}</b>\n\nUse <code>/agendar {$matchId}</code> para responder.";
            if ($context == 'resched') $msgAdv = "⚠️ <b>{$txtContext}: #{$matchId}</b>\n\nNova data proposta: <b>{$displayData}</b>\n\nUse <code>/agendar {$matchId}</code> para confirmar.";
            sendMessage($advPilot['telegram_id'], $msgAdv);
        }

        // Notificação no Grupo Oficial (Cobre propostas, contra-propostas e reagendamentos)
        $groupMsg = "📅 <b>{$txtContext}</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n🏁 {$meuNome} 🆚 {$advNome}\n🕐 Sugestão: <b>{$displayData}</b>\n\n⚠️ <i>Aguardando confirmação.</i>";
        sendGroupMessage($groupMsg);
        
        // --- ALERTA DE ADMIN (7ª Tentativa) ---
        if ($triggerAlert) {
            $admins = getAdmins();
            foreach ($admins as $adminId) {
                sendMessage($adminId, "🚨 <b>ALERTA DE LOOP: Partida #{$matchId}</b>\n\nO sistema detectou <b>{$alertType}</b>.\n\nPilotos: {$meuNome} e {$advNome}.\nPor favor, verifique se há um impasse no agendamento.");
            }
        }
    }

    // [CANCELAR OPERAÇÃO]
    if ($action === 'cancel_op') {
        editMessageText($chatId, $messageId, "❌ Operação cancelada.");
        answerCallbackQuery($cbId);
    }
    
    // [MANTER HORÁRIO]
    if ($action === 'btn_keep') {
        editMessageText($chatId, $messageId, "👍 <b>Ok, horário mantido.</b>");
        answerCallbackQuery($cbId);
    }
    
    // [RECUSAR]
    if ($action === 'btn_rej') {
        $schedules = getJson(FILE_SCHEDULES);
        $found = false;
        foreach ($schedules as &$s) {
            if ($s['match_id'] == $matchId) {
                $s['status'] = 'RECUSADO';
                $s['updated_at'] = date('Y-m-d H:i:s');
                $s['action_by_pilot_id'] = $pilot['id'];
                $found = true;
                break;
            }
        }
        if ($found) saveJson(FILE_SCHEDULES, $schedules);
        
        saveAudit($matchId, $pilot['id'], 'RECUSADO', "Recusou proposta.");
        editMessageText($chatId, $messageId, "🚫 <b>Proposta Recusada.</b>");
        answerCallbackQuery($cbId);
        
        $sched = getMatchSchedule($matchId); 
        if ($sched) {
            $proposerId = $sched['proposed_by_pilot_id'];
            if ($proposerId != $pilot['id']) {
                $proposer = getPilotById($proposerId);
                $meuNome = getPilotMention($pilot);
                $propNome = getPilotMention($proposer); // Para uso no log do grupo

                if ($proposer && $proposer['telegram_id']) {
                    sendMessage($proposer['telegram_id'], "🚫 <b>Proposta Recusada: Partida #{$matchId}</b>\n\n👤 Recusado por: <b>{$meuNome}</b>\n\nUse <code>/agendar {$matchId}</code> para enviar uma nova sugestão.");
                }

                // Notificação no Grupo Oficial
                $groupMsg = "🚫 <b>Agendamento Recusado</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n🛑 Recusado por: {$meuNome}\n🕐 Proposta original de: {$propNome}";
                sendGroupMessage($groupMsg);
            }
        }
    }

    // [CONFIRMAR]
    if ($action === 'btn_conf') {
        $schedules = getJson(FILE_SCHEDULES);
        $schedKey = null;
        foreach ($schedules as $k => $s) {
            if ($s['match_id'] == $matchId && $s['status'] == 'PROPOSTO') {
                $schedKey = $k; break;
            }
        }
        if ($schedKey === null) { answerCallbackQuery($cbId, "Proposta não encontrada."); exit; }
        if ($schedules[$schedKey]['proposed_by_pilot_id'] == $pilot['id']) { answerCallbackQuery($cbId, "Não pode confirmar sua própria proposta."); exit; }

        // --- REGRA DE SEGURANÇA: BLOQUEIO DE CONFIRMAÇÃO TARDIA ---
        $propTime = strtotime($schedules[$schedKey]['data_hora']);
        $now = time();
        
        // Bloqueia se faltar menos de 30min (1800s) para o horário proposto (OU se já passou)
        if ($now > ($propTime - 1800)) {
            $pName = getPilotDisplayName($pilot);
            
            // Registra Auditoria com nome do player
            saveAudit($matchId, $pilot['id'], 'ATRASADO para confirmação', "Piloto: {$pName} - Tentou confirmar fora da janela de segurança.");
            
            // Avisa o usuário e para
            editMessageText($chatId, $messageId, "🚫 <b>Tempo Esgotado!</b>\n\nNão é possível confirmar com menos de 30 minutos de antecedência ou após o horário.\n\nPor favor, proponha uma nova data.\nUse: <code>/agendar {$matchId}</code>");
            answerCallbackQuery($cbId, "Muito tarde para confirmar.");
            exit;
        }
        // -----------------------------------------------------------

        $schedules[$schedKey]['status'] = 'CONFIRMADO';
        $schedules[$schedKey]['updated_at'] = date('Y-m-d H:i:s');
        $schedules[$schedKey]['action_by_pilot_id'] = $pilot['id'];
        
        $matches = getJson(FILE_MATCHES);
        foreach ($matches as &$m) { if ($m['id'] == $matchId) $m['status'] = 'AGENDADO'; }
        
        saveJson(FILE_SCHEDULES, $schedules);
        saveJson(FILE_MATCHES, $matches);
        saveAudit($matchId, $pilot['id'], 'CONFIRMADO', "Data Confirmada");
        
        $dtDisplay = date('d/m H:i', strtotime($schedules[$schedKey]['data_hora']));
        $proposer = getPilotById($schedules[$schedKey]['proposed_by_pilot_id']);
        
        // MENÇÕES COM LINK
        $propNome = getPilotMention($proposer);
        $meuNome = getPilotMention($pilot);
        
        editMessageText($chatId, $messageId, "✅ <b>Agendamento Confirmado!</b>\n\n📅 Data: {$dtDisplay}\n👤 Solicitante: <b>{$propNome}</b>\n👤 Confirmado por: <b>{$meuNome}</b> (Você)");
        if ($proposer) {
            sendMessage($proposer['telegram_id'], "✅ <b>Confirmado! Partida #{$matchId}</b>\n\n📅 Data: {$dtDisplay}\n👤 Aceito por: <b>{$meuNome}</b>");
        }

        // Notificação no Grupo Oficial
        $groupMsg = "✅ <b>PARTIDA AGENDADA!</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n🏁 {$propNome} 🆚 {$meuNome}\n📅 Data: <b>{$dtDisplay}</b>\n\n🏆 <i>Boa sorte aos pilotos!</i>";
        sendGroupMessage($groupMsg);
    }
    
    // [RESULTADO - SELEÇÃO DE VENCEDOR]
    if ($action === 'res_win') {
        $winnerId = intval($parts[2] ?? 0);
        $match = null;
        $matches = getJson(FILE_MATCHES);
        $matchKey = null;
        foreach ($matches as $k => $m) { if ($m['id'] == $matchId) { $match = $m; $matchKey = $k; break; } }
        
        if (!$match) { answerCallbackQuery($cbId, "Partida não encontrada."); exit; }
        
        // Carrega Agendamento
        $schedules = getJson(FILE_SCHEDULES);
        $schedKey = null;
        foreach ($schedules as $k => $s) {
            if ($s['match_id'] == $matchId && $s['status'] != 'RECUSADO') { $schedKey = $k; break; }
        }
        
        if ($schedKey === null) { answerCallbackQuery($cbId, "Agendamento não encontrado."); exit; }
        
        $isAdm = isAdmin($userId);
        $currentUserPilotId = $pilot['id'];
        
        // --- TRATAMENTO DE EMPATE (ID 0) ---
        $winName = "";
        if ($winnerId == 0) {
            $winName = "EMPATE";
        } else {
            $winPilot = getPilotById($winnerId);
            $winName = getPilotDisplayName($winPilot);
        }
        $meuNome = getPilotMention($pilot);

        // -- LÓGICA 1: ADMIN FORCE (INTERVENÇÃO) --
        if ($isAdm) {
            $matches[$matchKey]['winner_id'] = $winnerId;
            $matches[$matchKey]['status'] = 'CONCLUIDO';
            
            $schedules[$schedKey]['status'] = 'PARTIDA_FINALIZADA';
            $schedules[$schedKey]['result_winner_id'] = $winnerId;
            $schedules[$schedKey]['result_confirmed_by'] = 'ADMIN_' . $userId;
            $schedules[$schedKey]['updated_at'] = date('Y-m-d H:i:s');
            
            saveJson(FILE_MATCHES, $matches);
            saveJson(FILE_SCHEDULES, $schedules);
            
            saveAudit($matchId, 0, 'RESULTADO confirmado por ADMIN', "Decidido por: {$pilot['nome']}");
            
            $resultLabel = ($winnerId == 0) ? "Resultado: 🤝 EMPATE" : "🏆 Vencedor: <b>{$winName}</b>";
            $resultShort = ($winnerId == 0) ? "EMPATE" : $winName;

            editMessageText($chatId, $messageId, "👮‍♂️ <b>Resultado Definido por Admin</b>\n\n{$resultLabel}\n\nPartida encerrada.");
            sendGroupMessage("👮‍♂️ <b>INTERVENÇÃO ADMINISTRATIVA</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n🏆 Resultado Final Definido.\n📌 {$resultLabel}\n\n✅ <i>Agendamento Encerrado.</i>");
            answerCallbackQuery($cbId, "Resultado forçado com sucesso.");
            exit;
        }

        // -- LÓGICA 2: SEGUNDA CONFIRMAÇÃO (CONSENSO OU DIVERGÊNCIA) --
        if (isset($schedules[$schedKey]['result_temp_winner']) && $schedules[$schedKey]['status'] == 'RESULTADO_PROPOSTO') {
            
            // Impede que o mesmo usuário confirme sua própria proposta (segurança extra, ja bloqueado no comando)
            if ($schedules[$schedKey]['result_proposal_by'] == $currentUserPilotId) {
                answerCallbackQuery($cbId, "Aguarde a confirmação do adversário.");
                exit;
            }

            $proposedWinner = intval($schedules[$schedKey]['result_temp_winner']);
            
            if ($winnerId === $proposedWinner) {
                // >> CONSENSO <<
                $matches[$matchKey]['winner_id'] = $winnerId;
                $matches[$matchKey]['status'] = 'CONCLUIDO'; // Opcional, mantendo lógica de agendamento principal
                
                $schedules[$schedKey]['status'] = 'PARTIDA_FINALIZADA';
                $schedules[$schedKey]['result_winner_id'] = $winnerId;
                $schedules[$schedKey]['result_confirmed_by'] = $currentUserPilotId;
                $schedules[$schedKey]['updated_at'] = date('Y-m-d H:i:s');
                
                // Limpa temporários
                unset($schedules[$schedKey]['result_temp_winner']);
                unset($schedules[$schedKey]['result_proposal_by']);

                saveJson(FILE_MATCHES, $matches);
                saveJson(FILE_SCHEDULES, $schedules);
                
                saveAudit($matchId, $currentUserPilotId, 'RESULTADO confirmado por consenso', "Confirmador: {$pilot['nome']}");
                
                $resultLabel = ($winnerId == 0) ? "Resultado: 🤝 EMPATE" : "🏆 Vencedor Oficial: <b>{$winName}</b>";
                
                editMessageText($chatId, $messageId, "🤝 <b>Consenso Atingido!</b>\n\n{$resultLabel}\n\nPartida finalizada com sucesso.");
                sendGroupMessage("🏁 <b>PARTIDA FINALIZADA</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n🤝 Resultado Confirmado por Consenso.\n📌 {$resultLabel}\n\n✅ <i>Agendamento Encerrado.</i>");
                answerCallbackQuery($cbId, "Confirmado!");

            } else {
                // >> DIVERGÊNCIA <<
                $schedules[$schedKey]['status'] = 'RESULTADO_EM_DISPUTA';
                $schedules[$schedKey]['updated_at'] = date('Y-m-d H:i:s');
                
                saveJson(FILE_SCHEDULES, $schedules);
                saveAudit($matchId, $currentUserPilotId, 'RESULTADO divergente – intervenção administrativa necessária', "Disputa iniciada por {$pilot['nome']}");
                
                editMessageText($chatId, $messageId, "⚠️ <b>Divergência Registrada!</b>\n\nVocê indicou um resultado diferente do seu oponente.\nO caso foi enviado para análise da administração.");
                
                // Notificar Admins
                $msgAdmin = "🚨 <b>ALERTA DE DISPUTA - Partida #{$matchId}</b>\n\nO piloto {$meuNome} contestou o resultado proposto.\nStatus alterado para: RESULTADO_EM_DISPUTA.\nIntervenção necessária.";
                $admins = getAdmins();
                foreach($admins as $adminId) sendMessage($adminId, $msgAdmin);
                
                sendGroupMessage("🚨 <b>RESULTADO EM DISPUTA</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n⚠️ Os pilotos informaram resultados diferentes.\n👮‍♂️ <i>O caso será analisado pela administração.</i>");
                answerCallbackQuery($cbId, "Divergência registrada.");
            }
            exit;
        }

        // -- LÓGICA 3: PRIMEIRA PROPOSTA --
        // Salva dados temporários no schedule
        $schedules[$schedKey]['result_temp_winner'] = $winnerId;
        $schedules[$schedKey]['result_proposal_by'] = $currentUserPilotId;
        $schedules[$schedKey]['status'] = 'RESULTADO_PROPOSTO';
        $schedules[$schedKey]['updated_at'] = date('Y-m-d H:i:s');
        
        saveJson(FILE_SCHEDULES, $schedules);
        saveAudit($matchId, $currentUserPilotId, 'RESULTADO informado – aguardando confirmação', "Vencedor sugerido: {$winName}");
        
        $propLabel = ($winnerId == 0) ? "Resultado indicado: 🤝 <b>EMPATE</b>" : "🏆 Vencedor indicado: <b>{$winName}</b>";

        editMessageText($chatId, $messageId, "📝 <b>Resultado Proposto</b>\n\n{$propLabel}\n\nAguardando confirmação do adversário.");
        
        // Notificar Adversário
        $p1Id = $match['player_1_id'];
        $p2Id = $match['player_2_id'];
        $advId = ($currentUserPilotId == $p1Id) ? $p2Id : $p1Id;
        $advPilot = getPilotById($advId);
        
        if ($advPilot && $advPilot['telegram_id']) {
            $msgPrivateAdv = ($winnerId == 0) 
                ? "📝 <b>Confirmação de Resultado: Partida #{$matchId}</b>\n\nO oponente indicou um <b>EMPATE</b>.\n\nUse <code>/resultado {$matchId}</code> para confirmar ou contestar."
                : "📝 <b>Confirmação de Resultado: Partida #{$matchId}</b>\n\nO oponente indicou que <b>{$winName}</b> venceu.\n\nUse <code>/resultado {$matchId}</code> para confirmar ou contestar.";
            
            sendMessage($advPilot['telegram_id'], $msgPrivateAdv);
        }
        
        // Notificação de instrução no grupo (conforme pedido)
        sendGroupMessage("📝 <b>RESULTADO PROPOSTO</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n👤 {$meuNome} informou o resultado.\n⚠️ <i>Aguardando confirmação do adversário via /resultado {$matchId}</i>");
        
        answerCallbackQuery($cbId, "Proposta enviada.");
    }
    
    exit;
}

// ---------------------------------------------------------------------------------
// B. TRATAMENTO DE TEXTO (COMANDOS)
// ---------------------------------------------------------------------------------
$message = $update['message'] ?? null;
if (!$message) exit;

$chatId = $message['chat']['id'];
$userId = $message['from']['id'];
$text   = trim($message['text'] ?? '');
$username = $message['from']['username'] ?? '';
$firstName = $message['from']['first_name'] ?? 'Piloto';

writeLog("MENSAGEM: Usuário $userId ($firstName) enviou: $text");

// ZONA PÚBLICA

// /links (Novo comando)
if (strcasecmp($text, '/links') === 0) {
    $msg = "🔗 <b>Links Úteis TGC:</b>\n\n";
    $msg .= "🏆 <b>Records + PolePosition:</b>\n<a href='https://topgearchampionships.com/dados/TGC-PolePosition.php'>Acessar Site</a>\n\n";
    $msg .= "🌎 <b>Mundial de Pilotos:</b>\n<a href='https://docs.google.com/spreadsheets/d/182V9hE4Ok5bkkOCByqUzUFXy-J2MvM32_S8oxaQYBgA/view?gid=1400759616#gid=1400759616'>Planilha Mundial</a>\n\n";
    $msg .= "🏁 <b>Envio Carro (Fase Grupos):</b>\n<a href='https://topgearchampionships.com/comissario/envio_la_liga.php'>Enviar Carro Comissário</a>\n\n";
    $msg .= "🏁 <b>Envio Carro (Fase Final):</b>\n<a href='https://topgearchampionships.com/comissario/envio.php'>Enviar Carro Comissário</a>\n\n";
    $msg .= "🕵️ <b>Logs Públicos:</b>\n<a href='https://topgearchampionships.com/comissario/log-publico.php'>Ver Auditoria Comissário</a>";
    sendMessage($chatId, $msg);
    exit;
}

// /tutorial-ptbr
if (strcasecmp($text, '/tutorial-ptbr') === 0 || strcasecmp($text, '/tutorial') === 0) {
    $msg = "📚 <b>COMO AGENDAR SUAS PARTIDAS</b>\n\n";
    $msg .= "<b>1. VISUALIZAR PARTIDAS:</b> Digite <code>/partidas</code>\n";
    $msg .= "O bot listará seus jogos com status:\n";
    $msg .= "🟢 DISPONÍVEL (Pronto p/ jogar)\n";
    $msg .= "⏳ PENDENTE (Aguardando ação)\n";
    $msg .= "❌ EXPIRADO (Necessita reagendar)\n\n";
    
    $msg .= "<b>2. INICIAR AGENDAMENTO:</b>\n";
    $msg .= "Digite <code>/agendar ID</code>\n";
    $msg .= "<i>Siga os passos no privado para escolher Data e Hora.</i>\n";
    $msg .= "⚠️ <b>Regra:</b> O horário deve ser marcado com no mínimo <b>2 horas de antecedência</b>.\n\n";

    $msg .= "<b>3. NO DIA DO JOGO (/play):</b>\n";
    $msg .= "Quando chegar a hora, use:\n";
    $msg .= "<code>/play ID</code>\n";
    $msg .= "✅ Janela Válida: <b>30 min antes até 30 min depois</b> do horário.\n";
    $msg .= "O bot avisará seu oponente que você está pronto!\n\n";
    
    $msg .= "<b>Cenários do /play:</b>\n";
    $msg .= "🔹 <b>Confirmado e no horário:</b> Avisa o grupo e oponente.\n";
    $msg .= "🔹 <b>Confirmado mas cedo demais:</b> Avisa para esperar.\n";
    $msg .= "🔹 <b>Passou 30min do horário:</b> Registra como não realizado (W.O. ou Reagendamento).\n";
    $msg .= "🔹 <b>Pendente (+24h):</b> Nudge no oponente para confirmar.";

    sendMessage($chatId, $msg);
    exit;
}

// /tutorial-es
if (strcasecmp($text, '/tutorial-es') === 0) {
    $msg = "📚 <b>CÓMO AGENDAR TUS PARTIDOS</b>\n\n";
    $msg .= "<b>1. VER PARTIDOS:</b> Escribe <code>/partidas</code>\n";
    $msg .= "El bot listará tus juegos con estado:\n";
    $msg .= "🟢 DISPONIBLE (Listo p/ jugar)\n";
    $msg .= "⏳ PENDIENTE (Esperando acción)\n";
    $msg .= "❌ EXPIRADO (Necesita reagendar)\n\n";

    $msg .= "<b>2. INICIAR GESTIÓN:</b>\n";
    $msg .= "Escribir <code>/agendar ID</code>\n";
    $msg .= "<i>Sigue los pasos en privado para elegir Fecha y Hora.</i>\n";
    $msg .= "⚠️ <b>Regla:</b> El horario debe marcarse con al menos <b>2 horas de antelación</b>.\n\n";

    $msg .= "<b>3. EN EL DÍA DEL JUEGO (/play):</b>\n";
    $msg .= "Cuando llegue la hora, usa:\n";
    $msg .= "<code>/play ID</code>\n";
    $msg .= "✅ Ventana Válida: <b>30 min antes hasta 30 min después</b> del horario.\n";
    $msg .= "¡El bot avisará a tu oponente que estás listo!\n\n";

    $msg .= "<b>Escenarios de /play:</b>\n";
    $msg .= "🔹 <b>Confirmado y a tiempo:</b> Avisa al grupo y oponente.\n";
    $msg .= "🔹 <b>Confirmado pero muy temprano:</b> Avisa para esperar.\n";
    $msg .= "🔹 <b>Pasaron 30min del horario:</b> Registra como no realizado (W.O. o Reagendamiento).\n";
    $msg .= "🔹 <b>Pendiente (+24h):</b> Recordatorio al oponente para confirmar.";

    sendMessage($chatId, $msg);
    exit;
}

// /ajuda
if (strcasecmp($text, '/ajuda') === 0 || strcasecmp($text, '/start') === 0 || strcasecmp($text, '/help') === 0) {
    $msg = "🆘 <b>Comandos Bot Top Gear</b> 🇧🇷\n\n";
    $msg .= "🏁 <code>/inscrever</code>\n<i>Entrar no torneio.</i>\n\n";
    $msg .= "📋 <code>/partidas</code>\n<i>Ver suas partidas e status.</i>\n\n";
    $msg .= "📅 <code>/agendar ID</code>\n<i>Propor horário (mín 2h antes).</i>\n\n";
    $msg .= "🎮 <code>/play ID</code>\n<i>Avisar que está pronto para jogar.</i>\n\n";
    $msg .= "🏆 <code>/resultado ID</code>\n<i>Informar quem venceu a partida.</i>\n\n";
    $msg .= "🔗 <code>/links</code>\n<i>Ver links de envio e logs.</i>\n\n";
    $msg .= "🆔 <code>/meuNick Nome</code>\n<i>Alterar seu nome no jogo.</i>\n\n";
    $msg .= "📚 <code>/tutorial</code>\n<i>Regras detalhadas de agendamento.</i>\n\n";
    $msg .= "ℹ️ <b>Dúvidas?</b> Chame @TopGearTGCBot no privado.";
    sendMessage($chatId, $msg);
    exit;
}

// /ayuda
if (strcasecmp($text, '/ayuda') === 0) {
    $msg = "🆘 <b>Comandos Bot Top Gear</b> 🇪🇸\n\n";
    $msg .= "🏁 <code>/inscrever</code>\n<i>Inscribirse en el torneo.</i>\n\n";
    $msg .= "📋 <code>/partidas</code>\n<i>Ver sus partidos y estados.</i>\n\n";
    $msg .= "📅 <code>/agendar ID</code>\n<i>Proponer horario (mín 2h antes).</i>\n\n";
    $msg .= "🎮 <code>/play ID</code>\n<i>Avisar que estás listo para jugar.</i>\n\n";
    $msg .= "🏆 <code>/resultado ID</code>\n<i>Informar ganador del partido.</i>\n\n";
    $msg .= "🔗 <code>/links</code>\n<i>Ver enlaces importantes.</i>\n\n";
    $msg .= "🆔 <code>/meuNick Nombre</code>\n<i>Cambiar su nombre en el juego.</i>\n\n";
    $msg .= "📚 <code>/tutorial-es</code>\n<i>Reglas detalladas.</i>\n\n";
    $msg .= "ℹ️ <b>Dudas?</b> Llama a @TopGearTGCBot en privado.";
    sendMessage($chatId, $msg);
    exit;
}

if (strcasecmp($text, '/inscrever-se') === 0 || strcasecmp($text, '/inscrever') === 0 || strcasecmp($text, '/registrar') === 0) {
    $pilots = getJson(FILE_PILOTS);
    foreach ($pilots as $p) { if ($p['telegram_id'] == $userId) { sendMessage($chatId, "Você já está inscrito."); exit; } }
    
    $newPilot = [
        'id' => getNextId($pilots),
        'telegram_id' => $userId,
        'username' => $username,
        'nome' => $firstName,
        'nickname_TGC' => $firstName,
        'ativo' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ];
    $pilots[] = $newPilot;
    saveJson(FILE_PILOTS, $pilots);
    writeLog("REGISTRO: Novo piloto cadastrado: $firstName (ID TG: $userId)");
    sendMessage($chatId, "🏁 <b>Inscrição Realizada!</b> 🏁\n\nBem-vindo, <b>{$firstName}</b>!\nSeu nick atual é: <b>{$firstName}</b>.\nUse <code>/meuNick NovoNome</code> se quiser alterar.");
    exit;
}

// ZONA PROTEGIDA
$currentPilot = getPilotByTgId($userId);
if (!$currentPilot && !isAdmin($userId)) { // Permite admins usarem comandos mesmo sem cadastro (edge case)
    writeLog("ACESSO NEGADO: Usuário $userId tentou usar comando restrito: $text");
    sendMessage($chatId, "⚠️ Você não está inscrito. Use <code>/inscrever</code> ou veja <code>/ajuda</code>."); 
    exit; 
}
// Se for admin sem cadastro, cria mock
if (!$currentPilot && isAdmin($userId)) $currentPilot = ['id' => 0, 'nome' => 'Admin', 'nickname_TGC' => 'ADMIN'];

// ZONA PRIVADA

// /meuNick
if (stripos($text, '/meunick') === 0) {
    $args = trim(substr($text, 8));
    if (empty($args)) {
        $nick = getPilotDisplayName($currentPilot);
        sendMessage($chatId, "🆔 <b>Seu Nickname</b>\n\nAtualmente: <b>{$nick}</b>\n\nPara alterar, digite:\n<code>/meuNick SeuNovoNome</code>");
    } else {
        $pilots = getJson(FILE_PILOTS);
        $updated = false;

        foreach ($pilots as &$p) {
            if ($p['id'] == $currentPilot['id']) {
                // REGRA DE 3 MESES
                if (isset($p['last_nick_change'])) {
                    $lastChange = new DateTime($p['last_nick_change']);
                    $limitDate = clone $lastChange;
                    $limitDate->modify('+90 days');
                    $now = new DateTime();

                    if ($now < $limitDate) {
                        $fmtDate = $limitDate->format('d/m/Y');
                        sendMessage($chatId, "⚠️ <b>Alteração Bloqueada</b>\n\nVocê alterou seu nickname recentemente. Pelas regras, deve-se aguardar 3 meses.\n\n📅 Liberado em: <b>{$fmtDate}</b>\n\n<i>Fale com um ADM se precisar alterar antes.</i>");
                        exit; 
                    }
                }

                $p['nickname_TGC'] = $args;
                $p['last_nick_change'] = date('Y-m-d H:i:s'); // Salva data da alteração
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            saveJson(FILE_PILOTS, $pilots);
            writeLog("NICK: Usuário {$currentPilot['id']} alterou nick para $args");
            sendMessage($chatId, "✅ Nickname alterado com sucesso para: <b>{$args}</b>\n\nℹ️ <i>Próxima alteração permitida em 90 dias.</i>");
        }
    }
    exit;
}

// /audit ID
if (stripos($text, '/audit') === 0) {
    $parts = explode(' ', $text);
    $matchId = intval($parts[1] ?? 0);
    
    writeLog("AUDIT COMMAND: Solicitado para Match ID: $matchId pelo Piloto {$currentPilot['id']}");

    if (!$matchId) { sendMessage($chatId, "❌ Use: <code>/audit ID</code>"); exit; }

    $audits = getJson(FILE_AUDIT);
    
    // Filtra forçando string para garantir comparação correta
    $matchAudits = array_filter($audits, function($a) use ($matchId) { 
        return strval($a['match_id']) === strval($matchId); 
    });
    
    if (empty($matchAudits)) {
        sendMessage($chatId, "📭 Nenhum registro para partida #$matchId");
    } else {
        $msg = "🕵️‍♂️ <b>Auditoria Partida #$matchId</b>\n\n";
        foreach ($matchAudits as $a) {
            $p = getPilotById($a['pilot_id']);
            $nome = getPilotDisplayName($p);
            $time = date('d/m H:i', strtotime($a['timestamp']));
            $msg .= "[$time] <b>{$nome}</b>: {$a['action']}\n<i>{$a['details']}</i>\n\n";
        }
        sendMessage($chatId, $msg);
    }
    exit;
}

// /partidas
if (strcasecmp($text, '/partidas') === 0) {
    $matches = getJson(FILE_MATCHES);
    $pilots = getJson(FILE_PILOTS);
    $schedules = getJson(FILE_SCHEDULES);
    $audits = getJson(FILE_AUDIT);
    
    $myMatches = [];
    foreach ($matches as $m) {
        $p1Id = $m['player_1_id'] ?? null;
        $p2Id = $m['player_2_id'] ?? null;
        
        if (($p1Id == $currentPilot['id'] || $p2Id == $currentPilot['id']) 
            && in_array($m['status'], ['PENDENTE', 'AGENDADO'])) {
            $myMatches[] = $m;
        }
    }
    
    if (empty($myMatches)) {
        sendMessage($chatId, "Sem partidas pendentes.");
    } else {
        usort($myMatches, function($a, $b) { return strcmp($a['deadline'], $b['deadline']); });
        
        $msg = "";
        foreach ($myMatches as $m) {
            $p1Id = $m['player_1_id'] ?? null;
            $p2Id = $m['player_2_id'] ?? null;
            
            $p1Name = getPilotDisplayName(getPilotById($p1Id, $pilots));
            $p2Name = getPilotDisplayName(getPilotById($p2Id, $pilots));
            
            $prazo = date('d/m \à\s H:i', strtotime($m['deadline']));
            $local = formatLocal($m['local_track'] ?? null);
            
            $titulo = "{$m['tournament']} - {$m['phase']}";
            if ($m['group_name'] !== $m['phase'] && $m['phase'] == 'Fase de Grupos') $titulo .= " - {$m['group_name']}";

            $sched = getMatchSchedule($m['id']);
            $statusAgendamento = "⚠️ Aguardando Agendamento";
            $iconTag = "⏳ PENDENTE";

            if ($sched) {
                $dtTimestamp = strtotime($sched['data_hora']);
                $dt = date('d/m H:i', $dtTimestamp);
                $pName = getPilotDisplayName(getPilotById($sched['proposed_by_pilot_id'], $pilots));
                
                if ($sched['status'] == 'CONFIRMADO') {
                    $now = time();
                    $windowStart = $dtTimestamp - 1800; // -30 min
                    $windowEnd = $dtTimestamp + 1800;   // +30 min

                    if ($now >= $windowStart && $now <= $windowEnd) {
                        $statusAgendamento = "✅ Agendado: {$dt}";
                        $iconTag = "🟢 DISPONÍVEL - JOGAR AGORA";
                    } elseif ($now > $windowEnd) {
                        // Passou 30 min e não foi jogado (ainda está como AGENDADO)
                        // Lógica Passiva: Registrar W.O./Reagendamento se ainda não houver audit
                        if (!hasAuditAction($m['id'], 'JOGO_NAO_REALIZADO', $dtTimestamp)) {
                            saveAudit($m['id'], $currentPilot['id'], 'JOGO_NAO_REALIZADO', 'Status atualizado via /partidas para Expirado');
                        }
                        $statusAgendamento = "⚠️ Agendado: {$dt} (Passou do horário)";
                        $iconTag = "❌ EXPIRADO - REAGENDAR";
                    } else {
                        $statusAgendamento = "✅ Agendado: {$dt}";
                        $iconTag = "✅ CONFIRMADO";
                    }
                } elseif ($sched['status'] == 'RECUSADO') {
                    $statusAgendamento = "❌ Agendamento Recusado (Defina novo horário)";
                    $iconTag = "❌ RECUSADO";
                } elseif ($sched['status'] == 'RESULTADO_PROPOSTO') {
                    $statusAgendamento = "🏁 Resultado Informado (Aguardando Confirmação)";
                    $iconTag = "🏁 CONFIRMAR RESULTADO";
                } elseif ($sched['status'] == 'RESULTADO_EM_DISPUTA') {
                     $statusAgendamento = "🚨 EM DISPUTA (Admin acionado)";
                     $iconTag = "🚨 DISPUTA";
                } else {
                    $statusAgendamento = "📅 Proposta: {$dt} (por {$pName})";
                    $iconTag = "⏳ PROPOSTA PENDENTE";
                }
            } else {
                $statusAgendamento = "📅 Proposta de Jogo em aberto (Use /agendar)";
                $iconTag = "⚠️ NÃO AGENDADO";
            }
            
            // Logs da partida
            $matchAudits = array_filter($audits, function($a) use ($m) { return strval($a['match_id']) === strval($m['id']); });
            usort($matchAudits, function($a, $b) { return strtotime($b['timestamp']) - strtotime($a['timestamp']); });
            $lastTwo = array_slice($matchAudits, 0, 2);
            $logTxt = "";
            foreach ($lastTwo as $l) {
                $pLog = getPilotById($l['pilot_id'], $pilots);
                $nLog = getPilotDisplayName($pLog);
                $tLog = date('d/m H:i', strtotime($l['timestamp']));
                $logTxt .= "\n   ▫️ {$tLog} {$nLog}: {$l['action']}";
            }

            $msg .= "🆔 <b>Partida #{$m['id']}</b> ({$iconTag})\n";
            $msg .= "👤 P1 <b>{$p1Name}</b> vs <b>{$p2Name}</b> P2 👤\n";
            $msg .= "🏆 {$titulo}\n";
            $msg .= "🛣 {$local}\n⏳ Prazo: {$prazo}\n📌 Status: <b>{$statusAgendamento}</b>";
            if($logTxt) $msg .= "\n📋 Últimos eventos:{$logTxt}";
            $msg .= "\n\n";
        }
        $msg .= "Use <code>/agendar ID</code> ou <code>/play ID</code> para gerenciar.\nPara informar vencedor: <code>/resultado ID</code>";
        sendMessage($chatId, $msg);
    }
    exit;
}

// /play ID | /jogar ID | /jugar ID
if (stripos($text, '/play') === 0 || stripos($text, '/jogar') === 0 || stripos($text, '/jugar') === 0) {
    $parts = explode(' ', $text);
    if (count($parts) < 2) { sendMessage($chatId, "❌ Use: <code>/play ID</code>"); exit; }
    
    $matchId = intval($parts[1]);
    $matches = getJson(FILE_MATCHES);
    $match = null;
    foreach ($matches as $m) if ($m['id'] == $matchId) { $match = $m; break; }

    if (!$match) { sendMessage($chatId, "❌ Partida não encontrada."); exit; }

    // Verifica se o usuário é participante
    $p1Id = $match['player_1_id'] ?? null;
    $p2Id = $match['player_2_id'] ?? null;
    
    if ($p1Id != $currentPilot['id'] && $p2Id != $currentPilot['id']) {
        sendMessage($chatId, "❌ Você não participa desta partida."); exit;
    }

    $sched = getMatchSchedule($matchId);
    
    // Regra 3: Sem agendamento ou não confirmado pelo usuário (quando aplicável)
    if (!$sched || $sched['status'] == 'RECUSADO') {
        sendMessage($chatId, "⚠️ <b>Agendamento Pendente</b>\n\nEsta partida ainda não tem um horário definido.\nUse <code>/agendar {$matchId}</code> primeiro.");
        exit;
    }

    $now = time();
    $schedTime = strtotime($sched['data_hora']);
    $windowStart = $schedTime - 1800; // -30 min
    $windowEnd = $schedTime + 1800;   // +30 min

    $meuNome = getPilotMention($currentPilot);
    $advId = ($p1Id == $currentPilot['id']) ? $p2Id : $p1Id;
    $advPilot = getPilotById($advId);
    $advNome = getPilotMention($advPilot);

    // 1. Agendamento CONFIRMADO – DENTRO da janela (30min +/-)
    if ($sched['status'] == 'CONFIRMADO' && $now >= $windowStart && $now <= $windowEnd) {
        saveAudit($matchId, $currentPilot['id'], 'DISPONIVEL', "No horário agendado");
        
        $msgPrivado = "🎮 <b>ESTOU PRONTO!</b>\n\nO piloto <b>{$meuNome}</b> está aguardando para iniciar a partida #{$matchId}.\nChame-o agora para correr!";
        $msgGrupo = "🟢 <b>JOGADOR DISPONÍVEL!</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n👤 <b>{$meuNome}</b> está online e pronto para correr!\n🆚 Adversário: {$advNome}\n\n<i>Que vença o melhor!</i> 🏁";

        if ($advPilot && $advPilot['telegram_id']) sendMessage($advPilot['telegram_id'], $msgPrivado);
        sendGroupMessage($msgGrupo);
        sendMessage($chatId, "✅ <b>Status Definido: DISPONÍVEL</b>\nSeu adversário e o grupo foram avisados.");
        exit;
    }

    // 4. Agendamento CONFIRMADO – ANTES da janela (> 30min antes)
    if ($sched['status'] == 'CONFIRMADO' && $now < $windowStart) {
        // Não registrar audit
        $diffMin = round(($windowStart - $now) / 60);
        sendMessage($chatId, "⏳ <b>Muito Cedo!</b>\n\nA partida está marcada para " . date('d/m H:i', $schedTime) . ".\nVocê só pode usar o comando /play a partir de 30 minutos antes do horário.\n\nFaltam cerca de {$diffMin} minutos para abrir a janela.");
        exit;
    }

    // 5. Agendamento CONFIRMADO – DEPOIS da janela (> 30min depois)
    if ($sched['status'] == 'CONFIRMADO' && $now > $windowEnd) {
        saveAudit($matchId, $currentPilot['id'], 'JOGO_NAO_REALIZADO', "Tentativa de play atrasada");
        
        $msg = "❌ <b>Horário Expirado</b>\n\nO tempo limite de tolerância (30min) para esta partida já passou.\nÉ necessário reagendar o jogo.\n\nUse <code>/agendar {$matchId}</code> para propor nova data (mínimo 2h de antecedência).";
        sendMessage($chatId, $msg);
        exit;
    }

    // 2. Agendamento NÃO CONFIRMADO (PROPOSTO) – Pendente > 24h
    if ($sched['status'] == 'PROPOSTO') {
        $proposalTime = strtotime($sched['created_at']); // Ou updated_at dependendo da lógica de re-proposta
        $isProposer = ($sched['proposed_by_pilot_id'] == $currentPilot['id']);
        
        // Se quem executa é quem propôs E faz mais de 24h
        if ($isProposer && ($now - $proposalTime) > 86400) {
            saveAudit($matchId, $currentPilot['id'], 'DISPONIVEL aguardando confirmação a mais de 24h', "Nudge enviado");
            
            $msgGrupo = "⏳ <b>AGUARDANDO CONFIRMAÇÃO (+24h)</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n👤 <b>{$meuNome}</b> está disponível, mas o agendamento ainda não foi confirmado por {$advNome}.\n\n⚠️ <i>Por favor, verifiquem suas pendências com /partidas</i>";
            
            sendGroupMessage($msgGrupo);
            sendMessage($chatId, "📢 <b>Alerta Enviado!</b>\nO grupo foi notificado sobre a pendência de confirmação.");
            
            if ($advPilot && $advPilot['telegram_id']) {
                sendMessage($advPilot['telegram_id'], "🔔 <b>Lembrete de Agendamento</b>\n\nA proposta para a partida #{$matchId} está pendente há mais de 24h.\nO oponente está aguardando.\nUse <code>/agendar {$matchId}</code> para responder.");
            }
            exit;
        }

        // 3. Comando executado por quem ainda não confirmou (Não é o propositor)
        if (!$isProposer) {
            // Não registrar audit
            sendMessage($chatId, "⚠️ <b>Ação Necessária</b>\n\nVocê ainda não confirmou o agendamento desta partida.\n\nUse <code>/agendar {$matchId}</code> e clique em <b>✅ Confirmar</b> antes de se declarar pronto para jogar.");
            exit;
        }
        
        // Caso genérico (ex: proponente tentando play antes de 24h sem confirmação)
        sendMessage($chatId, "⏳ <b>Aguardando Confirmação</b>\n\nSeu adversário ainda não confirmou o horário. Aguarde a confirmação por até 24h, caso ele não confirme envie o comando /play ID para notificar ele novamente e registrar na auditoria sua disponibilidade.");
        exit;
    }

    exit;
}

// /resultado ID
if (stripos($text, '/resultado') === 0) {
    $parts = explode(' ', $text);
    if (count($parts) < 2) { sendMessage($chatId, "❌ Use: <code>/resultado ID</code>"); exit; }
    
    $matchId = intval($parts[1]);
    $matches = getJson(FILE_MATCHES);
    $match = null;
    foreach ($matches as $m) if ($m['id'] == $matchId) { $match = $m; break; }

    if (!$match) { sendMessage($chatId, "❌ Partida não encontrada."); exit; }
    
    $p1Id = $match['player_1_id'] ?? null;
    $p2Id = $match['player_2_id'] ?? null;
    $isAdm = isAdmin($userId);
    
    // Verificação de Participante ou Admin
    if ($p1Id != $currentPilot['id'] && $p2Id != $currentPilot['id'] && !$isAdm) {
        sendMessage($chatId, "❌ Ação permitida apenas aos jogadores da partida ou administradores."); exit;
    }

    $sched = getMatchSchedule($matchId);
    
    // Validação de Status
    if (!$sched || $sched['status'] == 'PARTIDA_FINALIZADA') {
        sendMessage($chatId, "⚠️ <b>Partida Encerrada</b>\n\nO resultado desta partida já foi oficialmente registrado.\nCaso precise alterar, solicite ajuda à administração.");
        exit;
    }
    
    if ($sched['status'] == 'RESULTADO_EM_DISPUTA' && !$isAdm) {
        sendMessage($chatId, "🚫 <b>Bloqueio Administrativo</b>\n\nEsta partida está em análise por divergência de resultados.\nAguarde a decisão dos administradores.");
        exit;
    }

    // Regra de Tempo: Só permite /resultado APÓS o horário agendado
    $now = time();
    $schedTime = isset($sched['data_hora']) ? strtotime($sched['data_hora']) : 0;
    
    if ($now < $schedTime && !$isAdm) {
         sendMessage($chatId, "⏳ <b>Ainda não!</b>\n\nVocê só pode informar o resultado após o horário agendado da partida (" . date('d/m H:i', $schedTime) . ").");
         exit;
    }
    
    // Se o usuário já propôs e está aguardando, avisa (exceto se for admin forçando)
    if (isset($sched['result_proposal_by']) && $sched['result_proposal_by'] == $currentPilot['id'] && !$isAdm) {
        sendMessage($chatId, "⏳ <b>Aguarde a Confirmação</b>\n\nVocê já informou o resultado. O adversário precisa confirmar.\nCaso ele não confirme em breve, chame um administrador.");
        exit;
    }

    $p1 = getPilotById($p1Id);
    $p2 = getPilotById($p2Id);
    $nick1 = getPilotDisplayName($p1);
    $nick2 = getPilotDisplayName($p2);

    $msg = "🏆 <b>Resultado Oficial: Partida #{$matchId}</b>\n\nPor favor, informe quem venceu a disputa:";
    
    $buttons = [
        [['text' => "🏆 Vencedor: {$nick1}", 'callback_data' => "res_win|$matchId|$p1Id"]],
        [['text' => "🏆 Vencedor: {$nick2}", 'callback_data' => "res_win|$matchId|$p2Id"]],
        [['text' => "🤝 Empate", 'callback_data' => "res_win|$matchId|0"]],
        [['text' => "❌ Cancelar", 'callback_data' => "cancel_op|$matchId"]]
    ];

    sendMessage($chatId, $msg, ['inline_keyboard' => $buttons]);
    exit;
}

// /agendar ID
if (stripos($text, '/agendar') === 0) {
    $parts = explode(' ', $text);
    if (count($parts) < 2) { sendMessage($chatId, "❌ Use: <code>/agendar ID</code>"); exit; }
    
    $matchId = intval($parts[1]);
    $matches = getJson(FILE_MATCHES);
    $match = null;
    foreach ($matches as $m) if ($m['id'] == $matchId) { $match = $m; break; }

    if (!$match) { sendMessage($chatId, "❌ Partida não encontrada."); exit; }
    
    // --- NOVO: BLOQUEIO RITCHIE / POLE POSITION ---
    if (isComputerMatch($match)) {
        sendMessage($chatId, "🚫 <b>Atenção:</b> Não é necessário fazer esse agendamento, pois é uma partida de Pole Position.");
        exit;
    }
    
    $p1Id = $match['player_1_id'] ?? null;
    $p2Id = $match['player_2_id'] ?? null;
    
    if ($p1Id != $currentPilot['id'] && $p2Id != $currentPilot['id']) {
        sendMessage($chatId, "❌ Partida não é sua."); exit;
    }

    $sched = getMatchSchedule($matchId);
    $buttons = [];
    $msg = "";

    if (!$sched || $sched['status'] == 'RECUSADO') {
        $buttons[] = [['text' => "📅 Escolher Data e Hora", 'callback_data' => "calendar|$matchId|new"]];
        $msg = "📅 <b>Agendamento #$matchId</b>\n\nNenhuma proposta ativa no momento.\nToque abaixo para sugerir um horário.";
        if ($sched && $sched['status'] == 'RECUSADO') $msg = "📅 <b>Agendamento #$matchId</b>\n\nA última proposta foi recusada. Sugira um novo horário.";
        
        $keyboard = ['inline_keyboard' => $buttons];
        
        // Verifica se está em grupo para redirecionar ao privado
        if ($chatId != $userId) {
            $res = sendMessage($userId, $msg, $keyboard);
            if (isset($res['ok']) && $res['ok']) {
                sendMessage($chatId, "📬 <b>{$firstName}</b>, enviei as opções de agendamento no seu privado para não poluir o grupo.");
            } else {
                sendMessage($chatId, "⚠️ <b>{$firstName}</b>, não consegui enviar mensagem no seu privado.\nPor favor, me chame no privado primeiro (@TopGearTGCBot) e tente novamente.");
            }
        } else {
            sendMessage($chatId, $msg, $keyboard);
        }
    } 
    else {
        $dt = date('d/m H:i', strtotime($sched['data_hora']));
        $proposerId = $sched['proposed_by_pilot_id'];
        $isMeProposer = ($proposerId == $currentPilot['id']);
        
        // Uso da função de Menção para garantir notificação em grupo
        $pName = getPilotMention(getPilotById($proposerId));
        
        if ($sched['status'] == 'PROPOSTO') {
            if ($isMeProposer) {
                $msg = "⏳ <b>Proposta Enviada</b>\n\nVocê sugeriu: <b>{$dt}</b>\nAguardando resposta do adversário.";
                $buttons[] = [['text' => "✏️ Alterar/Reagendar Proposta", 'callback_data' => "calendar|$matchId|edit"]];
            } else {
                $msg = "🔔 <b>Proposta Recebida</b>\n\n👤 <b>{$pName}</b> sugeriu: <b>{$dt}</b>\n\nO que deseja fazer?";
                $buttons[] = [['text' => "✅ Confirmar", 'callback_data' => "btn_conf|$matchId"]];
                $buttons[] = [['text' => "🔄 Contra-proposta (Recusar e Sugerir)", 'callback_data' => "calendar|$matchId|counter"]];
                $buttons[] = [['text' => "🚫 Apenas Recusar", 'callback_data' => "btn_rej|$matchId"]];
            }
        }
        elseif ($sched['status'] == 'CONFIRMADO') {
            $msg = "✅ <b>Agendamento Confirmado</b>\n\n📅 Data: <b>{$dt}</b>\n\nDeseja manter ou reagendar?";
            $buttons[] = [['text' => "👍 Manter", 'callback_data' => "btn_keep|$matchId"]];
            $buttons[] = [['text' => "🔄 Reagendar (Propor nova data)", 'callback_data' => "calendar|$matchId|resched"]];
        }

        $keyboard = ['inline_keyboard' => $buttons];
        
        // Verifica se está em grupo para redirecionar ao privado
        if ($chatId != $userId) {
            $res = sendMessage($userId, $msg, $keyboard);
            if (isset($res['ok']) && $res['ok']) {
                sendMessage($chatId, "📬 <b>{$firstName}</b>, enviei as opções de agendamento no seu privado para não poluir o grupo.");
            } else {
                sendMessage($chatId, "⚠️ <b>{$firstName}</b>, não consegui enviar mensagem no seu privado.\nPor favor, me chame no privado primeiro (@TopGearTGCBot) e tente novamente.");
            }
        } else {
            sendMessage($chatId, $msg, $keyboard);
        }
    }
    exit;
}
?>