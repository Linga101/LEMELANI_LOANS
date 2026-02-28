<?php
/**
 * Lemelani Loans - Database Configuration
 */

// Database credentials
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'lemelani_loans');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : '');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
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
        $this->conn = null;

        try {
            if ($this->username === '') {
                throw new RuntimeException('Database username is not configured. Set DB_USER in the environment.');
            }
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch(Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
        }

        return $this->conn;
    }

    // Close connection
    public function closeConnection() {
        $this->conn = null;
    }
}
?>
