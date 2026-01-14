<?php
/**
 * ============================================================
 * ENVIRONMENT CONFIGURATION - BOOTSTRAP CENTRAL
 * ============================================================
 * Arquivo crítico de inicialização do projeto
 * 
 * Protegido contra:
 * ✓ Redefinição múltipla de constantes
 * ✓ Session start duplicado
 * ✓ Carregamento múltiplo
 * ============================================================
 */

// Proteção contra carregamento múltiplo
if (defined('CONFIG_LOADED')) {
    return;
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
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// ============================================================
// DEFINIR CONSTANTES (COM PROTEÇÃO)
// ============================================================

if (!defined('APP_ENV')) {
    define('APP_ENV', $_ENV['APP_ENV'] ?? 'development');
}

if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', ($_ENV['DEBUG_MODE'] ?? 'false') === 'true');
}

if (!defined('TIMEZONE')) {
    define('TIMEZONE', $_ENV['TIMEZONE'] ?? 'America/Sao_Paulo');
}

// Caminhos absolutos
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}

if (!defined('SRC_PATH')) {
    define('SRC_PATH', BASE_PATH . '/src');
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH . '/public');
}

if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', BASE_PATH . '/storage');
}

// Diretórios de armazenamento
if (!defined('DATA_DIR')) {
    define('DATA_DIR', $_ENV['DATA_DIR'] ?? STORAGE_PATH . '/json');
}

if (!defined('LOG_DIR')) {
    define('LOG_DIR', $_ENV['LOG_DIR'] ?? STORAGE_PATH . '/logs');
}

if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', $_ENV['BACKUP_DIR'] ?? STORAGE_PATH . '/backups');
}

// Arquivos principais
if (!defined('FILE_PILOTS')) {
    define('FILE_PILOTS', DATA_DIR . '/pilots.json');
}

if (!defined('FILE_MATCHES')) {
    define('FILE_MATCHES', DATA_DIR . '/matches.json');
}

if (!defined('FILE_SCHEDULES')) {
    define('FILE_SCHEDULES', DATA_DIR . '/schedules.json');
}

if (!defined('FILE_AUDIT')) {
    define('FILE_AUDIT', DATA_DIR . '/auditSchedules.json');
}

if (!defined('FILE_SESSIONS')) {
    define('FILE_SESSIONS', DATA_DIR . '/sessions.json');
}

if (!defined('FILE_LOG_BOT')) {
    define('FILE_LOG_BOT', LOG_DIR . '/botMain.log');
}

if (!defined('FILE_LOG_SECURITY')) {
    define('FILE_LOG_SECURITY', LOG_DIR . '/admin-security.log');
}

if (!defined('FILE_LOG_ERRORS')) {
    define('FILE_LOG_ERRORS', LOG_DIR . '/errors.log');
}

// ============================================================
// CONFIGURAR TIMEZONE
// ============================================================
date_default_timezone_set(TIMEZONE);

// ============================================================
// CRIAR DIRETÓRIOS NECESSÁRIOS
// ============================================================
$dirs = [DATA_DIR, LOG_DIR, BACKUP_DIR];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            http_response_code(500);
            die("❌ ERRO: Não foi possível criar diretório: $dir\n");
        }
        @chmod($dir, 0755);
    }
    
    // Verificar permissões de escrita
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
    }
}

// ============================================================
// CONFIGURAR ERROR HANDLING
// ============================================================
if (!DEBUG_MODE) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Handler customizado para erros
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $error = "[ERROR] $errstr in $errfile:$errline";
    if (DEBUG_MODE) {
        error_log($error);
    }
    return true;
});

// ============================================================
// INICIALIZAR SESSION (COM PROTEÇÃO)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
    // Regenerar session ID a cada login por segurança
    if (!isset($_SESSION['_session_created'])) {
        session_regenerate_id(true);
        $_SESSION['_session_created'] = time();
    }
}

// ============================================================
// AUTOLOAD DE CLASSES
// ============================================================
spl_autoload_register(function($class) {
    $namespace = 'TGC\\';
    
    if (strpos($class, $namespace) !== 0) {
        return;
    }
    
    $class_path = str_replace('\\', '/', substr($class, strlen($namespace)));
    $file = SRC_PATH . '/' . $class_path . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// ============================================================
// STATUS DE INICIALIZAÇÃO
// ============================================================
define('CONFIG_LOADED', true);
define('BOOT_TIME', microtime(true));

?>
