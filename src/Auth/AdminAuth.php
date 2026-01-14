<?php
/**
 * ============================================================
 * ADMIN AUTHENTICATION CLASS
 * ============================================================
 * Autenticação segura para painel administrativo
 * Requisitos: config/environment.php carregado
 */

// Garantir que environment está carregado
require_once __DIR__ . '/../../config/environment.php';

class AdminAuth {
    
    const SESSION_KEY = 'admin_session_key';
    const SESSION_TIMEOUT = 3600; // 1 hora
    const SESSION_IP_CHECK = true;
    
    /**
     * Fazer login com senha
     * 
     * @param string $password Senha do administrador
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function login($password) {
        // Verificar se password foi fornecida
        if (empty($password)) {
            throw new InvalidArgumentException("Senha não pode estar vazia");
        }
        
        // Comparar com variável de ambiente
        $adminPass = $_ENV['ADMIN_PASSWORD'] ?? null;
        
        if (empty($adminPass)) {
            throw new InvalidArgumentException("Senha de admin não configurada no .env");
        }
        
        // Usar password_verify ou comparação direta (fallback)
        $isValidPassword = ($password === $adminPass);
        
        if (!$isValidPassword) {
            self::logFailedAttempt($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            throw new InvalidArgumentException("❌ Senha do administrador incorreta");
        }
        
        // Criar sessão
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'timestamp' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        // Log de sucesso
        self::logSuccessfulLogin($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        return true;
    }
    
    /**
     * Verificar se sessão admin é válida
     * 
     * @return bool
     */
    public static function check() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Verificar se chave de autenticação existe
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        
        $session = $_SESSION[self::SESSION_KEY];
        
        // Validar timeout
        if (time() - $session['timestamp'] > self::SESSION_TIMEOUT) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }
        
        // Validar IP (segurança adicional)
        if (self::SESSION_IP_CHECK) {
            $currentIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if ($session['ip'] !== $currentIp) {
                self::logSecurityWarning("IP Mismatch", [
                    'session_ip' => $session['ip'],
                    'current_ip' => $currentIp
                ]);
                unset($_SESSION[self::SESSION_KEY]);
                return false;
            }
        }
        
        // Renovar timestamp (manter sessão ativa)
        $_SESSION[self::SESSION_KEY]['timestamp'] = time();
        
        return true;
    }
    
    /**
     * Fazer logout
     */
    public static function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        unset($_SESSION[self::SESSION_KEY]);
        session_destroy();
        
        self::logLogout($ip);
    }
    
    /**
     * Registrar tentativa de login falha
     */
    private static function logFailedAttempt($ip) {
        $logEntry = date('[Y-m-d H:i:s]') . " [LOGIN_FAILED] IP: $ip\n";
        @file_put_contents(FILE_LOG_SECURITY, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Registrar login bem-sucedido
     */
    private static function logSuccessfulLogin($ip) {
        $logEntry = date('[Y-m-d H:i:s]') . " [LOGIN_SUCCESS] IP: $ip\n";
        @file_put_contents(FILE_LOG_SECURITY, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Registrar logout
     */
    private static function logLogout($ip) {
        $logEntry = date('[Y-m-d H:i:s]') . " [LOGOUT] IP: $ip\n";
        @file_put_contents(FILE_LOG_SECURITY, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Registrar aviso de segurança
     */
    private static function logSecurityWarning($event, $details = []) {
        $detailsStr = json_encode($details);
        $logEntry = date('[Y-m-d H:i:s]') . " [SECURITY_WARNING] $event: $detailsStr\n";
        @file_put_contents(FILE_LOG_SECURITY, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Obter informações da sessão (debug)
     */
    public static function getSessionInfo() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return null;
        }
        
        return $_SESSION[self::SESSION_KEY];
    }
}

?>
