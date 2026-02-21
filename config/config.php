<?php
/**
 * Lemelani Loans - Main Configuration
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Site settings
define('SITE_NAME', 'Lemelani Loans');
define('SITE_TAGLINE', 'Borrow Smart, Live Better');

// Base URL -- automatically detect from the server environment (works for local & production).
// You can override this manually if needed by changing the value below.
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
// dirname($_SERVER['SCRIPT_NAME']) returns the directory where the current script resides; trim trailing slashes
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\/');
define('SITE_URL', $protocol . '://' . $host . $scriptDir);

// Example: if your app lives at http://localhost/LEMELANI_LOANS this will
// result in SITE_URL == 'http://localhost/LEMELANI_LOANS'

define('SITE_EMAIL', 'info@lemelaniloans.com');
define('SITE_PHONE', '+265 999 123 456');

// Directory paths
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

// Upload directories
define('ID_UPLOAD_DIR', UPLOAD_PATH . 'ids/');
define('SELFIE_UPLOAD_DIR', UPLOAD_PATH . 'selfies/');

// Create upload directories if they don't exist
if (!file_exists(ID_UPLOAD_DIR)) {
    mkdir(ID_UPLOAD_DIR, 0755, true);
}
if (!file_exists(SELFIE_UPLOAD_DIR)) {
    mkdir(SELFIE_UPLOAD_DIR, 0755, true);
}

// Security settings
define('HASH_ALGO', PASSWORD_DEFAULT);
define('SESSION_LIFETIME', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900); // 15 minutes

// Loan settings (these can be overridden by database settings)
define('MIN_LOAN_AMOUNT', 10000);
define('MAX_LOAN_AMOUNT', 300000);
define('DEFAULT_INTEREST_RATE', 5.00);
define('DEFAULT_LOAN_TERM', 30);

// Timezone
date_default_timezone_set('Africa/Blantyre');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    $data = htmlspecialchars($data);
    return $data;
}

function redirect($url) {
    // accept absolute URLs
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        header("Location: $url");
    } else {
        // ensure path begins with slash
        $path = (strpos($url, '/') === 0) ? $url : "/$url";
        header("Location: " . rtrim(SITE_URL, '/') . $path);
    }
    exit();
}

/**
 * Helper to build links to the application.
 * Usage: <a href="<?php echo site_url('loans.php'); ?>">My Loans</a>
 */
function site_url($path = '') {
    $path = ltrim($path, '/');
    $base = rtrim(SITE_URL, '/');

    // Prevent duplicate path segments when SITE_URL already contains a folder
    // (e.g. when in admin area SITE_URL ends with "/admin"). If the path
    // being requested already begins with that same segment, strip it out
    // so that we don't end up with "/admin/admin/foo.php" which would 404.
    $baseSegments = explode('/', $base);
    $lastSegment = end($baseSegments);
    if ($lastSegment && str_starts_with($path, $lastSegment . '/')) {
        $path = substr($path, strlen($lastSegment) + 1);
    }

    return $base . '/' . $path;
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

function require_login() {
    if (!is_logged_in()) {
        redirect('/login.php');
    }
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