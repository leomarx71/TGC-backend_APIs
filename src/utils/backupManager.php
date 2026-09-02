<?php
/**
 * ============================================================
 * BACKUP MANAGER UTILITY CLASS
 * ============================================================
 * Gerenciador de backups e rotação de temporada
 */

if (!defined('CONFIG_LOADED')) {
    require_once __DIR__ . '/../config/environment.php';
}

class backupManager {
    
    /**
     * Cria apenas um backup (Snapshot)
     */
    public static function createBackupSnapshot(): array
    {
        if (!defined('BACKUP_DIR') || !defined('BOOKINGS_DATA_DIR') || !defined('TOURNAMENTS_DATA_DIR')) {
            return [
                'success' => false,
                'error' => 'Constantes BACKUP_DIR, BOOKINGS_DATA_DIR ou TOURNAMENTS_DATA_DIR não definidas'
            ];
        }

        $timestamp = date('Y-m-d_His');
        $backupDir = BACKUP_DIR . '/' . $timestamp;
        $zipFile = BACKUP_DIR . '/' . $timestamp . '.zip';

        try {
            if (!mkdir($backupDir, 0755, true)) {
                return [
                    'success' => false,
                    'error' => "Não foi possível criar diretório: $backupDir"
                ];
            }

            $copyDirectory = function ($source, $destination) use (&$copyDirectory) {
                if (!is_dir($source)) {
                    throw new Exception("Diretório não encontrado: $source");
                }

                if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
                    throw new Exception("Não foi possível criar diretório: $destination");
                }

                foreach (scandir($source) as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }

                    $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
                    $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

                    if (is_dir($sourcePath)) {
                        $copyDirectory($sourcePath, $destinationPath);
                    } else {
                        if (!copy($sourcePath, $destinationPath)) {
                            throw new Exception("Não foi possível copiar: $sourcePath");
                        }
                    }
                }
            };

            $copyDirectory(
                rtrim(BOOKINGS_DATA_DIR, '/\\'),
                $backupDir . '/bookingsData'
            );

            $copyDirectory(
                rtrim(TOURNAMENTS_DATA_DIR, '/\\'),
                $backupDir . '/tournamentsData'
            );

            $zip = new ZipArchive();

            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception("Não foi possível criar arquivo ZIP: $zipFile");
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $filePath = $file->getRealPath();
                $relativePath = substr( $filePath,strlen($backupDir) + 1 );
                $zip->addFile($filePath, $relativePath);
            }

            $zip->close();
            $deleteDirectory = function ($directory) use (&$deleteDirectory) {
                if (!is_dir($directory)) {
                    return;
                }

                foreach (scandir($directory) as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }

                    $path = $directory . DIRECTORY_SEPARATOR . $item;

                    if (is_dir($path)) {
                        $deleteDirectory($path);
                    } else {
                        unlink($path);
                    }
                }
                rmdir($directory);
            };
            $deleteDirectory($backupDir);

            return [
                'success' => true,
                'timestamp' => $timestamp,
                'backup_file' => $zipFile,
                'message' => "Backup criado com sucesso: $zipFile"
            ];

        } catch (Exception $e) {

            if (is_dir($backupDir)) {
                $deleteDirectory = function ($directory) use (&$deleteDirectory) {
                    if (!is_dir($directory)) {
                        return;
                    }
                    foreach (scandir($directory) as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }
                        $path = $directory . DIRECTORY_SEPARATOR . $item;
                        if (is_dir($path)) {
                            $deleteDirectory($path);
                        } else {
                            @unlink($path);
                        }
                    }
                    @rmdir($directory);
                };
                $deleteDirectory($backupDir);
            }
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Listar backups disponíveis
     *
     * Os backups são armazenados como:
     * BACKUP_DIR/YYYY-MM-DD_HHMMSS.zip
     *
     * @return array
     */
    public static function listBackups(): array
    {
        if (!defined('BACKUP_DIR') || !is_dir(BACKUP_DIR)) {
            return [];
        }
        $backups = [];
        $files = glob(BACKUP_DIR . '/????-??-??_??????.zip');

        if ($files) {
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $filename = basename($file);
                $timestamp = pathinfo($filename, PATHINFO_FILENAME);
                $size = filesize($file);
                $backups[] = [
                    'timestamp' => $timestamp,
                    'files' => 1,
                    'size_mb' => round($size / (1024 * 1024), 2),
                    'path' => $file
                ];
            }
        }
        usort($backups, function ($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return $backups;
    }


    /**
     * Deletar um backup específico
     *
     * @param string $timestamp Data/hora do backup
     * @return bool
     */
    public static function deleteBackup(string $timestamp): bool
    {
        if (!defined('BACKUP_DIR')) {
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $timestamp)) {
            return false;
        }
        $backupFile = BACKUP_DIR . '/' . $timestamp . '.zip';
        if (!is_file($backupFile)) {
            return false;
        }
        return @unlink($backupFile);
    }
}
