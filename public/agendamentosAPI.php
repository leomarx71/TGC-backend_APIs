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

// Carregar Configuração de Ambiente Central
if (file_exists(__DIR__ . '/../src/config/environment.php')) {
    require_once __DIR__ . '/../src/config/environment.php';
}

if (!defined('BASE_DIR')) define('BASE_DIR', __DIR__);
if (!defined('FILE_LOG_BOT')) define('FILE_LOG_BOT', __DIR__);

function writeLog($msg, $data = null) {
    $date = date('Y-m-d H:i:s');
    $content = "[$date] $msg";
    if ($data !== null) {
        $content .= " | DADOS: " . (is_array($data) || is_object($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data);
    }
    file_put_contents(FILE_LOG_BOT, $content . PHP_EOL, FILE_APPEND);
}

$secretToken = $_ENV['WEBHOOK_SECRET'] ?? '';
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
        if ($p['phoneNumberID'] == $tgId) return $p;
    }
    return null;
}

function getTelegramIdByPilotId($pilotId) {
    $pilots = getJson(FILE_PILOTS);
    foreach ($pilots as $p) {
        if ($p['id'] == $pilotId) return $p['phoneNumberID'];
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
    return !empty($pilot['nicknameTGC']) ? $pilot['nicknameTGC'] : $pilot['name'];
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
        if ($s['matchID'] == $matchId) return $s;
    }
    return null;
}

function saveAudit($matchId, $pilotId, $action, $details = '') {
    $audit = getJson(FILE_AUDIT);
    $audit[] = [
        'id' => getNextId($audit),
        'timestamp' => date('Y-m-d H:i:s'),
        'matchID' => $matchId,
        'pilotID' => $pilotId,
        'action' => $action,
        'details' => $details
    ];
    saveJson(FILE_AUDIT, $audit);
}

