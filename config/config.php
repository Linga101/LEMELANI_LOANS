<?php
/**
 * Lemelani Loans - Main Configuration
 */

// Directory paths
define('ROOT_PATH', dirname(__DIR__));

/**
 * Load local environment variables from ROOT_PATH/.env for development use.
 * Existing OS/server environment variables always take precedence.
 */
function load_dotenv_file($filePath) {
    if (!is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        if ($key === '' || preg_match('/\s/', $key)) {
            continue;
        }

        // Strip optional matching quotes around the value.
        $firstChar = $value !== '' ? $value[0] : '';
        $lastChar = $value !== '' ? substr($value, -1) : '';
        if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

load_dotenv_file(ROOT_PATH . '/.env');

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

// Outbound email settings (override with environment variables in production)
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'lemelani.loans.noreply@gmail.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: SITE_NAME);
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 465));
define('SMTP_ENCRYPTION', strtolower((string)(getenv('SMTP_ENCRYPTION') ?: 'ssl'))); // ssl|tls|none
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: 'lemelani.loans.noreply@gmail.com');
define('SMTP_PASSWORD', str_replace(' ', '', (string)(getenv('SMTP_PASSWORD') ?: getenv('GMAIL_APP_PASSWORD') ?: '')));
define('PASSWORD_RESET_TTL_MINUTES', 60);
define('PASSWORD_RESET_REQUEST_COOLDOWN', 60); // seconds

define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// Private document storage (non-public web root)
define('PRIVATE_STORAGE_PATH', ROOT_PATH . '/storage/private/');
define('ID_UPLOAD_DIR', PRIVATE_STORAGE_PATH . 'ids/');
define('SELFIE_UPLOAD_DIR', PRIVATE_STORAGE_PATH . 'selfies/');
define('FILE_STORAGE_BACKEND', strtolower((string)(getenv('FILE_STORAGE_BACKEND') ?: 'local')));
define('OBJECT_STORAGE_PATH', rtrim((string)(getenv('OBJECT_STORAGE_PATH') ?: (ROOT_PATH . '/storage/object/')), '/\\') . DIRECTORY_SEPARATOR);
define('OBJECT_STORAGE_PUBLIC_BASE_URL', rtrim((string)(getenv('OBJECT_STORAGE_PUBLIC_BASE_URL') ?: ''), '/'));

// Create storage directories if they don't exist
foreach ([UPLOAD_PATH, ID_UPLOAD_DIR, SELFIE_UPLOAD_DIR, OBJECT_STORAGE_PATH] as $dir) {
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

set_exception_handler(function (Throwable $e) {
    error_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (APP_ENV === 'production') {
        echo 'An unexpected error occurred. Please try again later.';
    } else {
        echo 'Application error. Check server logs for details.';
    }
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }
    error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (!headers_sent()) {
        http_response_code(500);
    }
});

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

/**
 * Build cache-busted asset URLs.
 * Usage: <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
 */
function asset_url($path) {
    $path = ltrim((string)$path, '/');
    $url = site_url($path);

    $fullPath = ROOT_PATH . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($fullPath)) {
        $ver = filemtime($fullPath);
        if ($ver !== false) {
            $url .= '?v=' . rawurlencode((string)$ver);
        }
    }

    return $url;
}

/**
 * Feature flag helper.
 * Reads flags from environment variables using FF_<FLAG_NAME>=1|true|yes|on.
 */
