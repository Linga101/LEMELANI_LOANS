<?php
/**
 * Lemelani Loans - Main Configuration
 */

// Directory paths
define('ROOT_PATH', dirname(__DIR__));

// Security/session settings
define('HASH_ALGO', PASSWORD_DEFAULT);
define('SESSION_LIFETIME', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900); // 15 minutes

// App environment
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'development'));
define('APP_ENV', in_array($appEnv, ['production', 'staging', 'development'], true) ? $appEnv : 'development');

// Start session if not already started (harden cookie flags)
if (session_status() === PHP_SESSION_NONE) {
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Site settings
define('SITE_NAME', 'Lemelani Loans');
define('SITE_TAGLINE', 'Borrow Smart, Live Better');

// Base URL/path detection (fixed to app root, not current script directory)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$detectedBasePath = '';
if (preg_match('#^/[^/]+#', $scriptName, $matches)) {
    $detectedBasePath = $matches[0];
}
$envBasePath = getenv('APP_BASE_PATH');
$basePath = $envBasePath !== false ? trim((string)$envBasePath) : $detectedBasePath;
if ($basePath === '' || $basePath === '/') {
    $basePath = '';
} elseif ($basePath[0] !== '/') {
    $basePath = '/' . $basePath;
}
define('APP_BASE_PATH', rtrim($basePath, '/'));
define('SITE_URL', $protocol . '://' . $host . APP_BASE_PATH);

// Example: APP_BASE_PATH=/LEMELANI_LOANS -> SITE_URL=http://localhost/LEMELANI_LOANS

define('SITE_EMAIL', 'info@lemelaniloans.com');
define('SITE_PHONE', '+265 999 123 456');

define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// Private document storage (non-public web root)
define('PRIVATE_STORAGE_PATH', ROOT_PATH . '/storage/private/');
define('ID_UPLOAD_DIR', PRIVATE_STORAGE_PATH . 'ids/');
define('SELFIE_UPLOAD_DIR', PRIVATE_STORAGE_PATH . 'selfies/');

// Create storage directories if they don't exist
foreach ([UPLOAD_PATH, ID_UPLOAD_DIR, SELFIE_UPLOAD_DIR] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Loan settings (these can be overridden by database settings)
define('MIN_LOAN_AMOUNT', 10000);
define('MAX_LOAN_AMOUNT', 300000);
define('DEFAULT_INTEREST_RATE', 5.00);
define('DEFAULT_LOAN_TERM', 30);
define('DEFAULT_LOAN_TERM_MONTHS', 3);

// Timezone
date_default_timezone_set('Africa/Blantyre');

// Error reporting (disable in production)
if (APP_ENV === 'production') {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
ini_set('log_errors', '1');

// Include database configuration
require_once ROOT_PATH . '/config/database.php';

// Load Credit Scoring Engine class and provide a helper to get an instance
if (file_exists(ROOT_PATH . '/classes/CreditScoringEngine.php')) {
    require_once ROOT_PATH . '/classes/CreditScoringEngine.php';
}

function get_scoring_engine() {
    $database = new Database();
    $db = $database->getConnection();
    return new CreditScoringEngine($db);
}

// Helper functions
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    return $data;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    // accept absolute URLs
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        header("Location: $url");
    } else {
        header("Location: " . site_url($url));
    }
    exit();
}

/**
 * Helper to build links to the application.
 * Usage: <a href="<?php echo site_url('loans.php'); ?>">My Loans</a>
 */
function site_url($path = '') {
    $path = ltrim((string)$path, '/');
    $base = rtrim(SITE_URL, '/');
    return $path === '' ? $base . '/' : $base . '/' . $path;
}

function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

function get_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_user_role() {
    return $_SESSION['user_role'] ?? null;
}

function create_remember_tokens_table(PDO $db) {
    static $tableReady = false;
    if ($tableReady) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS remember_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                selector VARCHAR(24) NOT NULL UNIQUE,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_expires (user_id, expires_at),
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $tableReady = true;
}

function issue_remember_me_token($userId, $days = 30) {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        return false;
    }
    create_remember_tokens_table($db);

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $hash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

    $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
                          VALUES (:user_id, :selector, :token_hash, :expires_at)");
    $stmt->execute([
        ':user_id' => (int)$userId,
        ':selector' => $selector,
        ':token_hash' => $hash,
        ':expires_at' => $expiresAt
    ]);

    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('remember_token', $selector . ':' . $validator, [
        'expires' => time() + ($days * 86400),
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    return true;
}

function clear_remember_me_token() {
    if (!empty($_COOKIE['remember_token'])) {
        [$selector] = explode(':', $_COOKIE['remember_token'], 2);
        if (!empty($selector)) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                if ($db) {
                    create_remember_tokens_table($db);
                    $stmt = $db->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
                    $stmt->execute([':selector' => $selector]);
                }
            } catch (Exception $e) {
                error_log('Remember-token cleanup error: ' . $e->getMessage());
            }
        }
    }
    setcookie('remember_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function attempt_remember_me_login() {
    if (is_logged_in() || empty($_COOKIE['remember_token'])) {
        return false;
    }

    $parts = explode(':', $_COOKIE['remember_token'], 2);
    if (count($parts) !== 2) {
        clear_remember_me_token();
        return false;
    }

    [$selector, $validator] = $parts;
    if ($selector === '' || $validator === '') {
        clear_remember_me_token();
        return false;
    }

    try {
        $database = new Database();
        $db = $database->getConnection();
        if (!$db) {
            return false;
        }
        create_remember_tokens_table($db);

        $query = "SELECT rt.id, rt.user_id, rt.token_hash, rt.expires_at,
                         u.full_name, u.email, u.role, u.account_status, u.verification_status
                  FROM remember_tokens rt
                  JOIN users u ON rt.user_id = u.user_id
                  WHERE rt.selector = :selector
                  LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute([':selector' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || strtotime($row['expires_at']) < time()) {
            clear_remember_me_token();
            return false;
        }

        if (!hash_equals($row['token_hash'], hash('sha256', $validator))) {
            clear_remember_me_token();
            return false;
        }

        if ($row['account_status'] !== 'active') {
            clear_remember_me_token();
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['user_id'];
        $_SESSION['user_role'] = $row['role'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['email'] = $row['email'];
        $_SESSION['verification_status'] = $row['verification_status'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        $db->prepare("DELETE FROM remember_tokens WHERE id = :id")->execute([':id' => $row['id']]);
        issue_remember_me_token((int)$row['user_id']);
        return true;
    } catch (Exception $e) {
        error_log('Remember-me login error: ' . $e->getMessage());
        clear_remember_me_token();
        return false;
    }
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf_or_fail() {
    $submitted = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$submitted || !$sessionToken || !hash_equals($sessionToken, $submitted)) {
        http_response_code(403);
        exit('Invalid request token. Please refresh and try again.');
    }
}

function require_login() {
    if (!is_logged_in()) {
        attempt_remember_me_login();
    }

    if (is_logged_in()) {
        $now = time();
        $loginTime = $_SESSION['login_time'] ?? $now;
        $lastActivity = $_SESSION['last_activity'] ?? $now;

        if (($now - $loginTime) > SESSION_LIFETIME || ($now - $lastActivity) > LOGIN_TIMEOUT) {
            $_SESSION = [];
            clear_remember_me_token();
            session_destroy();
            redirect('/login.php?expired=1');
        }

        $_SESSION['last_activity'] = $now;
        return;
    }

    redirect('/login.php');
}

function require_role($allowed_roles) {
    require_login();
    $user_role = get_user_role();
    if (!in_array($user_role, (array)$allowed_roles)) {
        redirect('/dashboard.php');
    }
}

function format_currency($amount) {
    // ensure a numeric value is always provided to number_format
    $amount = $amount !== null ? $amount : 0;
    return 'MK ' . number_format($amount, 2);
}

function format_date($date) {
    return date('d M Y', strtotime($date));
}

function calculate_loan_total($amount, $interest_rate, $term_days = 30) {
    $interest = ($amount * $interest_rate / 100);
    return $amount + $interest;
}

function generate_transaction_reference() {
    return 'LML-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// Log audit trail
function log_audit($user_id, $action, $entity_type = null, $entity_id = null, $old_values = null, $new_values = null) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) 
                  VALUES (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':user_id' => $user_id,
            ':action' => $action,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':old_values' => $old_values ? json_encode($old_values) : null,
            ':new_values' => $new_values ? json_encode($new_values) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch(Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}
?>
