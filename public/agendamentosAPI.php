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

if (!defined('BASE_DIR')) define('BASE_DIR', __DIR__);
if (!defined('DATA_DIR')) define('DATA_DIR', BASE_DIR . '/../storage/json');
if (!defined('LOG_DIR')) define('LOG_DIR', BASE_DIR . '/../storage/logs');

if (!defined('FILE_PILOTS')) define('FILE_PILOTS', DATA_DIR . '/pilots.json');
if (!defined('FILE_MATCHES')) define('FILE_MATCHES', DATA_DIR . '/matches.json');
if (!defined('FILE_SCHEDULES')) define('FILE_SCHEDULES', DATA_DIR . '/schedules.json');
if (!defined('FILE_AUDIT')) define('FILE_AUDIT', DATA_DIR . '/auditSchedules.json');
if (!defined('FILE_LOG_API')) define('FILE_LOG_API', LOG_DIR . '/agendamentosAPI.log');

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

function getTelegramIdByPilotId($pilotId) {
    $pilots = getJson(FILE_PILOTS);
    foreach ($pilots as $p) {
        if ($p['id'] == $pilotId) return $p['telegram_id'];
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

function getPilotDisplayNameByNick($pilot) {
    if (!$pilot) return 'Desconhecido';
    return !empty($pilot['nickname_TGC']) ? $pilot['nickname_TGC'] : $pilot['nome'];
}

function getPilotDisplayName($pilot) {
    return getPilotDisplayNameByNick($pilot);
}

function isAdmin($tgId) {
    $admins = [5511993499981, 5561981356228, 5516992909090];
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

// Verifica se a partida é contra o computador (ex: Ritchie / Pole Position)
function isComputerMatch($match) {
    // Retorna true se um dos IDs for <= 0 (geralmente bots) ou se o torneio tiver 'Pole' no nome
    return ($match['player_1_id'] <= 0 || $match['player_2_id'] <= 0 || stripos($match['tournament'], 'Pole') !== false);
}

// =================================================================================
// 3. PROCESSAMENTO DO INPUT
// =================================================================================

header('Content-Type: application/json');
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    $rawInput = @file_get_contents('php://stdin');
}
$input = json_decode($rawInput, true);

if (!$input || !isset($input['message']['from']['pilotID']) || !isset($input['message']['from']['pilotName']) || !isset($input['message']['function'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid Input']);
    exit;
}

$pilotID = $input['message']['from']['pilotID'];
$pilotName = $input['message']['from']['pilotName'];
$function = trim($input['message']['function']);
$bookDate = $input['message']['bookDate'] ?? null;
$bookTime = $input['message']['bookTime'] ?? null;

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
        $msg .= "🏁 *Envio Comissário La Liga:*\nhttps://topgearchampionships.com/comissario/envio_la_liga.php\n\n";
        $msg .= "🏁 *Envio Comissário Normal:*\nhttps://topgearchampionships.com/comissario/envio.php\n\n";
        $msg .= "🕵️ *Logs Públicos:*\nhttps://topgearchampionships.com/comissario/log-publico.php";
        respond($msg);

    case '/ajuda':
        $msg = "📚 *COMO AGENDAR SUAS PARTIDAS*\n\n";
        $msg .= "*1. VER SUAS PARTIDAS:* " . "'/partidas'" . "\n";
        $msg .= "*2. INICIAR AGENDAMENTO:* /agendar ID\n";
        $msg .= "*3. NO DIA DO JOGO:* /play ID\n";
        $msg .= "\nUse os comandos acima para gerenciar seus jogos.";
        respond($msg);

    case '/ayuda':
        $msg = "📚 *CÓMO AGENDAR TUS PARTIDOS*\n\n";
        $msg .= "*1. VER SUS PARTIDOS:* " . "'/partidas'" . "\n";
        $msg .= "*2. INICIAR GESTIÓN:* /agendar ID\n";
        $msg .= "*3. EN EL DÍA DEL JUEGO:* /play ID";
        respond($msg);

    case '/inscrever':
        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Este comando não pode ser utilizado em grupos.\n\n" .
                "Envie uma mensagem privada para o TopGearTGCBot +351935525827 e execute o comando por lá.",
                []
            );
        }
        $pilots = getJson(FILE_PILOTS);
        foreach ($pilots as $p) { if ($p['telegram_id'] == $pilotID) respond("Você já está inscrito."); }
        $newPilot = [
            'id' => getNextId($pilots),
            'telegram_id' => $pilotID,
            'nome' => "Piloto API",
            'nickname_TGC' => "Piloto_API",
            'ativo' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $pilots[] = $newPilot;
        saveJson(FILE_PILOTS, $pilots);
        respond("🏁 *Inscrição Realizada!*\n\nBem-vindo! Use /meuNick NovoNome para alterar seu nick.");

    case '/meunick':
        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Este comando não pode ser utilizado em grupos.\n\n" .
                "Envie uma mensagem privada para o TopGearTGCBot +351935525827 e execute o comando por lá.",
                []
            );
        }
        $args = trim(substr($function, 8));
        if (empty($args)) {
            $nick = getPilotDisplayNameByNick($currentPilot);
            respond("🆔 *Seu Nickname*\n\nAtualmente: *$nick*\n\nPara alterar: /meuNick SeuNovoNome\n\n⚠️ Ao mudar a alteração bloqueada pelos 90 dias.");
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

        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Este comando não pode ser utilizado em grupos.\n\n" .
                "Envie uma mensagem privada para o TopGearTGCBot +351935525827 e execute o comando por lá.",
                []
            );
        }

        $myMatches = array_filter($matches, function($m) use ($currentPilot) {
            return ($m['player_1_id'] == $currentPilot['id'] || $m['player_2_id'] == $currentPilot['id'])
                && in_array($m['status'], ['PENDENTE', 'AGENDADO', 'PROPOSTO', 'CONFIRMADO']);
        });

        if (empty($myMatches)) {
            respond("Sem partidas pendentes.", []);
        }

        $msg = "";
        $lastKey = array_key_last($myMatches);

        foreach ($myMatches as $key => $m) {
            $p1 = getPilotById($m['player_1_id'], $pilots);
            $p2 = getPilotById($m['player_2_id'], $pilots);
            $p1Name = getPilotDisplayNameByNick($p1);
            $p2Name = getPilotDisplayNameByNick($p2);
            $sched = getMatchSchedule($m['id']);
            $status = $sched ? "{$sched['status']}" : "⚠️ Aguardando Agendamento";
            $prazo = date('d/m \à\s H:i', strtotime($m['deadline']));
            $local = formatLocal($m['local_track'] ?? null);
            $titulo = "{$m['tournament']} - {$m['phase']}";
            if ($m['group_name'] !== $m['phase'] && $m['phase'] == 'Fase de Grupos') $titulo .= " - {$m['group_name']}";

            $msg .= "🆔 *Partida #{$m['id']}*\n👤 {$p1Name} vs {$p2Name} 👤\n🏆 {$titulo}\n⏳ Prazo Final: {$prazo}\n📌 Status: {$status}\n🛣 {$local}\n\n";
            $msg .= "Use */agendar ID* ou */play ID* para gerenciar.\n\n";

            if ($key !== $lastKey) {
                $msg .= "\n[NEXT]\n";
            }
        }
        respond(trim($msg));

    case '/usernumber':
        $pilots = getJson(FILE_PILOTS);
        foreach ($pilots as $p) {
            if ($p['nome'] == $pilotName) {
                $pilotID = $p['telegram_id'];
                respond($pilotID);
            } else {
                $pilotID=351935525827;
            }
        }
        respond($pilotID);

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
            $nome = getPilotDisplayNameByNick($p);
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

    case '/play':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);
        if (!$matchId) respond("❌ Use: /play ID");

        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) { if ($m['id'] == $matchId) { $match = $m; break; } }
        if (!$match) respond("❌ Partida #$matchId não encontrada.");

        $p1Id = $match['player_1_id'] ;
        $p2Id = $match['player_2_id'] ;
        $opponent_id = null;

        if ($p1Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p2Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p2Id));
        } else if ($p2Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p1Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p1Id));
        }

        $responseData = [
            'match_id' => $matchId,
            'state' => '',
            'nickname' => $nickname,
            'opponent_id' => $opponent_id,
            'data_hora' => '',
            'tournament' => $match['tournament'],
        ];

        if ($match['player_1_id'] != $currentPilot['id'] && $match['player_2_id'] != $currentPilot['id'] && !isAdmin($pilotID)) {
            respond("❌ Você não participa desta partida.");
        }
        $sched = getMatchSchedule($matchId);

        if (!$sched) respond("❌ Não há agendamentos propostos ou confirmados para a partida #$matchId.\n\nUse /agendar ID para agendar.");

        $now = time();
        $dtTimestamp = strtotime($sched['data_hora']);
        $formattedTime = date('d/m H:i', $dtTimestamp);
        $responseData['data_hora'] = $formattedTime;

        // Primeiro verifica a janela de tempo, independentemente do status
        if ($now < ($dtTimestamp - 1800)) {
            $responseData['state'] = 'PLAY_MUITO_CEDO';
            respond("⏳ Muito cedo.\nPartida está agendada para $formattedTime\n\nA janela de *play* abre de 30min antes do horário até 30min depois do horário.", $responseData);
        }
        if ($now > ($dtTimestamp + 1800)) {
            saveAudit($matchId, $currentPilot['id'], 'JOGADOR_ATRASADO', 'Piloto tentou notificar disponibilidade após o horário agendado');
            $responseData['state'] = 'JOGADOR_ATRASADO';
            respond("❌ Oops, Muito tarde.\n⏳ Horário do play expirado.\nA partida estava agendada para $formattedTime\n\nUse /agendar ID para agendar novamente.", $responseData);
        }

        // Está dentro da janela de ±30 minutos. Agora verifica o status.
        if ($sched['status'] == 'CONFIRMADO') {
            saveAudit($matchId, $currentPilot['id'], 'JOGADOR_PRONTO', 'Piloto compareceu corretamente no horário proposto');
            $responseData['state'] = 'JOGADOR_PRONTO';
            respond("✅ *Você chegou no horário!*\n\nNotificando o oponente que você está pronto para a partida\nFique disponível para a resposta dele até o fim do período da janela de agendamento. \n\nPartida: 🆔 #$matchId!\nData Agendada: $formattedTime ", $responseData);
        }
        if ($sched['status'] == 'PROPOSTO') {
            $responseData['state'] = 'JOGADOR_PRONTO_SEM_AGENDAMENTO';
            saveAudit($matchId, $currentPilot['id'], 'JOGADOR_PRONTO_SEM_AGENDAMENTO', 'Piloto compareceu no horário proposto, mas o oponente ainda não tinha confirmado');
            respond("❌ O agendamento não havia sido confirmado pelo seu oponente para a partida #$matchId.\nRegistrei sua presença e disponibilidade no horário proposto.\n\nUse */agendar ID* para agendar novamente.", $responseData);
        }

        // Está dentro da janela, mas o status não permite /play
        $responseData['state'] = 'STATUS_PLAY_DESCONHECIDO';
        respond("❌ O status atual para a partida #$matchId não permite play", $responseData);

    case '/resultado':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);
        if (!$matchId) {
            respond("❌ Use: /resultado ID", ['state' => 'ERRO_FALTA_ID']);
        }

        $isAdm = isAdmin($pilotID);
        if (!$isAdm) {
            respond("❌ Apenas administradores podem informar o resultado de uma partida.", ['state' => 'ERRO_APENAS_ADMIN']);
        }

        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) {
            if ($m['id'] == $matchId) {
                $match = $m;
                break;
            }
        }

        if (!$match) {
            respond("❌ Partida não encontrada. \n\nRevise o número com o */partidas*", ['state' => 'ERRO_NAO_ENCONTRADO']);
        }

        // Bloqueio Ritchie / Pole Position
        if (isComputerMatch($match)) {
            respond("🚫 *Atenção:* Não é necessário informar resultado para partida de Pole Position (contra o Ritchie / Computador).", ['state' => 'ERRO_PARTIDA_COMPUTADOR']);
        }

        $p1Id = $match['player_1_id'] ?? null;
        $p2Id = $match['player_2_id'] ?? null;
        $p1 = getPilotById($p1Id);
        $p2 = getPilotById($p2Id);
        $nick1 = getPilotDisplayNameByNick($p1);
        $nick2 = getPilotDisplayNameByNick($p2);
        $p1Tg = getTelegramIdByPilotId($p1Id);
        $p2Tg = getTelegramIdByPilotId($p2Id);

        // Se passar apenas /resultado ID (sem argumento de vencedor/empate/woduplo)
        $winnerInput = isset($parts[2]) ? trim(implode(' ', array_slice($parts, 2))) : ($input['message']['winner'] ?? ($input['message']['winnerId'] ?? null));

        if ($winnerInput === null || $winnerInput === '') {
            $responseData = [
                'match_id' => $matchId,
                'player_1' => [
                    'id' => $p1Id,
                    'name' => $nick1,
                    'telegram_id' => $p1Tg
                ],
                'player_2' => [
                    'id' => $p2Id,
                    'name' => $nick2,
                    'telegram_id' => $p2Tg
                ],
                'state' => 'REQUER_RESULTADO_ADMIN'
            ];

            $msg = "🏆 *Definir Resultado - Partida #{$matchId}*\n\n";
            $msg .= "👤 *Piloto 1:* {$nick1} (Telegram ID: `{$p1Tg}`)\n";
            $msg .= "👤 *Piloto 2:* {$nick2} (Telegram ID: `{$p2Tg}`)\n\n";
            $msg .= "Para registrar o resultado, envie um dos comandos:\n";
            $msg .= "👉 */resultado {$matchId} {$nick1}* (Vitória de {$nick1})\n";
            $msg .= "👉 */resultado {$matchId} {$nick2}* (Vitória de {$nick2})\n";
            $msg .= "👉 */resultado {$matchId} empate* (Empate)\n";
            $msg .= "👉 */resultado {$matchId} woduplo* (W.O. Duplo)";

            respond($msg, $responseData);
        }

        // Mapeamento do vencedor informado
        $winnerId = null;
        $winName = "";
        $winnerStr = trim((string)$winnerInput);
        $winnerStrLower = mb_strtolower($winnerStr);

        if ($winnerStrLower === '0' || $winnerStrLower === 'empate' || $winnerStrLower === 'draw') {
            $winnerId = 0;
            $winName = "EMPATE";
        } elseif ($winnerStrLower === 'woduplo' || $winnerStrLower === 'wo_duplo' || $winnerStrLower === 'wo duplo' || $winnerStrLower === 'w.o. duplo' || $winnerStrLower === 'w.o.duplo' || $winnerStrLower === 'wo' || $winnerStr === '-1') {
            $winnerId = -1;
            $winName = "W.O. DUPLO";
        } else {
            $isP1 = false;
            $isP2 = false;

            if ($p1) {
                if ((string)$p1Id === $winnerStr) $isP1 = true;
                if (!empty($p1['nickname_TGC']) && mb_strtolower(trim($p1['nickname_TGC'])) === $winnerStrLower) $isP1 = true;
                if (!empty($p1['nome']) && mb_strtolower(trim($p1['nome'])) === $winnerStrLower) $isP1 = true;
                if (mb_strtolower(trim($nick1)) === $winnerStrLower) $isP1 = true;
                if ($winnerStrLower === '1') $isP1 = true;
            }

            if ($p2) {
                if ((string)$p2Id === $winnerStr) $isP2 = true;
                if (!empty($p2['nickname_TGC']) && mb_strtolower(trim($p2['nickname_TGC'])) === $winnerStrLower) $isP2 = true;
                if (!empty($p2['nome']) && mb_strtolower(trim($p2['nome'])) === $winnerStrLower) $isP2 = true;
                if (mb_strtolower(trim($nick2)) === $winnerStrLower) $isP2 = true;
                if ($winnerStrLower === '2') $isP2 = true;
            }

            if ($isP1 && !$isP2) {
                $winnerId = $p1Id;
                $winName = $nick1;
            } elseif ($isP2 && !$isP1) {
                $winnerId = $p2Id;
                $winName = $nick2;
            }
        }

        if ($winnerId === null) {
            respond("❌ Opção de resultado inválida.\n\nEnvie o nickname do vencedor ({$nick1} ou {$nick2}) ou 'empate' ou 'woduplo'.", ['state' => 'ERRO_OPCAO_INVALIDA']);
        }

        // Salvar em matches.json
        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchId) {
                $m['winner_id'] = $winnerId;
                $m['status'] = 'CONCLUIDO';
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        // Salvar em schedules.json
        $schedules = getJson(FILE_SCHEDULES);
        $schedFound = false;
        foreach ($schedules as &$s) {
            if ($s['match_id'] == $matchId && ($s['status'] ?? '') != 'RECUSADO') {
                $s['status'] = 'PARTIDA_FINALIZADA';
                $s['result_winner_id'] = $winnerId;
                $s['result_confirmed_by'] = 'ADMIN_' . $pilotID;
                $s['updated_at'] = date('Y-m-d H:i:s');
                unset($s['result_temp_winner']);
                unset($s['result_proposal_by']);
                $schedFound = true;
                break;
            }
        }
        if (!$schedFound) {
            $schedules[] = [
                'id' => getNextId($schedules),
                'match_id' => $matchId,
                'status' => 'PARTIDA_FINALIZADA',
                'result_winner_id' => $winnerId,
                'result_confirmed_by' => 'ADMIN_' . $pilotID,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }
        saveJson(FILE_SCHEDULES, $schedules);

        saveAudit($matchId, 0, 'RESULTADO confirmado por ADMIN', "Decidido por: " . ($currentPilot['nome'] ?? 'Admin'));

        $resultLabel = ($winnerId == 0) ? "Resultado: 🤝 *EMPATE*" : ($winnerId == -1 ? "Resultado: 🚫 *W.O. DUPLO*" : "🏆 Vencedor: *{$winName}*");
        $msg = "👮‍♂️ *Resultado Definido por Admin*\n\n{$resultLabel}\n\nPartida #{$matchId} encerrada com sucesso.";
        respond($msg, [
            'state' => 'FINALIZADO_ADMIN',
            'match_id' => $matchId,
            'winner_id' => $winnerId,
            'winner_name' => $winName
        ]);
        break;

    case '/agendar':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1]);
        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) {
            if ($m['id'] == $matchId) { $match = $m; break; }
        }

        $sched = getMatchSchedule($matchId);
        $msg = "";
        $responseData = [
            'match_id' => $matchId,
            'state' => $sched['status'],
            'opponent_id' => getTelegramIdByPilotId($match['player_2_id'])
        ];

        if (!$match) {
            respond("❌ Partida não encontrada. \n\nRevise o número com o */partidas*", ['state' => 'ERRO_NAO_ENCONTRADO']);
        }

        // Bloqueio Ritchie / Pole Position
        if (isComputerMatch($match)) {
            respond("🚫 *Atenção:* Não é necessário fazer esse agendamento, pois é uma partida de Pole Position (contra o Ritchie / Computador).", ['state' => 'ERRO_PARTIDA_COMPUTADOR']);
        }

        $p1Id = $match['player_1_id'] ?? null;
        $p2Id = $match['player_2_id'] ?? null;

        if ($p1Id != $currentPilot['id'] && $p2Id != $currentPilot['id']) {
            respond("❌ Esta partida não é sua. \n\nRevise o número com o */partidas*", ['state' => 'ERRO_NAO_PERTENCE']);
        }

        if (!$sched) {
            $msg = "📅 *Agendamento #$matchId*\n\nNenhuma proposta ativa no momento.\n\nResponda as próximas mensagens com sua disponibilidade.";
            $responseData['state'] = 'REQUER_PROPOSTA';
            $prazo = date('d/m H:i', strtotime($match['deadline']));

        } else {
            $dt = date('d/m H:i', strtotime($sched['data_hora']));
            $proposerId = $sched['proposed_by_pilot_id'];
            $isMeProposer = ($proposerId == $currentPilot['id']);

            // Definindo nome do oponente para a mensagem
            $opponentId = ($p1Id == $currentPilot['id']) ? $p2Id : $p1Id;
            $opponentName = getPilotDisplayNameByNick(getPilotById($opponentId));
            $responseData['opponent_name'] = $opponentName;
            $responseData['proposed_date'] = $dt;

            if ($sched['status'] == 'PROPOSTO') {
                if ($isMeProposer) {
                    $msg = "⏳ *Proposta Enviada*\n\nVocê sugeriu: *$dt*\nAguardando resposta de *$opponentName*.\n\nBasta *você* comparecer no horário agendado e enviar o */play ID*.";
                    $responseData['state'] = 'AGUARDANDO_OPONENTE';
                } else {
                    $msg = "🔔 *Proposta Recebida*\n\n👤 *$opponentName* sugeriu o seguinte horário:\n📅 *$dt*";
                    $responseData['state'] = 'REQUER_DECISAO_PROPOSTA';
                }
            }
            elseif ($sched['status'] == 'CONFIRMADO') {
                $msg = "✅ *Agendamento Confirmado*\n\n📅 Data: *$dt*\n👤 Oponente: *$opponentName*\n\nSeu agendamento já está confirmado. Basta *você* comparecer no horário agendado e enviar o */play ID*";
                $responseData['state'] = 'CONFIRMADO_PODE_EDITAR';
            }
        }
        respond($msg, $responseData);
    case '/proposal':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);

        if (!$matchId || empty($bookDate) || empty($bookTime)) {
            respond("❌ Falta de parâmetros. Envie data e hora corretos.");
        }

        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) if ($m['id'] == $matchId) { $match = $m; break; }

        if (!$match) respond("❌ Partida #$matchId não encontrada.");

        // Ajuste de Data
        $currentYear = date('Y');
        // Tenta criar data a partir de DD/MM
        $dateObj = DateTime::createFromFormat('d/m/Y', "$bookDate/$currentYear");
        if (!$dateObj) respond("❌ Formato de data não compreendido. Use o formato *DD/MM*.");

        // Ajuste de Hora
        $timeObj = DateTime::createFromFormat('H:i', $bookTime);
        if (!$timeObj) respond("❌ Formato de hora não compreendido. Use o formato *HH:MM*.");

        // Validação de Prazo Final (Deadline)
        $proposedTimestamp = strtotime($dateObj->format('Y-m-d') . ' ' . $timeObj->format('H:i:s'));
        $deadlineTimestamp = strtotime($match['deadline']);

        if ($proposedTimestamp > $deadlineTimestamp) {
            $limiteF = date('d/m/Y \à\s H:i', $deadlineTimestamp);
            respond("❌ *Atenção!* A data proposta ultrapassa o prazo final da partida, que é: *$limiteF*.\n\nPor favor, reinicie o processo com */agendar $matchId* e tente novamente com uma data válida.");
        }

        // Validação de antecedência mínima de 2 horas
        $nowTimestamp = time();

        if ($proposedTimestamp < ($nowTimestamp + 7200)) {
            $minimoTimestamp = $nowTimestamp + 7200;
            $minimoF = date('d/m/Y \à\s H:i', $minimoTimestamp);

            respond("❌ *Atenção!* O horário proposto deve ser de pelo menos 2 horas a partir de agora.\n\nO primeiro horário permitido é: *$minimoF*.\n\nPor favor, reinicie o processo com */agendar $matchId* e tente novamente com uma data válida.");
        }

        // Formatações solicitadas (MM/DD/YYYY e 12h AM/PM)
        $formattedDate = $dateObj->format('m/d/Y');
        $formattedTime = $timeObj->format('h:i A');
        $tournament = $match['tournament'] ?? 'Torneio Desconhecido';

        $msg = "📝 *Resumo da Proposta*\n\n";
        $msg .= "🏆 Torneio: {$tournament}\n";
        $msg .= "🆔 Partida: {$matchId}\n";
        $msg .= "📅 Data: {$formattedDate}\n";
        $msg .= "⏰ Hora: {$formattedTime}\n\n";
        $msg .= "⏳ *Lembrete:* A janela do seu jogo abrirá 30 minutos ANTES e fechará 30 minutos DEPOIS deste horário escolhido.\n\n";
        $msg .= "Para confirmar e notificar seu adversário, responda agora com a palavra *OK*.";

        $dbFormattedDate = $dateObj->format('Y-m-d') . ' ' . $timeObj->format('H:i:s');

        // 1. Atualizar schedules.json
        $schedules = getJson(FILE_SCHEDULES);
        $existingIndex = -1;
        foreach ($schedules as $idx => $s) {
            if ($s['match_id'] == $matchId) { $existingIndex = $idx; break; }
        }

        $newSchedule = [
            'match_id' => $matchId,
            'data_hora' => $dbFormattedDate,
            'status' => 'PROPOSTO',
            'proposed_by_pilot_id' => $currentPilot['id'],
            'created_at' => date('Y-m-d H:i:s')
        ];

        if ($existingIndex >= 0) {
            $newSchedule['id'] = $schedules[$existingIndex]['id'] ?? getNextId($schedules);
            $schedules[$existingIndex] = $newSchedule;
        } else {
            $newSchedule['id'] = getNextId($schedules);
            $schedules[] = $newSchedule;
        }
        saveJson(FILE_SCHEDULES, $schedules);

        // 2. Atualizar matches.json
        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchId) {
                $m['status'] = 'PROPOSTO';
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        // 3. Auditoria
        $p1Id = $match['player_1_id'] ?? null;
        $p2Id = $match['player_2_id'] ?? null;

        $opponentId = ($p1Id == $currentPilot['id']) ? $p2Id : $p1Id;
        $opponentName = getPilotDisplayNameByNick(getPilotById($opponentId));
        saveAudit($matchId, $currentPilot['id'], 'PARTIDA_PROPOSTA', "Piloto realizou proposta para $dbFormattedDate. Aguardando confirmação do Oponente $opponentName.");

        respond($msg, [
            'state' => 'AGUARDANDO_OPONENTE',
            'match_id' => $matchId
        ]);
        break;
    case '/proposal_confirm':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);

        if (!$matchId) {
            respond("❌ Falta de parâmetros (ID da partida).");
        }

        // 1. Atualizar schedules.json
        $schedules = getJson(FILE_SCHEDULES);
        $existingIndex = -1;
        foreach ($schedules as $index => $s) {
            if ($s['match_id'] == $matchId) { $existingIndex = $index; break; }
        }

        if ($existingIndex >= 0) {
            // Se já existe, atualiza os dados preservando ID original e quem propôs
            $schedules[$existingIndex]['status'] = 'CONFIRMADO';
            $schedules[$existingIndex]['updated_at'] = date('Y-m-d H:i:s');
            $schedules[$existingIndex]['action_by_pilot_id'] = $currentPilot['id'];
        } else {
            // Sem fallback de criação, apenas recusa e retorna erro.
            respond("❌ Erro: Não existe nenhuma proposta ativa para ser confirmada.", ['state' => 'ERRO_NENHUMA_PROPOSTA']);
        }

        saveJson(FILE_SCHEDULES, $schedules);

        // 2. Atualizar matches.json (Status global da partida)
        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchId) {
                $m['status'] = 'AGENDADO';
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        // 3. Salvar Auditoria
        saveAudit($matchId, $currentPilot['id'], 'CONFIRMADO', "Piloto confirmou o agendamento via API.");

        respond("✅ *Agendamento Confirmado!*\n\nA partida está oficialmente agendada. O seu oponente será notificado.\n\nNo dia do jogo, lembre-se de usar */play {$matchId}*.", ['state' => 'CONFIRMADO']);
        break;

    default:
        respond("❓ Comando não reconhecido ou não suportado via API.");
}