function feature_enabled($flagName, $default = false) {
    $normalized = strtolower((string)$flagName);
    $key = 'FF_' . strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', $normalized));
    $raw = getenv($key);
    if ($raw === false) {
        // Global cutover overrides are only applied when the specific flag
        // is not explicitly set, so per-page flags can still override.
        $globalAll = getenv('FF_NEXTJS_ALL');
        if ($globalAll !== false) {
            $globalAllValue = strtolower(trim((string)$globalAll));
            if (in_array($globalAllValue, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        if (str_starts_with($normalized, 'nextjs_admin_')) {
            $globalAdmin = getenv('FF_NEXTJS_ADMIN_ALL');
            if ($globalAdmin !== false) {
                $globalAdminValue = strtolower(trim((string)$globalAdmin));
                if (in_array($globalAdminValue, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
            }
        } elseif (str_starts_with($normalized, 'nextjs_')) {
            $globalCustomer = getenv('FF_NEXTJS_CUSTOMER_ALL');
            if ($globalCustomer !== false) {
                $globalCustomerValue = strtolower(trim((string)$globalCustomer));
                if (in_array($globalCustomerValue, ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
            }
        }

        return (bool)$default;
    }

    $value = strtolower(trim((string)$raw));
    if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return (bool)$default;
}

/**
 * Build Next.js URL for hybrid rollout.
 * Uses NEXTJS_BASE_URL (example: http://localhost:3000).
 */
function nextjs_url($path = '') {
    $base = rtrim((string)(getenv('NEXTJS_BASE_URL') ?: ''), '/');
    if ($base === '') {
        return '';
    }
    $path = ltrim((string)$path, '/');
    return $path === '' ? $base : ($base . '/' . $path);
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

function create_password_reset_tokens_table(PDO $db) {
    static $tableReady = false;
    if ($tableReady) {
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                selector VARCHAR(24) NOT NULL UNIQUE,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                requested_ip VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_selector_expires (selector, expires_at),
                FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $tableReady = true;
}

function create_password_reset_token($userId, $ttlMinutes = PASSWORD_RESET_TTL_MINUTES) {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        return null;
    }
    create_password_reset_tokens_table($db);

    // Invalidate old active tokens for this user before creating a new one.
    $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = :user_id AND used_at IS NULL")
        ->execute([':user_id' => (int)$userId]);

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);
    $expiresAt = date('Y-m-d H:i:s', time() + ((int)$ttlMinutes * 60));

    $stmt = $db->prepare("INSERT INTO password_reset_tokens (user_id, selector, token_hash, expires_at, requested_ip)
                          VALUES (:user_id, :selector, :token_hash, :expires_at, :requested_ip)");
    $stmt->execute([
        ':user_id' => (int)$userId,
        ':selector' => $selector,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
        ':requested_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    return [
        'selector' => $selector,
        'validator' => $validator,
        'expires_at' => $expiresAt,
    ];
}

function can_request_password_reset($userId, $cooldownSeconds = PASSWORD_RESET_REQUEST_COOLDOWN) {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        return false;
    }
    create_password_reset_tokens_table($db);

    $stmt = $db->prepare("SELECT created_at
                          FROM password_reset_tokens
                          WHERE user_id = :user_id
                          ORDER BY id DESC
                          LIMIT 1");
    $stmt->execute([':user_id' => (int)$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return true;
    }
    return (time() - strtotime($row['created_at'])) >= (int)$cooldownSeconds;
}

function validate_password_reset_token($selector, $validator) {
    if (!preg_match('/^[a-f0-9]{24}$/', (string)$selector) || !preg_match('/^[a-f0-9]{64}$/', (string)$validator)) {
        return null;
    }

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        return null;
    }
    create_password_reset_tokens_table($db);

    $stmt = $db->prepare("SELECT id, user_id, token_hash, expires_at, used_at
                          FROM password_reset_tokens
                          WHERE selector = :selector
                          LIMIT 1");
    $stmt->execute([':selector' => $selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (!empty($row['used_at'])) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }
    if (!hash_equals($row['token_hash'], hash('sha256', $validator))) {
        return null;
    }
    return $row;
}

function consume_password_reset_token($tokenId) {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        return false;
    }
    create_password_reset_tokens_table($db);
    $stmt = $db->prepare("UPDATE password_reset_tokens
                          SET used_at = NOW()
                          WHERE id = :id AND used_at IS NULL");
    $stmt->execute([':id' => (int)$tokenId]);
    return $stmt->rowCount() === 1;
}

function revoke_user_password_reset_tokens($userId) {
    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        return;
    }
    create_password_reset_tokens_table($db);
    $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = :user_id")->execute([':user_id' => (int)$userId]);
}

function base64url_encode($value) {
    return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
}

function smtp_read_response($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtp_expect($socket, $allowedCodes) {
    $response = smtp_read_response($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, (array)$allowedCodes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }
    return $response;
}

function send_email_smtp($toEmail, $toName, $subject, $textBody, $htmlBody = null) {
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        throw new RuntimeException('SMTP credentials are not configured.');
    }

    $transport = SMTP_ENCRYPTION;
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $timeout = 20;
    $remote = ($transport === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

    $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtp_expect($socket, 220);

        fwrite($socket, "EHLO localhost\r\n");
        smtp_expect($socket, 250);

        if ($transport === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            smtp_expect($socket, 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Failed to enable STARTTLS.');
            }
            fwrite($socket, "EHLO localhost\r\n");
            smtp_expect($socket, 250);
        }

        fwrite($socket, "AUTH LOGIN\r\n");
        smtp_expect($socket, 334);
        fwrite($socket, base64_encode(SMTP_USERNAME) . "\r\n");
        smtp_expect($socket, 334);
        fwrite($socket, base64_encode(SMTP_PASSWORD) . "\r\n");
        smtp_expect($socket, 235);

        fwrite($socket, "MAIL FROM:<" . MAIL_FROM_ADDRESS . ">\r\n");
        smtp_expect($socket, 250);
        fwrite($socket, "RCPT TO:<" . $toEmail . ">\r\n");
        smtp_expect($socket, [250, 251]);
        fwrite($socket, "DATA\r\n");
        smtp_expect($socket, 354);

        $boundary = 'b1_' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>';
        $headers[] = 'To: ' . trim(($toName !== '' ? $toName . ' ' : '') . '<' . $toEmail . '>');
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'lemelaniloans.local') . '>';

        if ($htmlBody !== null && $htmlBody !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $data = implode("\r\n", $headers) . "\r\n\r\n";
            $data .= "--{$boundary}\r\n";
            $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $data .= $textBody . "\r\n\r\n";
            $data .= "--{$boundary}\r\n";
            $data .= "Content-Type: text/html; charset=UTF-8\r\n";
            $data .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $data .= $htmlBody . "\r\n\r\n";
            $data .= "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $textBody . "\r\n";
        }

        // Dot-stuffing per RFC 5321.
        $data = preg_replace('/(?m)^\./', '..', $data);
        fwrite($socket, $data . "\r\n.\r\n");
        smtp_expect($socket, 250);

        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }

    return true;
}

function send_password_reset_email($toEmail, $toName, $resetUrl, $expiresAt) {
    $subject = SITE_NAME . ' password reset request';
    $displayName = trim((string)$toName) !== '' ? trim((string)$toName) : 'there';
    $expiryText = date('d M Y H:i', strtotime($expiresAt));

    $textBody = "Hello {$displayName},\n\n"
        . "We received a request to reset your " . SITE_NAME . " account password.\n\n"
        . "Use this link to reset your password:\n{$resetUrl}\n\n"
        . "This link expires on {$expiryText}.\n"
        . "If you did not request this, you can ignore this email.\n\n"
        . "Security tip: never share your password reset link.\n\n"
        . SITE_NAME;

    $safeName = h($displayName);
    $safeUrl = h($resetUrl);
    $safeExpiry = h($expiryText);
    $htmlBody = "<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;background:#f6f8fb;padding:24px;\">"
        . "<table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\"><tr><td align=\"center\">"
        . "<table role=\"presentation\" width=\"600\" cellspacing=\"0\" cellpadding=\"0\" style=\"background:#ffffff;border-radius:8px;padding:24px;\">"
        . "<tr><td>"
        . "<h2 style=\"margin:0 0 16px;color:#121926;\">Reset your password</h2>"
        . "<p style=\"margin:0 0 12px;color:#334155;\">Hello {$safeName},</p>"
        . "<p style=\"margin:0 0 16px;color:#334155;\">We received a request to reset your " . h(SITE_NAME) . " account password.</p>"
        . "<p style=\"margin:0 0 24px;\"><a href=\"{$safeUrl}\" style=\"background:#16a34a;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:6px;display:inline-block;\">Reset password</a></p>"
        . "<p style=\"margin:0 0 12px;color:#334155;\">Or copy and paste this URL into your browser:</p>"
        . "<p style=\"margin:0 0 16px;word-break:break-all;\"><a href=\"{$safeUrl}\">{$safeUrl}</a></p>"
        . "<p style=\"margin:0 0 8px;color:#334155;\">This link expires on {$safeExpiry}.</p>"
        . "<p style=\"margin:0;color:#64748b;font-size:13px;\">If you did not request this, you can ignore this email.</p>"
        . "</td></tr></table></td></tr></table></body></html>";

    return send_email_smtp($toEmail, (string)$toName, $subject, $textBody, $htmlBody);
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

function generate_audit_event_id() {
    return 'evt_' . bin2hex(random_bytes(10));
}

function current_request_id() {
    static $requestId = null;
    if ($requestId !== null) {
        return $requestId;
    }

    $candidates = [
        (string)($_SERVER['APP_REQUEST_ID'] ?? ''),
        (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''),
    ];
    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
            $requestId = $candidate;
            return $requestId;
        }
    }

    $requestId = bin2hex(random_bytes(8));
    $_SERVER['APP_REQUEST_ID'] = $requestId;
    return $requestId;
}

function storage_normalize_relative_path($relativePath) {
    $clean = str_replace(['\\', '..'], ['/', ''], (string)$relativePath);
    return ltrim($clean, '/');
}

function storage_is_truthy($value, $default = false) {
    if ($value === null || $value === false || trim((string)$value) === '') {
        return (bool)$default;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function storage_object_provider() {
    return strtolower(trim((string)(getenv('OBJECT_STORAGE_PROVIDER') ?: 'local_mirror')));
}

function storage_object_key($relativePath) {
    $relativePath = storage_normalize_relative_path($relativePath);
    $prefix = trim((string)(getenv('OBJECT_STORAGE_S3_KEY_PREFIX') ?: ''), '/');
    if ($prefix === '') {
        return $relativePath;
    }
    return $prefix . '/' . $relativePath;
}

function storage_object_local_mirror_enabled() {
    return storage_is_truthy(getenv('OBJECT_STORAGE_WRITE_LOCAL_MIRROR'), true);
}

function storage_object_require_remote() {
    return storage_is_truthy(getenv('OBJECT_STORAGE_REQUIRE_REMOTE'), false);
}

function storage_object_s3_client() {
    static $initialized = false;
    static $client = null;

    if ($initialized) {
        return $client;
    }
    $initialized = true;

    if (storage_object_provider() !== 's3') {
        return null;
    }
    if (!class_exists('\Aws\S3\S3Client')) {
        error_log('S3 storage provider selected but Aws\\S3\\S3Client is unavailable. Install aws/aws-sdk-php via Composer.');
        return null;
    }

    $bucket = trim((string)(getenv('OBJECT_STORAGE_S3_BUCKET') ?: ''));
    if ($bucket === '') {
        error_log('S3 storage provider selected but OBJECT_STORAGE_S3_BUCKET is not set.');
        return null;
    }

    $region = trim((string)(getenv('OBJECT_STORAGE_S3_REGION') ?: 'us-east-1'));
    $endpoint = trim((string)(getenv('OBJECT_STORAGE_S3_ENDPOINT') ?: ''));
    $key = trim((string)(getenv('OBJECT_STORAGE_S3_KEY') ?: ''));
    $secret = trim((string)(getenv('OBJECT_STORAGE_S3_SECRET') ?: ''));
    $pathStyle = storage_is_truthy(getenv('OBJECT_STORAGE_S3_PATH_STYLE'), true);

    $config = [
        'version' => 'latest',
        'region' => $region,
    ];
    if ($endpoint !== '') {
        $config['endpoint'] = $endpoint;
    }
    $config['use_path_style_endpoint'] = $pathStyle;

    if ($key !== '' && $secret !== '') {
        $config['credentials'] = [
            'key' => $key,
            'secret' => $secret,
        ];
    }

    try {
        $client = new \Aws\S3\S3Client($config);
    } catch (Throwable $e) {
        error_log('S3 client initialization failed: ' . $e->getMessage());
        $client = null;
    }

    return $client;
}

function storage_object_s3_bucket() {
    return trim((string)(getenv('OBJECT_STORAGE_S3_BUCKET') ?: ''));
}

function storage_write_local_file($relativePath, $bytes) {
    $relativePath = storage_normalize_relative_path($relativePath);
    if ($relativePath === '') {
        return false;
    }
    $destination = rtrim(OBJECT_STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $destinationDir = dirname($destination);
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
        return false;
    }
    return file_put_contents($destination, $bytes) !== false;
}

function storage_object_upload_bytes($relativePath, $bytes, $contentType = 'application/octet-stream') {
    if (storage_object_provider() !== 's3') {
        return false;
    }

    $client = storage_object_s3_client();
    $bucket = storage_object_s3_bucket();
    if (!$client || $bucket === '') {
        return false;
    }

    try {
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => storage_object_key($relativePath),
            'Body' => $bytes,
            'ContentType' => $contentType,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('S3 upload failed for ' . $relativePath . ': ' . $e->getMessage());
        return false;
    }
}

function storage_object_download_to_temp($relativePath) {
    if (storage_object_provider() !== 's3') {
        return null;
    }

    $client = storage_object_s3_client();
    $bucket = storage_object_s3_bucket();
    if (!$client || $bucket === '') {
        return null;
    }

    try {
        $result = $client->getObject([
            'Bucket' => $bucket,
            'Key' => storage_object_key($relativePath),
        ]);
        $body = (string)$result['Body'];
        $tmp = tempnam(sys_get_temp_dir(), 'lml_obj_');
        if ($tmp === false) {
            return null;
        }
        if (file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            return null;
        }
        return $tmp;
    } catch (Throwable $e) {
        error_log('S3 download failed for ' . $relativePath . ': ' . $e->getMessage());
        return null;
    }
}

function storage_base_path_for_backend() {
    if (FILE_STORAGE_BACKEND === 'object') {
        return rtrim(OBJECT_STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR;
    }
    return rtrim(PRIVATE_STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR;
}

function storage_resolve_document_path($relativePath) {
    $relativePath = storage_normalize_relative_path($relativePath);
    if ($relativePath === '') {
        return null;
    }

    if (FILE_STORAGE_BACKEND === 'object') {
        $objectLocalCandidate = rtrim(OBJECT_STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($objectLocalCandidate)) {
            return $objectLocalCandidate;
        }

        $remoteTemp = storage_object_download_to_temp($relativePath);
        if ($remoteTemp !== null) {
            return $remoteTemp;
        }
    }

    $candidates = [
        storage_base_path_for_backend() . str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
        rtrim(PRIVATE_STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
        rtrim(UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function storage_save_uploaded_file($tmpPath, $folder, $prefix, $extension) {
    $folder = trim((string)$folder, '/');
    $extension = strtolower(trim((string)$extension));
    if ($folder === '' || $tmpPath === '' || $extension === '') {
        return null;
    }

    $fileName = uniqid() . '_' . trim((string)$prefix, '_') . '.' . $extension;
    $relativePath = $folder . '/' . $fileName;

    if (FILE_STORAGE_BACKEND === 'object') {
        $bytes = file_get_contents($tmpPath);
        if ($bytes === false) {
            return null;
        }
        $contentType = function_exists('mime_content_type') ? ((string)mime_content_type($tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';

        $remoteSaved = storage_object_upload_bytes($relativePath, $bytes, $contentType);
        $mirrorSaved = false;
        if (storage_object_local_mirror_enabled()) {
            $mirrorSaved = storage_write_local_file($relativePath, $bytes);
        }

        if (storage_object_require_remote() && !$remoteSaved) {
            return null;
        }
        if ($remoteSaved || $mirrorSaved) {
            return $relativePath;
        }
        return null;
    }

    $destination = storage_base_path_for_backend() . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $destinationDir = dirname($destination);

    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
        return null;
    }

    if (move_uploaded_file($tmpPath, $destination)) {
        return $relativePath;
    }

    return null;
}

function storage_save_base64_file($dataUri, $folder, $prefix, array $allowedExt = ['jpg', 'jpeg', 'png']) {
    if (!preg_match('/^data:image\/([^;]+);base64,(.+)$/', (string)$dataUri, $matches)) {
        return null;
    }

    $ext = strtolower(trim((string)$matches[1]));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $decoded = base64_decode((string)$matches[2], true);
    if ($decoded === false) {
        return null;
    }

    $folder = trim((string)$folder, '/');
    if ($folder === '') {
        return null;
    }

    $fileName = uniqid() . '_' . trim((string)$prefix, '_') . '.' . $ext;
    $relativePath = $folder . '/' . $fileName;

    if (FILE_STORAGE_BACKEND === 'object') {
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
        ];
        $contentType = $mimeMap[$ext] ?? 'application/octet-stream';
        $remoteSaved = storage_object_upload_bytes($relativePath, $decoded, $contentType);
        $mirrorSaved = false;
        if (storage_object_local_mirror_enabled()) {
            $mirrorSaved = storage_write_local_file($relativePath, $decoded);
        }

        if (storage_object_require_remote() && !$remoteSaved) {
            return null;
        }
        if ($remoteSaved || $mirrorSaved) {
            return $relativePath;
        }
        return null;
    }

    $destination = storage_base_path_for_backend() . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $destinationDir = dirname($destination);

    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
        return null;
    }

    if (file_put_contents($destination, $decoded) !== false) {
        return $relativePath;
    }

    return null;
}

// Log audit trail
function log_audit($user_id, $action, $entity_type = null, $entity_id = null, $old_values = null, $new_values = null) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        $eventId = generate_audit_event_id();
        $requestId = current_request_id();
        $meta = [
            'event_id' => $eventId,
            'request_id' => $requestId,
            'request_audit_event_id' => (string)($_SERVER['APP_AUDIT_EVENT_ID'] ?? ''),
            'logged_at' => date('c'),
        ];

        $normalizeValues = static function ($value) use ($meta) {
            if ($value === null) {
                return ['_meta' => $meta];
            }
            if (is_array($value)) {
                if (!isset($value['_meta'])) {
                    $value['_meta'] = $meta;
                }
                return $value;
            }
            return [
                'value' => (string)$value,
                '_meta' => $meta,
            ];
        };
        
        $query = "INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) 
                  VALUES (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent)";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':user_id' => $user_id,
            ':action' => $action,
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':old_values' => json_encode($normalizeValues($old_values)),
            ':new_values' => json_encode($normalizeValues($new_values)),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        return $eventId;
    } catch(Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
        return null;
    }
}
?>
