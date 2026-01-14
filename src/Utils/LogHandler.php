<?php
/**
 * ============================================================
 * LOG HANDLER UTILITY CLASS
 * ============================================================
 * Gerenciador de logs centralizado
 */

class LogHandler {
    
    const TYPE_BOT = 'botMain.log';
    const TYPE_SECURITY = 'admin-security.log';
    const TYPE_ERROR = 'errors.log';
    
    /**
     * Escrever log
     * 
     * @param string $message Mensagem
     * @param string $type Tipo de log (botMain.log, admin-security.log, etc)
     * @param array $context Contexto adicional
     */
    public static function write($message, $type = self::TYPE_BOT, $context = []) {
        if (!defined('LOG_DIR')) {
            return false;
        }
        
        $logFile = LOG_DIR . '/' . $type;
        
        $timestamp = date('[Y-m-d H:i:s]');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $entry = "$timestamp $message$contextStr\n";
        
        return @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Escrever log de ação do bot
     * 
     * @param string $action Nome da ação
     * @param array $data Dados da ação
     */
    public static function logBotAction($action, $data = []) {
        return self::write("[BOT_ACTION] $action", self::TYPE_BOT, $data);
    }
    
    /**
     * Escrever log de agendamento
     * 
     * @param int $matchId ID da partida
     * @param int $pilotA ID Piloto A
     * @param int $pilotB ID Piloto B
     * @param string $status Status (PROPOSTO, CONFIRMADO, RECUSADO, etc)
     * @param string $dataHora Data/Hora do agendamento
     */
    public static function logSchedule($matchId, $pilotA, $pilotB, $status, $dataHora) {
        $action = "AGENDAMENTO_$status";
        $data = [
            'match_id' => $matchId,
            'pilot_a' => $pilotA,
            'pilot_b' => $pilotB,
            'data_hora' => $dataHora
        ];
        return self::logBotAction($action, $data);
    }
    
    /**
     * Escrever log de rotação de temporada
     * 
     * @param string $adminId ID do admin
     * @param string $backupDir Diretório do backup
     */
    public static function logSeasonRotation($adminId, $backupDir) {
        $action = "ROTACAO_TEMPORADA";
        $data = [
            'admin_id' => $adminId,
            'backup_dir' => $backupDir
        ];
        return self::logBotAction($action, $data);
    }
    
    /**
     * Escrever log de novo membro
     * 
     * @param int $pilotId ID do piloto
     * @param string $pilotName Nome do piloto
     */
    public static function logNewMember($pilotId, $pilotName) {
        $action = "NOVO_MEMBRO";
        $data = [
            'pilot_id' => $pilotId,
            'pilot_name' => $pilotName
        ];
        return self::logBotAction($action, $data);
    }
    
    /**
     * Escrever log de erro
     * 
     * @param string $message Mensagem de erro
     * @param string $file Arquivo
     * @param int $line Linha
     */
    public static function logError($message, $file = '', $line = 0) {
        $msg = "ERROR: $message";
        if ($file) {
            $msg .= " in $file:$line";
        }
        return self::write($msg, self::TYPE_ERROR);
    }
    
    /**
     * Obter últimas N linhas do log
     * 
     * @param int $lines Número de linhas
     * @param string $type Tipo de log
     * @return string Conteúdo
     */
    public static function getLastLines($lines = 50, $type = self::TYPE_BOT) {
        if (!defined('LOG_DIR')) {
            return '';
        }
        
        $logFile = LOG_DIR . '/' . $type;
        
        if (!file_exists($logFile)) {
            return "Log vazio.";
        }
        
        $allLines = file($logFile);
        $lastLines = array_slice($allLines, -$lines);
        
        return implode('', $lastLines);
    }
    
    /**
     * Limpar arquivo de log
     * 
     * @param string $type Tipo de log
     * @return bool
     */
    public static function clear($type = self::TYPE_BOT) {
        if (!defined('LOG_DIR')) {
            return false;
        }
        
        $logFile = LOG_DIR . '/' . $type;
        
        if (file_exists($logFile)) {
            // Fazer backup antes de limpar
            $backupFile = $logFile . '.backup.' . date('Y-m-d-His');
            @copy($logFile, $backupFile);
        }
        
        // Limpar
        return file_put_contents($logFile, '');
    }
    
    /**
     * Obter tamanho do arquivo de log em MB
     * 
     * @param string $type Tipo de log
     * @return float Tamanho em MB
     */
    public static function getSize($type = self::TYPE_BOT) {
        if (!defined('LOG_DIR')) {
            return 0;
        }
        
        $logFile = LOG_DIR . '/' . $type;
        
        if (!file_exists($logFile)) {
            return 0;
        }
        
        $bytes = filesize($logFile);
        return round($bytes / (1024 * 1024), 2);
    }
}

?>
