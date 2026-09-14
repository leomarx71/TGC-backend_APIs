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

function isComputerMatch($match) {
    // Retorna true se um dos IDs for <= 0 (geralmente bots) ou se o torneio tiver 'Pole' no nome
    return ($match['player1ID'] <= 0 || $match['player2ID'] <= 0 || stripos($match['tournament'], 'Pole') !== false);
}

function formatMsToTime($ms) {
        if ($ms <= 0) return "0:00:000"; // Fallback para quem não tem tempo válido

        $minutes = floor($ms / 60000);
        $seconds = floor(($ms % 60000) / 1000);
        $milliseconds = $ms % 1000;

        // %d (minutos sem zero extra), %02d (segundos com 2 casas), %03d (ms com 3 casas)
        return sprintf("%d:%02d:%03d", $minutes, $seconds, $milliseconds);
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
    $currentPilot = ['id' => 0, 'name' => 'Admin', 'nicknameTGC' => 'ADMIN'];
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
        $msg .= "*1. VER SUAS PARTIDAS:* '/partidas'\n";
        $msg .= "*2. INICIAR AGENDAMENTO:* /agendar ID\n";
        $msg .= "*3. NO DIA DO JOGO:* /play ID\n";
        $msg .= "*4. ENVIAR TEMPOS POLE POSITION:* /polePosition ID\n";
        $msg .= "*5. RESULTADOS POLE POSITION:* /poleRounds ID ou /poleFinalStandings\n\n";
        $msg .= "*Comandos para Admins*\n\n";
        $msg .= "*6. Gerenciar o resultado da partida:* /resultado ID\n";
        $msg .= "*7. Ver Auditoria da partida:* /audit ID\n";
        respond($msg);

    case '/ayuda':
        $msg = "📚 *CÓMO AGENDAR TUS PARTIDOS*\n\n";
        $msg .= "*1. VER SUS PARTIDOS:* '/partidas'\n";
        $msg .= "*2. INICIAR GESTIÓN:* /agendar ID\n";
        $msg .= "*3. EN EL DÍA DEL JUEGO:* /play ID\n";
        $msg .= "*4. ENVIAR TIEMPOS POLE POSITION:* /polePosition ID\n";
        $msg .= "*5. RESULTADOS POLE POSITION:* /poleRounds ID o /poleFinalStandings\n\n";
        $msg .= "*Comandos para Admins*\n\n";
        $msg .= "*6. Gestión el resultado del partido:* /resultado ID\n";
        $msg .= "*7. Ver auditoría del juego:* /audit ID\n";
        respond($msg);

    case '/inscrever':
        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Só consigo processar uma mensagem por vez! \n\nReenvie novamente o comando...\n\n" .
                "Link do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
                []
            );
        }
        $pilots = getJson(FILE_PILOTS);
        foreach ($pilots as $p) { if ($p['phoneNumberID'] == $pilotID) respond("Você já está inscrito."); }
        $newPilot = [
            'id' => getNextId($pilots),
            'phoneNumberID' => $pilotID,
            'name' => "Piloto API",
            'nicknameTGC' => "Piloto_API",
            'active' => 1,
            'createdAt' => date('Y-m-d H:i:s')
        ];
        $pilots[] = $newPilot;
        saveJson(FILE_PILOTS, $pilots);
        respond("🏁 *Inscrição Realizada!*\n\nBem-vindo! Use /meuNick NovoNome para alterar seu nick.");

    case '/meunick':
        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Só consigo processar uma mensagem por vez! \n\nReenvie novamente o comando...\n\n" .
                "Link do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
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
                    if (isset($p['lastNickChange']) && strtotime($p['lastNickChange']) > strtotime('-90 days') && !isAdmin($pilotID)) {
                        respond("⚠️ Alteração bloqueada. Aguarde 90 dias entre mudanças.");
                    }
                    $p['nicknameTGC'] = $args;
                    $p['lastNickChange'] = date('Y-m-d H:i:s');
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
                "❌ Só consigo processar uma mensagem por vez! \n\nReenvie novamente o comando...\n\n" .
                "Link do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
                []
            );
        }

        $myMatches = array_filter($matches, fn($m) =>
            ($m['player1ID'] == $currentPilot['id'] || $m['player2ID'] == $currentPilot['id'])
            && in_array($m['status'], ['PENDENTE', 'AGENDADO', 'PROPOSTO', 'CONFIRMADO', 'CONFIRMADO_PODE_EDITAR', 'CONFIRMADO_SEM_VIDEO'])
        );

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

            // Verifica se é partida de pole position
            $isPole = ($m['player1ID'] == 999 || $m['player2ID'] == 999);
            $sched = getMatchSchedule($m['id']);

            // PHP 8+ Match: Avalia as condições de cima para baixo
            $status = match (true) {
                $isPole && $m['status'] === 'PENDENTE'               => '⚠️ Aguardando envio de tempos',
                $isPole && $m['status'] === 'CONFIRMADO_SEM_VIDEO'   => '⚠️ Aguardando Video',
                $isPole && $m['status'] === 'CONFIRMADO_PODE_EDITAR' => '✅ Tempos OK',
                $isPole                                              => $m['status'], // Caso a pole position tenha outro status
                $sched !== null                                      => $sched['status'], // Partida normal com agendamento
                default                                              => '⚠️ Aguardando Agendamento', // Partida normal sem agendamento
            };

            $prazo = date('d/m \à\s H:i', strtotime($m['deadline']));
            $local = formatLocal($m['localTrack'] ?? null);
            $titulo = "{$m['tournament']} - {$m['phase']}" . (($m['groupName'] !== $m['phase'] && $m['phase'] === 'Fase de Grupos') ? " - {$m['groupName']}" : "");

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
        $matchID = intval($parts[1] ?? 0);
        if (!$matchID) respond("❌ Use: /audit ID");
        $audits = array_filter(getJson(FILE_AUDIT), function($a) use ($matchID) { return $a['matchID'] == $matchID; });
        if (empty($audits)) respond("📭 Nenhum registro para partida #$matchID");
        $msg = "🕵️‍♂️ *Auditoria Partida #$matchID*\n\n";

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
        $matchID = intval($parts[1] ?? 0);
        if (!$matchID) respond("❌ Use: /play ID");

        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) { if ($m['id'] == $matchID) { $match = $m; break; } }
        if (!$match) respond("❌ Partida #$matchID não encontrada.");

        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Só consigo processar uma mensagem por vez! \n\nReenvie novamente o comando...\n\n" .
                "Link do Bot TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
                []
            );
        }

        $p1Id = $match['player1ID'];
        $p2Id = $match['player2ID'];
                $opponentID = null;

        if ($p1Id == $currentPilot['id']) {
                    $opponentID = getTelegramIdByPilotId($p2Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p2Id));
        } else if ($p2Id == $currentPilot['id']) {
                    $opponentID = getTelegramIdByPilotId($p1Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p1Id));
        }

        $responseData = [
            'matchID' => $matchID,
            'state' => '',
            'nickname' => $nickname,
            'opponentID' => $opponentID,
            'bookingDate' => 'ERRO',
            'tournament' => $match['tournament'],
        ];

        if ($match['player1ID'] != $currentPilot['id'] && $match['player2ID'] != $currentPilot['id'] ) {
            $responseData['state'] = 'JOGADOR_PRONTO_SEM_AGENDAMENTO';
            respond("❌ Você não participa desta partida.", $responseData);
        }
        $sched = getMatchSchedule($matchID);

        if ($match['player1ID'] == 999 || $match['player2ID'] == 999) {
            $responseData['state'] = 'JOGADOR_PRONTO_SEM_AGENDAMENTO';
            respond("❌ Esta é uma partida de PolePosition\nNão é necessário comando play.\n\nQuando tiver os tempos envie */polePosition $matchID*.", $responseData);
        }

        if (!$sched) {
            $responseData['state'] = 'JOGADOR_PRONTO_SEM_AGENDAMENTO';
            respond("❌ Não há agendamentos propostos ou confirmados para a partida #$matchID.\n\nUse */agendar $matchID* para agendar.");
        }

        $now = time();
        $dtTimestamp = strtotime($sched['bookingDate']);
        $formattedTime = date('d/m H:i', $dtTimestamp);
        $windowStartTime = date('H:i', $dtTimestamp - 1800);
        $windowEndTime = date('H:i', $dtTimestamp + 1800);
        $responseData['bookingDate'] = $formattedTime;

        // Primeiro verifica a janela de tempo, independentemente do status
        if ($now < ($dtTimestamp - 1800)) {
            $responseData['state'] = 'PLAY_MUITO_CEDO';
            respond("⏳ Muito cedo.\nPartida está agendada para $formattedTime\n\nA janela de *play* abre de 30min antes do horário até 30min depois do horário.", $responseData);
        }
        if ($now > ($dtTimestamp + 1800)) {
            saveAudit($matchID, $currentPilot['id'], 'JOGADOR_ATRASADO', 'Piloto tentou notificar disponibilidade após o horário agendado');
            $responseData['state'] = 'JOGADOR_ATRASADO';
            respond("❌ Oops, Muito tarde.\n⏳ Horário do play expirado.\nA partida estava agendada para $formattedTime\n\nUse */agendar $matchID* para agendar novamente.", $responseData);
        }

        // Está dentro da janela de ±30 minutos. Agora verifica o status.
        if ($sched['status'] == 'CONFIRMADO') {
            saveAudit($matchID, $currentPilot['id'], 'JOGADOR_PRONTO', 'Piloto compareceu corretamente no horário proposto');
            $responseData['state'] = 'JOGADOR_PRONTO';
            respond("✅ *Você chegou no horário!*\n\nNotificando o oponente que você está pronto para a partida.\nFique disponível para a resposta dele até o fim do período da janela de agendamento.\n\nPartida: 🆔 #$matchID!\nData Agendada: $formattedTime\nJanela Válida: de $windowStartTime até $windowEndTime", $responseData);
        }
        if ($sched['status'] == 'PROPOSTO') {
            $responseData['state'] = 'JOGADOR_PRONTO_SEM_AGENDAMENTO';
            saveAudit($matchID, $currentPilot['id'], 'JOGADOR_PRONTO_SEM_AGENDAMENTO', 'Piloto compareceu no horário proposto, mas o oponente ainda não tinha confirmado');
            respond("❌ O agendamento não havia sido confirmado pelo seu oponente para a partida ID $matchID.\nRegistrei sua presença e disponibilidade no horário proposto.\n\nUse */agendar ID* para agendar novamente.", $responseData);
        }

        // Está dentro da janela, mas o status não permite /play
        $responseData['state'] = 'STATUS_PLAY_DESCONHECIDO';
        respond("❌ O status atual para a partida #$matchID não permite play", $responseData);

    case '/resultado':
        $parts = explode(' ', $function);
        $matchID = intval($parts[1] ?? 0);
        if (!$matchID) {
            respond("❌ Use: /resultado ID", ['state' => 'ERRO_FALTA_ID']);
        }

        $isAdm = isAdmin($pilotID);
        if (!$isAdm) {
            respond("❌ Apenas administradores podem informar o resultado de uma partida.", ['state' => 'ERRO_APENAS_ADMIN']);
        }

        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) {
            if ($m['id'] == $matchID) {
                $match = $m;
                break;
            }
        }

        if (!$match) {
            respond("❌ Partida não encontrada. \n\nRevise o número com o */partidas*", ['state' => 'ERRO_NAO_ENCONTRADO']);
        }

        //if (isComputerMatch($match)) {
        //    respond("🚫 *Atenção:* Não é necessário informar resultado para partida de Pole Position (contra o Ritchie / Computador).", ['state' => 'ERRO_PARTIDA_COMPUTADOR']);
        //}

        $p1Id = $match['player1ID'] ?? null;
        $p2Id = $match['player2ID'] ?? null;
        $p1 = getPilotById($p1Id);
        $p2 = getPilotById($p2Id);
        $nick1 = getPilotDisplayNameByNick($p1);
        $nick2 = getPilotDisplayNameByNick($p2);
        $p1Tg = getTelegramIdByPilotId($p1Id);
        $p2Tg = getTelegramIdByPilotId($p2Id);

        $winnerInput = isset($parts[2]) ? trim(implode(' ', array_slice($parts, 2))) : ($input['message']['winner'] ?? ($input['message']['winnerId'] ?? null));

        if ($winnerInput === null || $winnerInput === '') {
            $responseData = [
                'matchID' => $matchID,
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

            $msg = "🏆 *Os pilotos dessa partida {$matchID} são:*\n\n";
            $msg .= "👤 *Player 1 =* {$nick1}\n";
            $msg .= "👤 *Player 2 =* {$nick2}\n\n";
            $msg .= "Para registrar o resultado, envie um dos comandos a seguir:\n\n";
            $msg .= "Se o resultado for Vitória de *{$nick1}*\n";
            $msg .= "👉 /resultado {$matchID} {$nick1}\n";
            $msg .= "Se o resultado for Vitória de *{$nick2}*\n";
            $msg .= "👉 /resultado {$matchID} {$nick2}\n";
            $msg .= "Se o resultado for *Empate*\n";
            $msg .= "👉 /resultado {$matchID} empate\n";
            $msg .= "Se o resultado for *W.O. Duplo*\n";
            $msg .= "👉 /resultado {$matchID} woduplo";

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
                            if (!empty($p1['nicknameTGC']) && mb_strtolower(trim($p1['nicknameTGC'])) === $winnerStrLower) $isP1 = true;
                            if (!empty($p1['name']) && mb_strtolower(trim($p1['name'])) === $winnerStrLower) $isP1 = true;
                if (mb_strtolower(trim($nick1)) === $winnerStrLower) $isP1 = true;
                if ($winnerStrLower === '1') $isP1 = true;
            }

            if ($p2) {
                if ((string)$p2Id === $winnerStr) $isP2 = true;
                if (!empty($p2['nicknameTGC']) && mb_strtolower(trim($p2['nicknameTGC'])) === $winnerStrLower) $isP2 = true;
                                if (!empty($p2['name']) && mb_strtolower(trim($p2['name'])) === $winnerStrLower) $isP2 = true;
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
            if ($m['id'] == $matchID) {
                            $m['winnerID'] = $winnerId;
                $m['status'] = 'CONCLUIDO';
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        $results = getJson(FILE_SCHEDULES);
        $schedFound = false;
        foreach ($results as &$s) {
            if ($s['matchID'] == $matchID && ($s['status'] ?? '') != 'RECUSADO') {
                $s['status'] = 'PARTIDA_FINALIZADA';
                $s['resultWinnerID'] = $winnerId;
                $s['resultConfirmedBy'] = $pilotID;
                $s['updatedAt'] = date('Y-m-d H:i:s');
                unset($s['resultTempWinner']);
                                unset($s['resultProposalBy']);
                $schedFound = true;
                break;
            }
        }
        if (!$schedFound) {
            $results[] = [
                'id' => getNextId($results),
                'matchID' => $matchID,
                'status' => 'PARTIDA_FINALIZADA',
                'resultWinnerID' => $winnerId,
                'resultConfirmedBy' => $pilotID,
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s')
            ];
        }
        saveJson(FILE_SCHEDULES, $results);

        saveAudit($matchID, 0, 'RESULTADO confirmado por ADMIN', "Decidido por: " . ($currentPilot['name'] ?? 'Admin'));

        $resultLabel = ($winnerId == 0) ? "Resultado da partida {$matchID}: 🤝 *EMPATE*" : ($winnerId == -1 ? "👉 Resultado da partida {$matchID}: 🚫 *W.O. DUPLO*" : "Resultado da partida {$matchID} foi: 🏆 *Vencedor: {$winName}*");
        $msg = "👮‍♂️ *Resultado Definido por Admin*\n\n{$resultLabel}\n\nResultado registrado com sucesso.";
        respond($msg, [
            'state' => 'FINALIZADO_ADMIN',
            'matchID' => $matchID,
                    'winnerID' => $winnerId,
                    'winnerName' => $winName
        ]);

    case '/agendar':
        $parts = explode(' ', $function);
        $matchID = intval($parts[1]);
        $matches = getJson(FILE_MATCHES);
        $match = null;
        unset($m);
        foreach ($matches as $m) {
            if ($m['id'] == $matchID) { $match = $m; break; }
        }

        // Comando enviado em grupo
        if ($pilotID == 351935525827) {
            respond(
                "❌ Só consigo processar uma mensagem por vez! \n\nReenvie novamente o comando...\n\n" .
                "Link do TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.",
                []
            );
        }
        $sched = getMatchSchedule($matchID);
        $msg = "";
        $responseData = [
            'matchID' => $matchID,
            'state' => $sched['status'],
            'opponentID' => getTelegramIdByPilotId($match['player2ID'])
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
            $msg = "📅 *Agendamento #$matchID*\n\nNenhuma proposta ativa no momento.\n\nResponda as próximas mensagens com sua disponibilidade.";
            $responseData['state'] = 'REQUER_PROPOSTA';
            $prazo = date('d/m H:i', strtotime($match['deadline']));

        } else {
            $dt = date('d/m H:i', strtotime($sched['bookingDate']));
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
        $matchID = intval($parts[1] ?? 0);

        if (!$matchID || empty($bookDate) || empty($bookTime)) {
            respond("❌ Falta de parâmetros. Envie data e hora corretos.");
        }

        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) if ($m['id'] == $matchID) { $match = $m; break; }

        if (!$match) respond("❌ Partida #$matchID não encontrada.");

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
        $opponentID = null;

        if ($p1Id == $currentPilot['id']) {
            $opponentID = getTelegramIdByPilotId($p2Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p2Id));
        } else if ($p2Id == $currentPilot['id']) {
            $opponentID = getTelegramIdByPilotId($p1Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p1Id));
        }

        $responseData = [
            'matchID' => $matchID,
            'state' => 'ERRO',
            'nickname' => $nickname,
            'opponentID' => $opponentID,
            'bookingDate' => $dbFormattedDate,
            'tournament' => $match['tournament'],
        ];

        if ($proposedTimestamp > $deadlineTimestamp) {
            $limiteF = date('d/m/Y \à\s H:i', $deadlineTimestamp);
            $responseData['state'] = 'FORA_DO_PRAZO';
            respond("❌ Oops não foi possível finalizar\n\n*Atenção!* A data proposta ultrapassa o prazo final da partida, que é: *$limiteF*.\n\nPor favor, reinicie o processo com */agendar $matchID* e tente novamente com uma data válida.", $responseData);
        }

        // Validação de antecedência mínima de 2 horas
        $nowTimestamp = time();

        if ($proposedTimestamp < ($nowTimestamp + 7200)) {
            $minimoTimestamp = $nowTimestamp + 7200;
            $minimoF = date('d/m/Y \à\s H:i', $minimoTimestamp);
            $responseData['state'] = 'MENOS_DE_2_HORAS';
            respond("❌ Oops não foi possível finalizar\n\n*Atenção!* O horário proposto deve ser de pelo menos 2 horas a partir de agora.\n\nO primeiro horário permitido é: *$minimoF*.\n\nPor favor, reinicie o processo com */agendar $matchID* e tente novamente com uma data válida.", $responseData);
        }

        // Formatações solicitadas (MM/DD/YYYY e 12h AM/PM)
        $formattedDate = $dateObj->format('d/m/Y');
        $formattedTime = $timeObj->format('H:i');
        $tournament = $match['tournament'] ?? 'Torneio Desconhecido';

        $msg = "📨 Solicitação de agendamento enviada com sucesso!\nAguarde a confirmação do seu oponente.\n\n";
        $msg .= "📝 *Resumo da Proposta*\n\n";
        $msg .= "🏆 Torneio: {$tournament}\n";
        $msg .= "🆔 Partida: {$matchID}\n";
        $msg .= "📅 Data: {$formattedDate}\n";
        $msg .= "⏰ Hora: {$formattedTime}\n\n";
        $msg .= "⏳ *Lembrete 1:* A janela do seu jogo abrirá 30 minutos ANTES e fechará 30 minutos DEPOIS deste horário escolhido.\n\n";
        $msg .= "⏳ *Lembrete 2:* Mesmo que seu oponente não confirme a tempo, Você deve comparecer no seu horário proposto e enviar o */play $matchID* para registrar que você está disponível.\n\n";

        // 1. Atualizar schedules.bookingsData
        $results = getJson(FILE_SCHEDULES);
        $existingIndex = -1;
        unset($s);
        foreach ($results as $idx => $s) {
            if ($s['matchID'] == $matchID) { $existingIndex = $idx; break; }
        }

        $newSchedule = [
            'matchID' => $matchID,
            'bookingDate' => $dbFormattedDate,
            'status' => 'PROPOSTO',
            'proposedByPilotID' => $currentPilot['id'],
            'createdAt' => date('Y-m-d H:i:s')
        ];

        if ($existingIndex >= 0) {
            $newSchedule['id'] = $results[$existingIndex]['id'] ?? getNextId($results);
            $results[$existingIndex] = $newSchedule;
        } else {
            $newSchedule['id'] = getNextId($results);
            $results[] = $newSchedule;
        }
        saveJson(FILE_SCHEDULES, $results);

        // 2. Atualizar matches.bookingsData
        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchID) {
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
        saveAudit($matchID, $currentPilot['id'], 'PARTIDA_PROPOSTA', "Piloto realizou proposta para $dbFormattedDate. Aguardando confirmação do Oponente $opponentName.");
        $responseData['state'] = 'AGUARDANDO_OPONENTE';

        respond($msg, $responseData);

    case '/proposal_confirm':
        $parts = explode(' ', $function);
        $matchID = intval($parts[1] ?? 0);

        if (!$matchID) {
            respond("❌ Falta de parâmetros (ID da partida).");
        }

        // 1. Atualizar schedules.bookingsData
        $results = getJson(FILE_SCHEDULES);
        $existingIndex = -1;
        foreach ($results as $index => $s) {
            if ($s['matchID'] == $matchID) { $existingIndex = $index; break; }
        }

        if ($existingIndex >= 0) {
            // Se já existe, atualiza os dados preservando ID original e quem propôs
            $results[$existingIndex]['status'] = 'CONFIRMADO';
            $results[$existingIndex]['updatedAt'] = date('Y-m-d H:i:s');
            $results[$existingIndex]['actionByPilotID'] = $currentPilot['id'];
        } else {
            // Sem fallback de criação, apenas recusa e retorna erro.
            respond("❌ Erro: Não existe nenhuma proposta ativa para ser confirmada.", ['state' => 'ERRO_NENHUMA_PROPOSTA']);
        }

        saveJson(FILE_SCHEDULES, $results);

        // 2. Atualizar matches.bookingsData (Status global da partida)
        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchID) {
                $m['status'] = 'AGENDADO';
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        // 3. Salvar Auditoria
        saveAudit($matchID, $currentPilot['id'], 'CONFIRMADO', "Piloto confirmou o agendamento via API.");

        $matches = getJson(FILE_MATCHES);
        $match = null;
        unset($m);
        foreach ($matches as $m) { if ($m['id'] == $matchID) { $match = $m; break; } }
        if (!$match) respond("❌ Partida #$matchID não encontrada para confirmação.");

        $p1Id = $match['player1ID'] ;
        $p2Id = $match['player2ID'] ;
                $opponentID = null;

        if ($p1Id == $currentPilot['id']) {
                    $opponentID = getTelegramIdByPilotId($p2Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p2Id));
        } else if ($p2Id == $currentPilot['id']) {
                    $opponentID = getTelegramIdByPilotId($p1Id) ;
            $nickname = getPilotDisplayNameByNick(getPilotById($p1Id));
        }

        $dtTimestamp = strtotime($results[$existingIndex]['bookingDate']);
        $formattedTime = date('d/m H:i', $dtTimestamp);

        $responseData = [
            'matchID' => $matchID,
            'state' => 'CONFIRMADO',
            'nickname' => $nickname,
            'opponentID' => $opponentID,
            'bookingDate' => $formattedTime,
            'tournament' => $match['tournament'],
        ];

        respond("✅ *Agendamento Confirmado!*\n\nA partida está oficialmente agendada. O seu oponente será notificado.
        \n\nNo dia do jogo, lembre-se de usar */play {$matchID}*.", $responseData);

    case '/poleposition':
        $parts = explode(' ', $function);
        $matchID = intval($parts[1]);
        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) {
            if ($m['id'] == $matchID) { $match = $m; break; }
        }

        if ($pilotID == 351935525827) {
            $responseData = ['state' => 'ERRO_NAO_PERTENCE'];
            respond( "❌ Só consigo processar uma mensagem por vez! \n\nReenvie novamente o comando...\n\n" .
                "Link do TopGearTGCBot https://chat.whatsapp.com/F4NcJEt40Kb6rcyq6tn6MG e execute o comando por lá.", $responseData );
        }

        if (!$match) {
            $responseData = ['state' => 'ERRO_NAO_ENCONTRADO'];
            respond("❌ Partida não encontrada. \n\nRevise o número com o */partidas*", $responseData);
        }

        if ($match['status'] == "CONCLUIDO") {
            $responseData = ['state' => 'RODADA_FINALIZADA'];
            respond("🚫 *Atenção:* Infelizmente essa rodada já encerrou\n\nRevise o número da rodada ativa com o */partidas*", $responseData);
        }

        $p1Id = $match['player1ID'] ;
        $p2Id = $match['player2ID'] ;

        if ($p1Id != $currentPilot['id'] && $p2Id != $currentPilot['id']) {
            $responseData = ['state' => 'ERRO_NAO_PERTENCE'];
            respond("❌ Esta partida não é sua. \n\nRevise o número com o */partidas*", $responseData);
        }

        //De baixo pra cima, pega o último resultado da partida.
        $results = getJson(FILE_RESULTS_T8);
        $result = null;
        for ($i = count($results) - 1; $i >= 0; $i--) {
            if ($results[$i]['matchID'] == $matchID) {
                $result = $results[$i]; break;
            }
        }

        $resultadoAtual = [
            'id' => $result['id'],
            'matchID' => $matchID,
            'roundID' => $result['roundID'],
            'pilotID' => $result['pilotID'],
            'totalTime' => formatMsToTime((int)$result['totalTime']),
            'times' => is_array($result['times']) ? array_map(function($time) {
                return formatMsToTime((int)$time);
            }, $result['times']) : [],
            'link' => $result['proof']['url']
        ];

        if ($match['status'] == 'CONFIRMADO_SEM_VIDEO') {
            if ($result['pilotID'] == $pilotID) {
                $responseData = ['state' => 'CONFIRMADO_SEM_VIDEO'] + $resultadoAtual + $match['localTrack'];
                respond("📝 Você já enviou os seus tempos anteriormente.\n\n*Deseja enviar o link agora ?*", $responseData );
            }
        }

        if ($match['status'] == 'CONFIRMADO_PODE_EDITAR') {
            if ($result['pilotID'] == $pilotID) {
                $responseData = ['state' => 'CONFIRMADO_PODE_EDITAR'] + $resultadoAtual + $match['localTrack'];
                respond("📝 Você já enviou os seus tempos anteriormente.\n\n*Deseja enviar melhores tempos agora ?*", $responseData);
            }
        }

        $msg = "🏁 *Pistas da partida #{$match['id']}*\n\n";
        foreach ($match['localTrack'] as $track) {
            $msg .= "🏎️ {$track}\n";
        }
        $msg .= "\nEnvie agora os tempos!";

        $responseData = ['state' => 'PENDENTE_TEMPOS'] + $match['localTrack'];

        respond($msg, $responseData);

    case '/polepositionsendtimes':
        $parts = explode(' ', $function);
        $matchID = intval($parts[1]);
        $matches = getJson(FILE_MATCHES);
        $match = null;
        foreach ($matches as $m) {
            if ($m['id'] == $matchID) { $match = $m; break; }
        }

        if (!$match) {
            $responseData = ['state' => 'ERRO_NAO_ENCONTRADO'];
            respond("❌ Partida não encontrada. \n\nRevise o número com o */partidas*", $responseData);
        }

        if ($match['status'] == "CONCLUIDO") {
            $responseData = ['state' => 'RODADA_FINALIZADA'];
            respond("🚫 *Atenção:* Infelizmente essa rodada já encerrou\n\nRevise o número da rodada ativa com o */partidas*", $responseData);
        }

        $message = $input['message'];
        $timesRecebidos = $message['times'] ;
        $videoLink = $message['videoLink'] ;
        $pilotID = $message['from']['pilotID'] ;
        $roundID = $match['groupName'];
        $results = getJson(FILE_RESULTS_T8);

        //Atualização apenas do link de vídeo, sem alterar tempos anteriores
        if ($timesRecebidos == null) {
            for ($i = count($results) - 1; $i >= 0; $i--) {
                if ((int)$results[$i]['matchID'] === $matchID && (int)$results[$i]['pilotID'] === $pilotID) {
                    $results[$i]['proof']['url'] = trim($videoLink);
                    break;
                }
            }
            saveJson(FILE_RESULTS_T8, $results);

            $allMatches = getJson(FILE_MATCHES);
            foreach ($allMatches as &$m) {
                if ((int)$m['id'] === $matchID) {
                    $m['status'] = 'CONFIRMADO_PODE_EDITAR';
                    break;
                }
            }
            unset($m);
            saveJson(FILE_MATCHES, $allMatches);
            $responseData = ['state' => 'CONFIRMADO_PODE_EDITAR'];
            respond("✅ Sucesso:\nLink da sua partida atualizado com sucesso.", $responseData);
        }

        $times = [];
        $totalTime = 0;

        foreach ($timesRecebidos as $item) {
            $pista = trim($item['pista'] );
            $tempo = trim($item['tempo'] );

            if (!preg_match('/^(\d+):([0-5]\d):(\d{3})$/', $tempo, $tempoMatch)) {
                respond( "❌ Erro: o tempo {$tempo} possui formato inválido.", ['state' => 'ERRO_DADOS'] );
            }

            $minutos = intval($tempoMatch[1]);
            $segundos = intval($tempoMatch[2]);
            $milissegundos = intval($tempoMatch[3]);

            $tempoMs =
                ($minutos * 60 * 1000) +
                ($segundos * 1000) +
                $milissegundos;

            if (!preg_match('/^(\d{2})\s/', $pista, $pistaMatch)) {
                respond( "❌ Erro: não foi possível identificar o ID da pista: {$pista}", ['state' => 'ERRO_DADOS'] );
            }

            $trackID = intval($pistaMatch[1]);

            $times[(string)$trackID] = $tempoMs;
            $totalTime += $tempoMs;
        }

        $novoId = 1;
        if (!empty($results)) {
            $ids = array_column($results, 'id');
            $novoId = max($ids) + 1;
        }

        $agora = date('c');

        $novoResultado = [
            'id' => $novoId,
            'matchID' => $matchID,
            'roundID' => $roundID,
            'pilotID' => $pilotID,
            'totalTime' => $totalTime,
            'times' => $times,
            'proof' => [
                'url' => $videoLink
            ],
            'audit' => [
                'submittedAt' => $agora,
                'published' => false,
                'publishedAt' => null,
                'approvedBy' => null
            ]
        ];

        // Adiciona novo resultado sem alterar resultados anteriores
        $results[] = $novoResultado;
        saveJson(FILE_RESULTS_T8, $results);

        // Atualizar matches
        $responseData = ['state' => 'ERRO_DADOS'];
        $allMatches = getJson(FILE_MATCHES);
        foreach ($allMatches as &$m) {
            if ($m['id'] == $matchID) {
                if ($videoLink == "N/A") {
                    $responseData = ['state' => 'CONFIRMADO_SEM_VIDEO'] + $match['localTrack'];
                    $m['status'] = 'CONFIRMADO_SEM_VIDEO';
                } else {
                    $responseData = ['state' => 'CONFIRMADO_PODE_EDITAR'] + $match['localTrack'];
                    $m['status'] = 'CONFIRMADO_PODE_EDITAR';
                }
                break;
            }
        }
        saveJson(FILE_MATCHES, $allMatches);

        $msg = "✅ *Tempos enviados com sucesso!*\n";
        $msg .= "Aguarde a validação do Admin.";
        $msg .= "\n\nMandou bem!\nPara ver sua classificação nessa\nrodada, use o comando:";
        $msg .= "\n*/poleRounds $roundID*";
        $msg .= "\n\nPara ver sua classificação geral no\ntorneio, use o comando:";
        $msg .= "\n*/poleFinalStandings*";
        $msg .= "\n\n👏🏽 Obrigado por participar! 🏁 ";

        respond( $msg, $responseData );

    case '/polerounds':
        $parts = explode(' ', $function);
        // O parâmetro enviado agora é estritamente o número da rodada (ex: /poleRounds 2)
        $roundNumber = intval($parts[1] ?? 0);

        if ($roundNumber <= 0) {
            respond("❌ Por favor, informe o número da rodada.\nExemplo: */poleRounds 2*", ['state' => 'ERRO_PARAMETRO_INVALIDO']);
        }

        $targetRound = "Rodada " . $roundNumber;

        $matches = getJson(FILE_MATCHES);
        $pilots = getJson(FILE_PILOTS);

        $roundMatches = [];
        $concludedCount = 0;

        foreach ($matches as $m) {
            if (($m['tournamentId'] ?? '') === 'T8' && trim($m['groupName'] ?? '') === $targetRound) {
                $roundMatches[] = $m;
                if (($m['status'] ?? '') === 'CONCLUIDO') {
                    $concludedCount++;
                }
            }
        }

        $totalRoundMatches = count($roundMatches);

        if ($totalRoundMatches === 0) {
            respond("❌ Não há partidas encontrada para a {$targetRound}.", ['state' => 'ERRO_NAO_ENCONTRADO']);
        }

        $isAdm = isAdmin($pilotID);

        // Regra de Trava: Algumas concluídas, mas não todas
        if ($concludedCount > 0 && $concludedCount < $totalRoundMatches) {
            $msg = "❌ *Status Inconsistente*\n\nAlgumas partidas desta rodada ({$concludedCount}/{$totalRoundMatches}) constam como CONCLUÍDO e outras não.\n\nPor favor, contate a Administração para fazer uma revisão antes de ver os resultados.";
            respond($msg, ['state' => 'ERRO_STATUS_INCONSISTENTE']);
        }

        $standings = getJson(FILE_STANDINGS_T8);
        $tournamentInfo = getJson( FILE_TOURNAMENTS_DATA_T8);

        // Regra de Trava Final: Todas concluídas e usuário normal -> Retorna o que já tá salvo no standings sem reordenar
        if ($concludedCount === $totalRoundMatches && !$isAdm) {
            $existingRound = null;
            foreach ($standings as $s) {
                if (($s['roundID'] ?? '') === $targetRound) {
                    $existingRound = $s;
                    break;
                }
            }

            if ($existingRound) {
                $msg = "🏎️ *Classificação Final - {$targetRound}* 🏁\n\n";
                foreach ($existingRound['results'] as $p) {
                    // Resgatar o nome do piloto para exibir na mensagem (pois é removido no JSON final do standings)
                    $pInfo = getPilotById($p['pilotID'], $pilots);
                    $pName = getPilotDisplayNameByNick($pInfo);

                    $pos = $p['rank'] > 0 ? "{$p['rank']}º" : "DNF";
                    $timeFmt = $p['totalTime'] > 0 ? formatMsToTime($p['totalTime']) : "--:--:---";
                    $pts = $p['points'] > 0 ? "(+{$p['points']} pts)" : "";

                    $msg .= "{$pos} - {$pName} - {$timeFmt} {$pts}\n";
                }
                $msg .= "\nEssa rodada já foi encerrada e os resultados finais são:";

                respond($msg, [
                    'state' => 'CLASSIFICACAO_RODADA_FINALIZADA',
                    'tournamentName' => $tournamentInfo['name'],
                    'results' => $existingRound['results']
                ]);
            } else {
                respond("❌ A rodada consta como concluída, mas a classificação final não foi encontrada no sistema.", ['state' => 'ERRO_NAO_ENCONTRADO']);
            }
        }

        $allResults = getJson(FILE_RESULTS_T8);
        $scoringData = getJson(FILE_SCORING_T8);
        $pointsMap = $scoringData['pointsByPosition'] ?? [];

        $roundPilots = [];
        foreach ($roundMatches as $m) {
            $mID = (int)$m['id'];

            // Localiza quem é o oponente humano (Diferente do Ritchie id 999)
            $p1Id = (int)$m['player1ID'];
            $p2Id = (int)$m['player2ID'];
            $humanPilotId = ($p1Id === 999) ? $p2Id : $p1Id;
            $humanPilot = getPilotById($humanPilotId, $pilots);

            $latestResultForMatch = null;
            foreach ($allResults as $r) {
                if ((int)$r['matchID'] === $mID) {
                    // Se houver mais de 1 reenvio, priorizamos o de maior ID (mais recente)
                    if (!$latestResultForMatch || (int)$r['id'] > (int)$latestResultForMatch['id']) {
                        $latestResultForMatch = $r;
                    }
                }
            }

            $roundPilots[] = [
                'rank' => 0,
                'pilotNickName' => getPilotDisplayNameByNick($humanPilot),
                'roundID' => $targetRound,
                'resultID' => $latestResultForMatch ? $latestResultForMatch['id'] : null,
                'totalTime' => $latestResultForMatch ? (int)$latestResultForMatch['totalTime'] : 0,
                'totalTimeFormatted' => $latestResultForMatch ? formatMsToTime((int)$latestResultForMatch['totalTime']) : "0:00:000",
                'points' => 0
            ];
        }

        // Separa quem mandou tempo e quem ainda não enviou
        $finishedPilots = array_filter($roundPilots, fn($p) => $p['totalTime'] > 0);
        $dnfPilots = array_filter($roundPilots, fn($p) => $p['totalTime'] == 0);

        usort($finishedPilots, function($a, $b) {
            return $a['totalTime'] <=> $b['totalTime'];
        });

        $CurrentsRoundResults = [];
        $currentRank = 1;
        $actualPosition = 1;
        $previousTime = null;

        foreach ($finishedPilots as $p) {
            if ($previousTime !== null) {
                if ($p['totalTime'] == $previousTime) {
                    // Empate: Mantém o $currentRank igual ao do piloto anterior
                } else {
                    $currentRank = $actualPosition; // Avança para a posição atual, "pulando" ranks de empates
                }
            }
            $p['rank'] = $currentRank;

            if (isset($pointsMap[(string)$currentRank])) {
                $p['points'] = $pointsMap[(string)$currentRank];
            } else {
                // Participante válido, mas além do Top 10 ganha 1 ponto de participação
                $p['points'] = 1;
            }

            $previousTime = $p['totalTime'];
            $actualPosition++;
            $CurrentsRoundResults[] = $p;
        }

        // DNF são anexados ao final da tabela com Rank e Pontos zerados
        foreach ($dnfPilots as $p) {
            $p['rank'] = 0;
            $p['points'] = 0;
            $CurrentsRoundResults[] = $p;
        }

        $msg = "\n\nPara ver a sua classificação geral no torneio,\nuse o comando:";
        $msg .= "\n*/poleFinalStandings*";

        // Preparação para atualizar o objeto no db de forma limpa (sem strings extras de display)
        $roundExists = false;
        $finalStandingsForJson = [
            'id' => 0,
            'roundID' => $targetRound,
            'results' => $CurrentsRoundResults
        ];

        foreach ($standings as &$s) {
            if (($s['roundID'] ?? '') == $targetRound) {
                $finalStandingsForJson['id'] = $s['id'] ?? getNextId($standings);
                $s = $finalStandingsForJson;
                $roundExists = true;
                break;
            }
        }

        if (!$roundExists) {
            $finalStandingsForJson['id'] = getNextId($standings);
            $standings[] = $finalStandingsForJson;
        }

        saveJson(FILE_STANDINGS_T8, $standings);

        respond($msg, [
            'state' => 'CLASSIFICACAO_RODADA',
            'tournamentName' => $tournamentInfo['name'],
            'results' => $CurrentsRoundResults
        ]);

    case '/polefinalstandings':
    // 1. Carrega apenas o standings.json, tornando independente do matches.json
    $standings = getJson(FILE_STANDINGS_T8);

    if (empty($standings)) {
        respond("❌ Nenhuma rodada foi registrada até o momento no torneio.", ['state' => 'ERRO_STANDINGS_VAZIO']);
    }

    $aggregated = [];

    // 2. Itera sobre todas as rodadas salvas
    foreach ($standings as $round) {
        $results = $round['results'] ?? [];

        foreach ($results as $r) {
            // Compatibilidade: tenta usar pilotNickName (novo padrão) ou resolve via pilotID
            $pilotIdentifier = $r['pilotNickName'] ?? '';

            if (empty($pilotIdentifier) && isset($r['pilotID'])) {
                $pilots = getJson(FILE_PILOTS);
                $pInfo = getPilotById($r['pilotID'], $pilots);
                $pilotIdentifier = getPilotDisplayNameByNick($pInfo);
            }

            if (empty($pilotIdentifier)) {
                continue; // Pula se não conseguir identificar o piloto
            }

            // Inicializa o piloto no array de consolidação se não existir
            if (!isset($aggregated[$pilotIdentifier])) {
                $aggregated[$pilotIdentifier] = [
                    'pilotNickName' => $pilotIdentifier,
                    'totalPoints' => 0,
                    'totalTime' => 0,
                    'validRounds' => 0,
                    'positionsCount' => [] // Novo array para registrar as posições de cada rodada
                ];
            }

            // 3. Soma os pontos e os tempos totais
            $aggregated[$pilotIdentifier]['totalPoints'] += (int)($r['points'] ?? 0);

            $time = (int)($r['totalTime'] ?? 0);
            if ($time > 0) { // Soma apenas tempos válidos (Ignora DNFs com 0 nas rodadas)
                $aggregated[$pilotIdentifier]['totalTime'] += $time;
                $aggregated[$pilotIdentifier]['validRounds']++; // Conta rodadas em que ele pontuou tempo
            }

            // Armazena a posição conquistada nesta rodada para o desempate (Regra 1)
            $pos = (int)($r['rank'] ?? 0);
            if ($pos > 0) {
                $aggregated[$pilotIdentifier]['positionsCount'][$pos] = ($aggregated[$pilotIdentifier]['positionsCount'][$pos] ?? 0) + 1;
            }
        }
    }

    // 4. Converte o dicionário associativo para um array indexado
    $finalStandings = array_values($aggregated);

    // 5. Função de Comparação para Ordenação e Desempate (Closure)
    $comparePilots = function($a, $b) {
        // Regra Base: Maior Pontuação Total (Descendente)
        if ($a['totalPoints'] !== $b['totalPoints']) {
            return $b['totalPoints'] <=> $a['totalPoints'];
        }

        // Regra Desempate 1: Melhores posições conquistadas (Countback)
        // Varre do 1º ao 50º lugar para ver quem tem mais daquela posição
        for ($i = 1; $i <= 32; $i++) {
            $countA = $a['positionsCount'][$i] ?? 0;
            $countB = $b['positionsCount'][$i] ?? 0;
            if ($countA !== $countB) {
                return $countB <=> $countA; // Descendente (Quem tem maior contagem vence)
            }
        }

        // Regra Desempate 2: Menor número de DNFs (Maior número de rodadas válidas)
        if ($a['validRounds'] !== $b['validRounds']) {
            return $b['validRounds'] <=> $a['validRounds'];
        }

        // Regra Desempate 3: Menor Tempo Total (ignorando 0ms do DNF que recebe o maximo do PHP)
        $timeA = $a['totalTime'] > 0 ? $a['totalTime'] : PHP_INT_MAX;
        $timeB = $b['totalTime'] > 0 ? $b['totalTime'] : PHP_INT_MAX;
        if ($timeA !== $timeB) {
            return $timeA <=> $timeB; // Ascendente (Menor tempo vence)
        }

        // Regra Desempate 4: Retorna 0 (Empate absoluto)
        return 0;
    };

    // Aplica a ordenação
    usort($finalStandings, $comparePilots);

    // 6. Preparação final, injeção do tempo formatado e atribuição de rank
    $rankIndex = 1;
    $actualRank = 1;
    $prevPilot = null;

    foreach ($finalStandings as &$fs) {
        // Checa se o piloto atual empatou absolutamente em todas as 3 regras com o piloto anterior
        if ($prevPilot !== null && $comparePilots($fs, $prevPilot) === 0) {
            // Mantém o mesmo rank do piloto anterior
        } else {
            // Atualiza o rank de fato (ex: se o 1º e 2º empataram e pegaram Rank 1, o 3º cara recebe Rank 3)
            $actualRank = $rankIndex;
        }

        $fs['rank'] = $actualRank;
        $fs['totalTimeFormatted'] = $fs['totalTime'] > 0 ? formatMsToTime($fs['totalTime']) : "0:00:000";

        // Remove a estrutura de contagem para não poluir o JSON que vai para o Front-End
        unset($fs['positionsCount']);

        $prevPilot = $fs;
        $rankIndex++;
    }

    $msg = " \n🏁 *Parabéns vc chegou na linha de chegada!* 🏁\n👏🏽 *Obrigado por participar!* 👏🏽";

    $tournamentInfo = getJson( FILE_TOURNAMENTS_DATA_T8);
    respond($msg, [
        'state' => 'CLASSIFICACAO_GERAL_FINAL',
        'tournamentName' => $tournamentInfo['name'],
        'results' => $finalStandings
    ]);

    default:
        respond("❓ Comando não reconhecido ou não suportado via API.");
}