// Verifica se a partida é contra o computador (ex: Ritchie / Pole Position)
function isComputerMatch($match) {
    // Retorna true se um dos IDs for <= 0 (geralmente bots) ou se o torneio tiver 'Pole' no nome
    return ($match['player1ID'] <= 0 || $match['player2ID'] <= 0 || stripos($match['tournament'], 'Pole') !== false);
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
        $msg .= "*1. VER SUAS PARTIDAS:* '/partidas'" . "\n";
        $msg .= "*2. INICIAR AGENDAMENTO:* /agendar ID\n";
        $msg .= "*3. NO DIA DO JOGO:* /play ID\n\n";
        $msg .= "*Comandos para Admins*\n\n";
        $msg .= "*4. Gerenciar o resultado da partida:* /resultado ID\n";
        $msg .= "*5. Ver Auditoria da partida:* /audit ID\n";
        respond($msg);

    case '/ayuda':
        $msg = "📚 *CÓMO AGENDAR TUS PARTIDOS*\n\n";
        $msg .= "*1. VER SUS PARTIDOS:* " . "'/partidas'" . "\n";
        $msg .= "*2. INICIAR GESTIÓN:* /agendar ID\n";
        $msg .= "*3. EN EL DÍA DEL JUEGO:* /play ID";
        $msg .= "*Comandos para Admins*\n\n";
        $msg .= "*4. Gestión el resultado del partido:* /resultado ID\n";
        $msg .= "*5. Ver auditoría del juego:* /audit ID\n";
        respond($msg);

    case '/inscrever':
        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Este comando não pode ser utilizado em grupos.\n\n" .
                "Envie uma mensagem lá no grupo do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
                []
            );
        }
        $pilots = getJson(FILE_PILOTS);
        foreach ($pilots as $p) { if ($p['phoneNumberID'] == $pilotID) respond("Você já está inscrito."); }
        $newPilot = [
            'id' => getNextId($pilots),
            'phoneNumberID' => $pilotID,
            'nome' => "Piloto API",
            'nickname_TGC' => "Piloto_API",
            'ativo' => 1,
            'createdAt' => date('Y-m-d H:i:s')
        ];
        $pilots[] = $newPilot;
        saveJson(FILE_PILOTS, $pilots);
        respond("🏁 *Inscrição Realizada!*\n\nBem-vindo! Use /meuNick NovoNome para alterar seu nick.");

    case '/meunick':
        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Este comando não pode ser utilizado em grupos.\n\n" .
                "Envie uma mensagem lá no grupo do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
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
                if ($p['phoneNumberID'] == $pilotID) {
                    if (isset($p['last_nick_change']) && strtotime($p['last_nick_change']) > strtotime('-90 days') && !isAdmin($pilotID)) {
                        respond("⚠️ Alteração bloqueada. Aguarde 90 dias entre mudanças.");
                    }
                    $p['nicknameTGC'] = $args;
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
                "Envie uma mensagem lá no grupo do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
                []
            );
        }

        $myMatches = array_filter($matches, function($m) use ($currentPilot) {
            return ($m['player1ID'] == $currentPilot['id'] || $m['player2ID'] == $currentPilot['id'])
                && in_array($m['status'], ['PENDENTE', 'AGENDADO', 'PROPOSTO', 'CONFIRMADO']);
        });

        if (empty($myMatches)) {
            respond("Sem partidas pendentes.", []);
        }

        $msg = "";
        $lastKey = array_key_last($myMatches);

        foreach ($myMatches as $key => $m) {
            $p1 = getPilotById($m['player1ID'], $pilots);
            $p2 = getPilotById($m['player2ID'], $pilots);
            $p1Name = getPilotDisplayNameByNick($p1);
            $p2Name = getPilotDisplayNameByNick($p2);
            $sched = getMatchSchedule($m['id']);
            $status = $sched ? "{$sched['status']}" : "⚠️ Aguardando Agendamento";
            $prazo = date('d/m \à\s H:i', strtotime($m['deadline']));
            $local = formatLocal($m['localTrack'] ?? null);
            $titulo = "{$m['tournament']} - {$m['phase']}";
            if ($m['groupName'] !== $m['phase'] && $m['phase'] == 'Fase de Grupos') $titulo .= " - {$m['groupName']}";

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
            if ($p['name'] == $pilotName) {
                $pilotID = $p['phoneNumberID'];
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
        $audits = array_filter(getJson(FILE_AUDIT), function($a) use ($matchId) { return $a['matchID'] == $matchId; });
        if (empty($audits)) respond("📭 Nenhum registro para partida #$matchId");
        $msg = "🕵️‍♂️ *Auditoria Partida #$matchId*\n\n";

        $auditData = [];
        foreach ($audits as $a) {
            $p = getPilotById($a['pilotID']);
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

        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Este comando não pode ser utilizado em grupos.\n\n" .
                "Envie uma mensagem lá no grupo do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
                []
            );
        }

        $p1Id = $match['player1ID'];
        $p2Id = $match['player2ID'];
        $opponent_id = null;

        if ($p1Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p2Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p2Id));
        } else if ($p2Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p1Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p1Id));
        }

        $responseData = [
            'matchID' => $matchId,
            'state' => 'ERRO',
            'nickname' => $nickname,
            'opponent_id' => $opponent_id,
            'dateTime' => 'ERRO',
            'tournament' => $match['tournament'],
        ];

        if ($match['player1ID'] != $currentPilot['id'] && $match['player2ID'] != $currentPilot['id'] && !isAdmin($pilotID)) {
            respond("❌ Você não participa desta partida.");
        }
        $sched = getMatchSchedule($matchId);

        //TODO se p1 ou p2 é o rithchie, não permitir play pq é uma partida de pole position, não precisa agendar nem play
        if (!$sched) respond("❌ Não há agendamentos propostos ou confirmados para a partida #$matchId.\n\nUse /agendar ID para agendar.");

        $now = time();
        $dtTimestamp = strtotime($sched['dateTime']);
        $formattedTime = date('d/m H:i', $dtTimestamp);
        $windowStartTime = date('H:i', $dtTimestamp - 1800);
        $windowEndTime = date('H:i', $dtTimestamp + 1800);
        $responseData['dateTime'] = $formattedTime;

        // Primeiro verifica a janela de tempo, independentemente do status
        if ($now < ($dtTimestamp - 1800)) {
            $responseData['state'] = 'PLAY_MUITO_CEDO';
            respond("⏳ Muito cedo.\nPartida está agendada para $formattedTime\n\nA janela de *play* abre de 30min antes do horário até 30min depois do horário.", $responseData);
        }
        if ($now > ($dtTimestamp + 1800)) {
            saveAudit($matchId, $currentPilot['id'], 'JOGADOR_ATRASADO', 'Piloto tentou notificar disponibilidade após o horário agendado');
            $responseData['state'] = 'JOGADOR_ATRASADO';
            respond("❌ Oops, Muito tarde.\n⏳ Horário do play expirado.\nA partida estava agendada para $formattedTime\n\nUse */agendar $matchId* para agendar novamente.", $responseData);
        }

        // Está dentro da janela de ±30 minutos. Agora verifica o status.
        if ($sched['status'] == 'CONFIRMADO') {
            saveAudit($matchId, $currentPilot['id'], 'JOGADOR_PRONTO', 'Piloto compareceu corretamente no horário proposto');
            $responseData['state'] = 'JOGADOR_PRONTO';
            respond("✅ *Você chegou no horário!*\n\nNotificando o oponente que você está pronto para a partida.\nFique disponível para a resposta dele até o fim do período da janela de agendamento.\n\nPartida: 🆔 #$matchId!\nData Agendada: $formattedTime\nJanela Válida: de $windowStartTime até $windowEndTime", $responseData);
        }
        if ($sched['status'] == 'PROPOSTO') {
            $responseData['state'] = 'JOGADOR_PRONTO_SEM_AGENDAMENTO';
            saveAudit($matchId, $currentPilot['id'], 'JOGADOR_PRONTO_SEM_AGENDAMENTO', 'Piloto compareceu no horário proposto, mas o oponente ainda não tinha confirmado');
            respond("❌ O agendamento não havia sido confirmado pelo seu oponente para a partida ID $matchId.\nRegistrei sua presença e disponibilidade no horário proposto.\n\nUse */agendar ID* para agendar novamente.", $responseData);
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
        //TODO permitir resultado para partidas de pole position, mas não permitir agendamento nem play
        //TODO checar se o ADMIN já informou o resultado, se sim, não permitir informar novamente
        if (isComputerMatch($match)) {
            respond("🚫 *Atenção:* Não é necessário informar resultado para partida de Pole Position (contra o Ritchie / Computador).", ['state' => 'ERRO_PARTIDA_COMPUTADOR']);
        }

        $p1Id = $match['player1ID'] ?? null;
        $p2Id = $match['player2ID'] ?? null;
        $p1 = getPilotById($p1Id);
        $p2 = getPilotById($p2Id);
        $nick1 = getPilotDisplayNameByNick($p1);
        $nick2 = getPilotDisplayNameByNick($p2);
        $p1Tg = getTelegramIdByPilotId($p1Id);
        $p2Tg = getTelegramIdByPilotId($p2Id);

        //TODO arrumar o CamelCase em todos os campos de nickname, nome, nickname_TGC, nicknameTGC, etc. para manter consistência
        // Se passar apenas /resultado ID (sem argumento de vencedor/empate/woduplo)
        $winnerInput = isset($parts[2]) ? trim(implode(' ', array_slice($parts, 2))) : ($input['message']['winner'] ?? ($input['message']['winnerId'] ?? null));

        if ($winnerInput === null || $winnerInput === '') {
            $responseData = [
                'matchID' => $matchId,
                'player1ID' => [
                    'id' => $p1Id,
                    'name' => $nick1,
                    'phoneNumberID' => $p1Tg
                ],
                'player2ID' => [
                    'id' => $p2Id,
                    'name' => $nick2,
                    'phoneNumberID' => $p2Tg
                ],
                'state' => 'REQUER_RESULTADO_ADMIN'
            ];

            $msg = "🏆 *Os pilotos dessa partida {$matchId} são:*\n\n";
            $msg .= "👤 *Player 1 =* {$nick1}\n";
            $msg .= "👤 *Player 2 =* {$nick2}\n\n";
            $msg .= "Para registrar o resultado, envie um dos comandos a seguir:\n\n";
            $msg .= "Se o resultado for Vitória de *{$nick1}*\n";
            $msg .= "👉 /resultado {$matchId} {$nick1}\n";
            $msg .= "Se o resultado for Vitória de *{$nick2}*\n";
            $msg .= "👉 /resultado {$matchId} {$nick2}\n";
            $msg .= "Se o resultado for *Empate*\n";
            $msg .= "👉 /resultado {$matchId} empate\n";
            $msg .= "Se o resultado for *W.O. Duplo*\n";
            $msg .= "👉 /resultado {$matchId} woduplo";

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
            respond("❌ Opção de resultado inválida.\n\nEnvie exatamento o nickname do vencedor:\n({$nick1} ou {$nick2}) ou\n Empate ou W.O. Duplo", ['state' => 'ERRO_OPCAO_INVALIDA']);
        }

        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchId) {
                $m['winner_id'] = $winnerId;
                $m['status'] = 'CONCLUIDO';
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        $schedules = getJson(FILE_SCHEDULES);
        $schedFound = false;
        foreach ($schedules as &$s) {
            if ($s['matchID'] == $matchId && ($s['status'] ?? '') != 'RECUSADO') {
                $s['status'] = 'PARTIDA_FINALIZADA';
                $s['resultWinnerID'] = $winnerId;
                $s['resultConfirmedBy'] = $pilotID;
                $s['updatedAt'] = date('Y-m-d H:i:s');
                unset($s['result_temp_winner']);
                unset($s['result_proposal_by']);
                $schedFound = true;
                break;
            }
        }
        if (!$schedFound) {
            $schedules[] = [
                'id' => getNextId($schedules),
                'matchID' => $matchId,
                'status' => 'PARTIDA_FINALIZADA',
                'resultWinnerID' => $winnerId,
                'resultConfirmedBy' => $pilotID,
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s')
            ];
        }
        saveJson(FILE_SCHEDULES, $schedules);

        saveAudit($matchId, 0, 'RESULTADO confirmado por ADMIN', "Decidido por: " . ($currentPilot['nome'] ?? 'Admin'));

        $resultLabel = ($winnerId == 0) ? "Resultado da partida {$matchId}: 🤝 *EMPATE*" : ($winnerId == -1 ? "👉 Resultado da partida {$matchId}: 🚫 *W.O. DUPLO*" : "Resultado da partida {$matchId} foi: 🏆 *Vencedor: {$winName}*");
        $msg = "👮‍♂️ *Resultado Definido por Admin*\n\n{$resultLabel}\n\nResultado registrado com sucesso.";
        respond($msg, [
            'state' => 'FINALIZADO_ADMIN',
            'matchID' => $matchId,
            'winner_id' => $winnerId,
            'winner_name' => $winName
        ]);

    case '/agendar':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1]);
        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) {
            if ($m['id'] == $matchId) { $match = $m; break; }
        }

        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Este comando não pode ser utilizado em grupos.\n\n" .
                "Envie uma mensagem privada para o TopGearTGCBot +351935525827 e execute o comando por lá.",
                []
            );
        }
        $sched = getMatchSchedule($matchId);
        $msg = "";
        $responseData = [
            'matchID' => $matchId,
            'state' => $sched['status'],
            'opponent_id' => getTelegramIdByPilotId($match['player2ID'])
        ];

        if (!$match) {
            respond("❌ Partida não encontrada. \n\nRevise o número com o */partidas*", ['state' => 'ERRO_NAO_ENCONTRADO']);
        }

        // Bloqueio Ritchie / Pole Position
        if (isComputerMatch($match)) {
            respond("🚫 *Atenção:* Não é necessário fazer esse agendamento, pois é uma partida de Pole Position (contra o Ritchie / Computador).", ['state' => 'ERRO_PARTIDA_COMPUTADOR']);
        }

        $p1Id = $match['player1ID'] ?? null;
        $p2Id = $match['player2ID'] ?? null;

        if ($p1Id != $currentPilot['id'] && $p2Id != $currentPilot['id']) {
            respond("❌ Esta partida não é sua. \n\nRevise o número com o */partidas*", ['state' => 'ERRO_NAO_PERTENCE']);
        }

        if (!$sched) {
            $msg = "📅 *Agendamento #$matchId*\n\nNenhuma proposta ativa no momento.\n\nResponda as próximas mensagens com sua disponibilidade.";
            $responseData['state'] = 'REQUER_PROPOSTA';
            $prazo = date('d/m H:i', strtotime($match['deadline']));

        } else {
            $dt = date('d/m H:i', strtotime($sched['dateTime']));
            $proposerId = $sched['proposedByPilotID'];
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

        $dbFormattedDate = $dateObj->format('Y-m-d') . ' ' . $timeObj->format('H:i:s');

        $p1Id = $match['player1ID'];
        $p2Id = $match['player2ID'];
        $opponent_id = null;

        if ($p1Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p2Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p2Id));
        } else if ($p2Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p1Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p1Id));
        }

        $responseData = [
            'matchID' => $matchId,
            'state' => 'ERRO',
            'nickname' => $nickname,
            'opponent_id' => $opponent_id,
            'dateTime' => $dbFormattedDate,
            'tournament' => $match['tournament'],
        ];

        if ($proposedTimestamp > $deadlineTimestamp) {
            $limiteF = date('d/m/Y \à\s H:i', $deadlineTimestamp);
            $responseData['state'] = 'FORA_DO_PRAZO';
            respond("❌ Oops não foi possível finalizar\n\n*Atenção!* A data proposta ultrapassa o prazo final da partida, que é: *$limiteF*.\n\nPor favor, reinicie o processo com */agendar $matchId* e tente novamente com uma data válida.", $responseData);
        }

        // Validação de antecedência mínima de 2 horas
        $nowTimestamp = time();

        if ($proposedTimestamp < ($nowTimestamp + 7200)) {
            $minimoTimestamp = $nowTimestamp + 7200;
            $minimoF = date('d/m/Y \à\s H:i', $minimoTimestamp);
            $responseData['state'] = 'MENOS_DE_2_HORAS';
            respond("❌ Oops não foi possível finalizar\n\n*Atenção!* O horário proposto deve ser de pelo menos 2 horas a partir de agora.\n\nO primeiro horário permitido é: *$minimoF*.\n\nPor favor, reinicie o processo com */agendar $matchId* e tente novamente com uma data válida.", $responseData);
        }

        // Formatações solicitadas (MM/DD/YYYY e 12h AM/PM)
        $formattedDate = $dateObj->format('d/m/Y');
        $formattedTime = $timeObj->format('H:i');
        $tournament = $match['tournament'] ?? 'Torneio Desconhecido';

        $msg = "📨 Solicitação de agendamento enviada com sucesso!\nAguarde a confirmação do seu oponente.\n\n";
        $msg .= "📝 *Resumo da Proposta*\n\n";
        $msg .= "🏆 Torneio: {$tournament}\n";
        $msg .= "🆔 Partida: {$matchId}\n";
        $msg .= "📅 Data: {$formattedDate}\n";
        $msg .= "⏰ Hora: {$formattedTime}\n\n";
        $msg .= "⏳ *Lembrete 1:* A janela do seu jogo abrirá 30 minutos ANTES e fechará 30 minutos DEPOIS deste horário escolhido.\n\n";
        $msg .= "⏳ *Lembrete 2:* Mesmo que seu oponente não confirme a tempo, Você deve comparecer no seu horário proposto e enviar o */play $matchId* para registrar que você está disponível.\n\n";

        // 1. Atualizar schedules.bookingsData
        $schedules = getJson(FILE_SCHEDULES);
        $existingIndex = -1;
        foreach ($schedules as $idx => $s) {
            if ($s['matchID'] == $matchId) { $existingIndex = $idx; break; }
        }

        $newSchedule = [
            'matchID' => $matchId,
            'dateTime' => $dbFormattedDate,
            'status' => 'PROPOSTO',
            'proposedByPilotID' => $currentPilot['id'],
            'createdAt' => date('Y-m-d H:i:s')
        ];

        if ($existingIndex >= 0) {
            $newSchedule['id'] = $schedules[$existingIndex]['id'] ?? getNextId($schedules);
            $schedules[$existingIndex] = $newSchedule;
        } else {
            $newSchedule['id'] = getNextId($schedules);
            $schedules[] = $newSchedule;
        }
        saveJson(FILE_SCHEDULES, $schedules);

        // 2. Atualizar matches.bookingsData
        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchId) {
                $m['status'] = 'PROPOSTO';
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        // 3. Auditoria
        $p1Id = $match['player1ID'] ?? null;
        $p2Id = $match['player2ID'] ?? null;

        $opponentId = ($p1Id == $currentPilot['id']) ? $p2Id : $p1Id;
        $opponentName = getPilotDisplayNameByNick(getPilotById($opponentId));
        saveAudit($matchId, $currentPilot['id'], 'PARTIDA_PROPOSTA', "Piloto realizou proposta para $dbFormattedDate. Aguardando confirmação do Oponente $opponentName.");
        $responseData['state'] = 'AGUARDANDO_OPONENTE';

        respond($msg, $responseData);

    case '/proposal_confirm':
        $parts = explode(' ', $function);
        $matchId = intval($parts[1] ?? 0);

        if (!$matchId) {
            respond("❌ Falta de parâmetros (ID da partida).");
        }

        // 1. Atualizar schedules.bookingsData
        $schedules = getJson(FILE_SCHEDULES);
        $existingIndex = -1;
        foreach ($schedules as $index => $s) {
            if ($s['matchID'] == $matchId) { $existingIndex = $index; break; }
        }

        if ($existingIndex >= 0) {
            // Se já existe, atualiza os dados preservando ID original e quem propôs
            $schedules[$existingIndex]['status'] = 'CONFIRMADO';
            $schedules[$existingIndex]['updatedAt'] = date('Y-m-d H:i:s');
            $schedules[$existingIndex]['actionByPilotID'] = $currentPilot['id'];
        } else {
            // Sem fallback de criação, apenas recusa e retorna erro.
            respond("❌ Erro: Não existe nenhuma proposta ativa para ser confirmada.", ['state' => 'ERRO_NENHUMA_PROPOSTA']);
        }

        saveJson(FILE_SCHEDULES, $schedules);

        // 2. Atualizar matches.bookingsData (Status global da partida)
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

        $matches = getJson(FILE_MATCHES);
        $match = null;
        unset($m);
        foreach ($matches as $m) { if ($m['id'] == $matchId) { $match = $m; break; } }
        if (!$match) respond("❌ Partida #$matchId não encontrada para confirmação.");

        $p1Id = $match['player1ID'] ;
        $p2Id = $match['player2ID'] ;
        $opponent_id = null;

        if ($p1Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p2Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p2Id));
        } else if ($p2Id == $currentPilot['id']) {
            $opponent_id = getTelegramIdByPilotId($p1Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p1Id));
        }

        $dtTimestamp = strtotime($schedules[$existingIndex]['dateTime']);
        $formattedTime = date('d/m H:i', $dtTimestamp);

        $responseData = [
            'matchID' => $matchId,
            'state' => 'CONFIRMADO',
            'nickname' => $nickname,
            'opponent_id' => $opponent_id,
            'dateTime' => $formattedTime,
            'tournament' => $match['tournament'],
        ];

        respond("✅ *Agendamento Confirmado!*\n\nA partida está oficialmente agendada. O seu oponente será notificado.\n\nNo dia do jogo, lembre-se de usar */play {$matchId}*.", $responseData);

    default:
        respond("❓ Comando não reconhecido ou não suportado via API.");
}