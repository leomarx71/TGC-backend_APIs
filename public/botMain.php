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
        // pois alguns servidores bloqueiam getenv/putenv
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
    // Formato HTML para mencionar usuário pelo ID
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
}

function editMessageText($chatId, $messageId, $text, $keyboard = null) {
    writeLog("EDIT MESSAGE [INIT]: Chat $chatId | Msg $messageId");
    $data = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($keyboard) $data['reply_markup'] = $keyboard;
    $result = apiRequest("editMessageText", $data);
    if (!isset($result['ok']) || !$result['ok']) {
        writeLog("EDIT MESSAGE [FAIL]: " . ($result['description'] ?? 'Erro desconhecido'));
    }
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
    if (!$pilot) { answerCallbackQuery($cbId, "Você não está registrado."); exit; }

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
        
        $auditAction = 'PROPOSTO';
        if ($context == 'edit') $auditAction = 'REAGENDADO';
        if ($context == 'counter') $auditAction = 'REC_NOVAPROPOSTA';
        if ($context == 'resched') {
            $auditAction = 'REAGENDADO';
            $matches = getJson(FILE_MATCHES);
            foreach ($matches as &$mRef) { if ($mRef['id'] == $matchId) $mRef['status'] = 'PENDENTE'; }
            saveJson(FILE_MATCHES, $matches);
        }
        
        saveAudit($matchId, $pilot['id'], $auditAction, "Horário: $finalDateTime");

        $advId = ($match['pilot_a_id'] == $pilot['id']) ? $match['pilot_b_id'] : $match['pilot_a_id'];
        $advPilot = getPilotById($advId);
        
        // MENÇÕES COM LINK (Notificação em Grupo)
        $advNome = getPilotMention($advPilot);
        $meuNome = getPilotMention($pilot);

        $txtConfirm = "✅ <b>Proposta Registrada!</b>\n\n📅 Data: {$displayData}\n👤 Solicitante: <b>{$meuNome}</b>\n👤 Adversário: <b>{$advNome}</b>\n\nAguardando confirmação.";
        if ($context == 'resched') $txtConfirm = "🔄 <b>Reagendamento Solicitado!</b>\nNova data: {$displayData}\nAguardando confirmação.";

        editMessageText($chatId, $messageId, $txtConfirm);
        answerCallbackQuery($cbId, "Sucesso!");

        // Notificação Privada ao Adversário (Mantida como redundância garantida)
        if ($advPilot && $advPilot['telegram_id']) {
            $msgAdv = "🔔 <b>Nova Proposta: Partida #{$matchId}</b>\n\n📅 Data Sugerida: <b>{$displayData}</b>\n👤 Por: <b>{$meuNome}</b>\n\nUse <code>/agendar {$matchId}</code> para responder.";
            if ($context == 'counter') $msgAdv = "🔄 <b>Contra-Proposta Recebida: #{$matchId}</b>\n\nO adversário sugeriu novo horário:\n📅 <b>{$displayData}</b>\n\nUse <code>/agendar {$matchId}</code> para responder.";
            if ($context == 'resched') $msgAdv = "⚠️ <b>Solicitação de Reagendamento: #{$matchId}</b>\n\nNova data proposta: <b>{$displayData}</b>\n\nUse <code>/agendar {$matchId}</code> para confirmar.";
            sendMessage($advPilot['telegram_id'], $msgAdv);
        }

        // Notificação no Grupo Oficial (Se configurado)
        $groupMsg = "📅 <b>Nova Proposta de Agendamento</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n🏁 {$meuNome} 🆚 {$advNome}\n🕒 Sugestão: <b>{$displayData}</b>\n\n⚠️ <i>Aguardando confirmação.</i>";
        sendGroupMessage($groupMsg);
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
                $groupMsg = "🚫 <b>Agendamento Recusado</b>\n\n🆔 Partida: <b>#{$matchId}</b>\n🛑 Recusado por: {$meuNome}\n🕒 Proposta original de: {$propNome}";
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
if ($text === '/links') {
    $msg = "🔗 <b>Links Comissário:</b>\n\n";
    $msg .= "A - Link para: <a href='https://topgearchampionships.com/comissario/envio_la_liga.php'>[ENVIO CARRO FASE DE GRUPOS]</a>\n";
    $msg .= "B - Link para: <a href='https://topgearchampionships.com/comissario/envio.php'>[ENVIO CARRO FASE FINAL]</a>\n";
    $msg .= "C - Link para: <a href='https://topgearchampionships.com/comissario/log-publico.php'>[LOGS PÚBLICOS COMISSARIO]</a>";
    sendMessage($chatId, $msg);
    exit;
}

// /ajuda
if ($text === '/ajuda') {
    $msg = "🆘 <b>Comandos Bot Top Gear</b> 🇧🇷\n\n";
    $msg .= "🏁 <code>/inscrever-se</code>\n<i>Entrar no torneio.</i>\n\n";
    $msg .= "📋 <code>/partidas</code>\n<i>Ver suas partidas e IDs.</i>\n\n";
    $msg .= "📅 <code>/agendar ID</code>\n<i>Gerenciar agendamento.</i>\nEx: <code>/agendar 10</code>\n\n";
    $msg .= "🔗 <code>/links</code>\n<i>Ver links de envio e logs.</i>\n\n";
    $msg .= "🆔 <code>/meuNick Nome</code>\n<i>Alterar seu nome no jogo.</i>\nEx: <code>/meuNick AyrtonSenna</code>\n\n";
    $msg .= "ℹ️ <b>Nota:</b> Horários em Brasília (America/Sao_Paulo).";
    sendMessage($chatId, $msg);
    exit;
}

// /ayuda
if ($text === '/ayuda') {
    $msg = "🆘 <b>Comandos Bot Top Gear</b> 🇪🇸\n\n";
    $msg .= "🏁 <code>/inscrever-se</code>\n<i>Inscribirse en el torneo.</i>\n\n";
    $msg .= "📋 <code>/partidas</code>\n<i>Ver sus partidos e IDs.</i>\n\n";
    $msg .= "📅 <code>/agendar ID</code>\n<i>Gestionar horarios.</i>\nEj: <code>/agendar 10</code>\n\n";
    $msg .= "🔗 <code>/links</code>\n<i>Ver enlaces importantes.</i>\n\n";
    $msg .= "🆔 <code>/meuNick Nombre</code>\n<i>Cambiar su nombre en el juego.</i>\nEj: <code>/meuNick AyrtonSenna</code>\n\n";
    $msg .= "ℹ️ <b>Nota:</b> Horarios en Brasilia (America/Sao_Paulo).";
    sendMessage($chatId, $msg);
    exit;
}

// /inscrever-se (Renomeado)
if ($text === '/inscrever-se' || $text === '/registrar') { // Mantido /registrar como alias oculto por segurança
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
if (!$currentPilot) { 
    writeLog("ACESSO NEGADO: Usuário $userId tentou usar comando restrito: $text");
    sendMessage($chatId, "⚠️ Você não está inscrito. Use <code>/inscrever-se</code> ou veja <code>/ajuda</code>."); 
    exit; 
}

// ZONA PRIVADA

// /meuNick
if (strpos($text, '/meuNick') === 0) {
    $args = trim(substr($text, 8));
    if (empty($args)) {
        $nick = getPilotDisplayName($currentPilot);
        sendMessage($chatId, "🆔 <b>Seu Nickname</b>\n\nAtualmente: <b>{$nick}</b>\n\nPara alterar, digite:\n<code>/meuNick SeuNovoNome</code>");
    } else {
        $pilots = getJson(FILE_PILOTS);
        foreach ($pilots as &$p) {
            if ($p['id'] == $currentPilot['id']) {
                $p['nickname_TGC'] = $args;
                $currentPilot['nickname_TGC'] = $args;
                break;
            }
        }
        saveJson(FILE_PILOTS, $pilots);
        writeLog("NICK: Usuário {$currentPilot['id']} alterou nick para $args");
        sendMessage($chatId, "✅ Nickname alterado com sucesso para: <b>{$args}</b>");
    }
    exit;
}

// /audit ID
if (strpos($text, '/audit') === 0) {
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
if ($text === '/partidas') {
    $matches = getJson(FILE_MATCHES);
    $pilots = getJson(FILE_PILOTS);
    $schedules = getJson(FILE_SCHEDULES);
    $audits = getJson(FILE_AUDIT);
    
    $myMatches = [];
    foreach ($matches as $m) {
        if (($m['pilot_a_id'] == $currentPilot['id'] || $m['pilot_b_id'] == $currentPilot['id']) 
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
            $pA = getPilotById($m['pilot_a_id'], $pilots);
            $pB = getPilotById($m['pilot_b_id'], $pilots);
            $adversario = ($pA['id'] == $currentPilot['id']) ? getPilotDisplayName($pB) : getPilotDisplayName($pA);
            $prazo = date('d/m \à\s H:i', strtotime($m['deadline']));
            $local = formatLocal($m['local_track'] ?? null);
            $titulo = "{$m['tournament']} - {$m['phase']}";
            if ($m['group_name'] !== $m['phase'] && $m['phase'] == 'Fase de Grupos') $titulo .= " - {$m['group_name']}";

            $sched = getMatchSchedule($m['id']);
            $statusAgendamento = "⚠️ Aguardando Agendamento";
            if ($sched) {
                $dt = date('d/m H:i', strtotime($sched['data_hora']));
                $pName = getPilotDisplayName(getPilotById($sched['proposed_by_pilot_id'], $pilots));
                if ($sched['status'] == 'CONFIRMADO') $statusAgendamento = "✅ Agendado: {$dt}";
                elseif ($sched['status'] == 'RECUSADO') $statusAgendamento = "❌ Agendamento Recusado (Defina novo horário)";
                else $statusAgendamento = "📅 Proposta: {$dt} (por {$pName})";
            } else {
                $statusAgendamento = "📅 Proposta de Jogo em aberto (Use /agendar)";
            }
            
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

            $msg .= "🆔 <b>#{$m['id']}</b> vs {$adversario}\n🏆 {$titulo}\n🛣 {$local}\n⏳ Prazo: {$prazo}\n📌 Status: <b>{$statusAgendamento}</b>";
            if($logTxt) $msg .= "\n📋 Últimos eventos:{$logTxt}";
            $msg .= "\n\n";
        }
        $msg .= "Use <code>/agendar ID</code> para gerenciar.";
        sendMessage($chatId, $msg);
    }
    exit;
}

// /agendar ID
if (strpos($text, '/agendar') === 0) {
    $parts = explode(' ', $text);
    if (count($parts) < 2) { sendMessage($chatId, "❌ Use: <code>/agendar ID</code>"); exit; }
    
    $matchId = intval($parts[1]);
    $matches = getJson(FILE_MATCHES);
    $match = null;
    foreach ($matches as $m) if ($m['id'] == $matchId) { $match = $m; break; }

    if (!$match) { sendMessage($chatId, "❌ Partida não encontrada."); exit; }
    if ($match['pilot_a_id'] != $currentPilot['id'] && $match['pilot_b_id'] != $currentPilot['id']) {
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
        sendMessage($chatId, $msg, $keyboard);
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
        sendMessage($chatId, $msg, $keyboard);
    }
    exit;
}
?>