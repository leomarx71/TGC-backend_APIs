<?php
/**
 * ============================================================
 * BACKUP MANAGER UTILITY CLASS
 * ============================================================
 * Gerenciador de backups e rotação de temporada
 */

class BackupManager {
    
    /**
     * Cria apenas um backup (Snapshot) sem alterar/limpar os dados atuais.
     * Útil para o botão "Criar Backup" do admin.
     * * @param string $adminId ID do admin solicitante
     * @return array Resultado da operação
     */
    public static function createBackupSnapshot($adminId = 'system') {
        if (!defined('BACKUP_DIR') || !defined('DATA_DIR')) {
            return ['success' => false, 'error' => 'Constantes BACKUP_DIR ou DATA_DIR não definidas'];
        }

        // Criar pasta de backup com data/hora
        $timestamp = date('Y-m-d_His');
        $backupDir = BACKUP_DIR . '/' . $timestamp;
        
        if (!@mkdir($backupDir, 0755, true)) {
            return ['success' => false, 'error' => "Não foi possível criar diretório: $backupDir"];
        }

        $files_backed_up = [];

        try {
            // Arquivos para backup
            $filesToBackup = glob(DATA_DIR . '/*.json');

            foreach ($filesToBackup as $file) {
                if (file_exists($file)) {
                    $filename = basename($file);
                    $backupFile = $backupDir . '/' . $filename . '.backup';
                    
                    if (@copy($file, $backupFile)) {
                        $files_backed_up[] = $filename;
                    }
                }
            }

            // Tenta logar se a classe existir
            if (class_exists('LogHandler') && method_exists('LogHandler', 'logSeasonRotation')) {
                // LogHandler::logBackup($adminId, $timestamp); // Exemplo se existisse
            }

            return [
                'success' => true,
                'timestamp' => $timestamp,
                'backup_dir' => $backupDir,
                'files_backed_up' => $files_backed_up,
                'message' => "Backup criado com sucesso em: $backupDir"
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Executar rotação de temporada
     * * Remove dados de matches, schedules, audit
     * Mas MANTÉM dados de pilots (com status intacto)
     * Faz backup de todos os arquivos com timestamp
     * * @param string $adminId ID do admin que rotacionou
     * @return array Resultado da operação
     */
    public static function rotateSeasonFull($adminId = 'system') {
        if (!defined('BACKUP_DIR') || !defined('DATA_DIR')) {
            return [
                'success' => false,
                'error' => 'Constantes não definidas'
            ];
        }
        
        // Criar pasta de backup com data/hora
        $timestamp = date('Y-m-d_His');
        $backupDir = BACKUP_DIR . '/' . $timestamp;
        
        if (!@mkdir($backupDir, 0755, true)) {
            return [
                'success' => false,
                'error' => "Não foi possível criar diretório: $backupDir"
            ];
        }
        
        $files_backed_up = [];
        $files_cleared = [];
        
        try {
            // === ARQUIVOS PARA FAZER BACKUP E DEPOIS LIMPAR ===
            $filesToClear = [
                defined('FILE_MATCHES') ? FILE_MATCHES : DATA_DIR . '/matches.json',
                defined('FILE_SCHEDULES') ? FILE_SCHEDULES : DATA_DIR . '/schedules.json',
                defined('FILE_AUDIT') ? FILE_AUDIT : DATA_DIR . '/auditSchedules.json'
            ];
            
            foreach ($filesToClear as $file) {
                if (file_exists($file)) {
                    $filename = basename($file);
                    $backupFile = $backupDir . '/' . $filename . '.backup';
                    
                    // Fazer backup
                    if (@copy($file, $backupFile)) {
                        $files_backed_up[] = $filename;
                    }
                    
                    // Limpar arquivo original (escrever JSON vazio)
                    $emptyData = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    if (@file_put_contents($file, $emptyData)) {
                        $files_cleared[] = $filename;
                    }
                }
            }
            
            // === ARQUIVO DE PILOTS - APENAS BACKUP, NÃO LIMPA ===
            $filePilots = defined('FILE_PILOTS') ? FILE_PILOTS : DATA_DIR . '/pilots.json';
            if (file_exists($filePilots)) {
                $backupFile = $backupDir . '/pilots.json.backup';
                @copy($filePilots, $backupFile);
                $files_backed_up[] = 'pilots.json (backup apenas)';
            }
            
            // === ARQUIVO DE SESSIONS - APENAS LIMPEZA ===
            $fileSessions = defined('FILE_SESSIONS') ? FILE_SESSIONS : DATA_DIR . '/sessions.json';
            if (file_exists($fileSessions)) {
                $emptyData = json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                @file_put_contents($fileSessions, $emptyData);
                $files_cleared[] = 'sessions.json';
            }
            
            // === REGISTRAR NO LOG ===
            if (class_exists('LogHandler') && method_exists('LogHandler', 'logSeasonRotation')) {
                LogHandler::logSeasonRotation($adminId, $timestamp);
            }
            
            return [
                'success' => true,
                'timestamp' => $timestamp,
                'backup_dir' => $backupDir,
                'files_backed_up' => $files_backed_up,
                'files_cleared' => $files_cleared,
                'message' => "Rotação concluída! Backup em: $backupDir"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'backup_dir' => $backupDir
            ];
        }
    }
    
    /**
     * Restaurar backup de uma data/hora específica
     * * @param string $timestamp Data/hora do backup (YYYY-MM-DD_HHiiss)
     * @return array Resultado
     */
    public static function restoreBackup($timestamp) {
        if (!defined('BACKUP_DIR') || !defined('DATA_DIR')) {
            return [
                'success' => false,
                'error' => 'Constantes não definidas'
            ];
        }
        
        $backupDir = BACKUP_DIR . '/' . $timestamp;
        
        if (!is_dir($backupDir)) {
            return [
                'success' => false,
                'error' => "Backup não encontrado: $backupDir"
            ];
        }
        
        $filesRestored = [];
        
        try {
            // Restaurar cada arquivo .backup
            $files = glob($backupDir . '/*.backup');
            
            foreach ($files as $backupFile) {
                $originalName = str_replace('.backup', '', basename($backupFile));
                $originalFile = DATA_DIR . '/' . $originalName;
                
                if (@copy($backupFile, $originalFile)) {
                    $filesRestored[] = $originalName;
                }
            }
            
            return [
                'success' => true,
                'timestamp' => $timestamp,
                'files_restored' => $filesRestored,
                'message' => "Backup restaurado com sucesso!"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Listar todos os backups disponíveis
     * * @return array Lista de backups
     */
    public static function listBackups() {
        if (!defined('BACKUP_DIR')) {
            return [];
        }
        
        $backups = [];
        
        if (!is_dir(BACKUP_DIR)) {
            return $backups;
        }
        
        $dirs = glob(BACKUP_DIR . '/????-??-??_??????', GLOB_ONLYDIR);
        
        if ($dirs) {
            foreach ($dirs as $dir) {
                $timestamp = basename($dir);
                $files = count(glob($dir . '/*.backup'));
                $size = self::getDirSize($dir);
                
                $backups[] = [
                    'timestamp' => $timestamp,
                    'files' => $files,
                    'size_mb' => round($size / (1024 * 1024), 2),
                    'path' => $dir
                ];
            }
        }
        
        // Ordenar por data decrescente
        usort($backups, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        
        return $backups;
    }
    
    /**
     * Deletar um backup específico
     * * @param string $timestamp Data/hora do backup
     * @return bool
     */
    public static function deleteBackup($timestamp) {
        if (!defined('BACKUP_DIR')) {
            return false;
        }
        
        $backupDir = BACKUP_DIR . '/' . $timestamp;
        
        if (!is_dir($backupDir)) {
            return false;
        }
        
        return self::deleteDirectory($backupDir);
    }
    
    /**
     * Limpar todos os backups antigos (mais de N dias)
     * * @param int $days Dias de retenção
     * @return int Número de backups deletados
     */
    public static function cleanOldBackups($days = 30) {
        if (!defined('BACKUP_DIR')) {
            return 0;
        }
        
        $deleted = 0;
        $maxAge = time() - ($days * 86400);
        
        $dirs = glob(BACKUP_DIR . '/????-??-??_??????', GLOB_ONLYDIR);
        
        if ($dirs) {
            foreach ($dirs as $dir) {
                $mtime = filemtime($dir);
                
                if ($mtime < $maxAge) {
                    if (self::deleteDirectory($dir)) {
                        $deleted++;
                    }
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * Obter tamanho total de um diretório
     * * @param string $dir Caminho do diretório
     * @return int Tamanho em bytes
     */
    private static function getDirSize($dir) {
        $size = 0;
        
        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                $size += filesize($file);
            }
        }
        
        return $size;
    }
    
    /**
     * Deletar diretório recursivamente
     * * @param string $dir Caminho do diretório
     * @return bool
     */
    private static function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return @unlink($dir);
        }
        
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                self::deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        
        return @rmdir($dir);
    }
}
?>