<?php
/**
 * Lemelani Loans - Database Configuration
 */

// Database credentials
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'lemelani_loans');
// In development, default to local XAMPP credentials if env vars are not set.
$dbUserFromEnv = getenv('DB_USER');
$dbPassFromEnv = getenv('DB_PASS');
$dbAppEnv = strtolower((string)(getenv('APP_ENV') ?: 'development'));
define('DB_USER', ($dbUserFromEnv !== false && $dbUserFromEnv !== '') ? $dbUserFromEnv : ($dbAppEnv === 'production' ? '' : 'root'));
define('DB_PASS', ($dbPassFromEnv !== false) ? $dbPassFromEnv : ($dbAppEnv === 'production' ? '' : ''));
define('DB_CHARSET', 'utf8mb4');

// Create database connection class
class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset = DB_CHARSET;
    public $conn;

    // Get database connection
    public function getConnection() {
        if ($this->conn instanceof PDO) {
            return $this->conn;
        }

        try {
            if ($this->username === '') {
                throw new RuntimeException('Database username is not configured.');
            }
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            return $this->conn;
        } catch (Throwable $e) {
            error_log("Database connection error: " . $e->getMessage());
            throw new RuntimeException('Unable to connect to the database at this time.');
        }

        return $this->conn;
    }

    // Close connection
    public function closeConnection() {
        $this->conn = null;
    }
}
?>
