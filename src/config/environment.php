<?php
/**
 * ============================================================
 * ENVIRONMENT CONFIGURATION - BOOTSTRAP CENTRAL
 * ============================================================
 * Arquivo crítico de inicialização do projeto TGC
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

$envFile = dirname(__DIR__, 2) . '/.env';
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

const TIMEZONE = 'America/Sao_Paulo';
define("SEASON_YEAR", date('Y'));

if (!defined('APP_ENV')) {
    define('LOG_LEVEL', 'INFO');
    define('DEBUG_MODE', 'false');
}

// Caminhos base absolutos
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', __DIR__);
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

if (!defined('UTILS_PATH')) {
    define('UTILS_PATH', SRC_PATH . '/utils');
}

if (!defined('GENERAL_DATA_DIR')) {
    define('GENERAL_DATA_DIR', STORAGE_PATH . '/generalData');
}

if (!defined('BOOKINGS_DATA_DIR')) {
    define('BOOKINGS_DATA_DIR', STORAGE_PATH . '/bookingsData/' . SEASON_YEAR);
}

if (!defined('TOURNAMENTS_DATA_DIR')) {
    define('TOURNAMENTS_DATA_DIR', STORAGE_PATH . '/tournamentsData/' . SEASON_YEAR);
}

if (!defined('LOG_DIR')) {
    define('LOG_DIR', STORAGE_PATH . '/logs');
}

if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', $_ENV['BACKUP_DIR'] ?? STORAGE_PATH . '/backups');
}

if (!defined('FILE_PILOTS')) {
    define('FILE_PILOTS', GENERAL_DATA_DIR . '/pilots.json');
}

if (!defined('FILE_COUNTRIES')) {
    define('FILE_COUNTRIES', GENERAL_DATA_DIR . '/allCountries.json');
}

if (!defined('FILE_TRACKS')) {
    define('FILE_TRACKS', GENERAL_DATA_DIR . '/allTracks.json');
}

if (!defined('FILE_TOURNAMENTS')) {
    define('FILE_TOURNAMENTS', GENERAL_DATA_DIR . '/tournaments.json');
}

if (!defined('FILE_TOURNAMENTS_PHASES')) {
    define('FILE_TOURNAMENTS_PHASES', GENERAL_DATA_DIR . '/tournamentsPhases.json');
}

if (!defined('FILE_MATCHES')) {
    define('FILE_MATCHES', BOOKINGS_DATA_DIR . '/matches.json');
}

if (!defined('FILE_SCHEDULES')) {
    define('FILE_SCHEDULES', BOOKINGS_DATA_DIR . '/schedules.json');
}

if (!defined('FILE_AUDIT')) {
    define('FILE_AUDIT', BOOKINGS_DATA_DIR . '/auditSchedules.json');
}

if (!defined('FILE_SESSIONS')) {
    define('FILE_SESSIONS', BOOKINGS_DATA_DIR . '/sessions.json');
}

if (!defined('FILE_LOG_BOT')) {
    define('FILE_LOG_BOT', LOG_DIR . '/botMain.log');
}

// ============================================================
// CONFIGURAR TIMEZONE
// ============================================================
date_default_timezone_set(TIMEZONE);

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
    // 1. Suporte a namespace TGC\
    $namespace = 'TGC\\';
    if (strpos($class, $namespace) === 0) {
        $class_path = str_replace('\\', '/', substr($class, strlen($namespace)));
        $file = SRC_PATH . '/' . $class_path . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    // 2. Fallback para classes utilitárias em src/utils/
    $utilFile = UTILS_PATH . '/' . $class . '.php';
    if (file_exists($utilFile)) {
        require_once $utilFile;
        return;
    }
});

// ============================================================
// STATUS DE INICIALIZAÇÃO
// ============================================================
const CONFIG_LOADED = true;
define("BOOT_TIME", microtime(true));
?>
