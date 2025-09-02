<?php
class Database {
    private $host = 'localhost';
    private $db_name = 'u261459251_patel';
    private $username = 'u261459251_levagt';
    private $password = 'GtPatelsamaj@0330';
    public $conn;

    public function __construct() {
        // You can still override with environment variables if needed
        $this->host = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? $this->host;
        $this->db_name = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? $this->db_name;
        $this->username = $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? $this->username;
        $this->password = $_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? $this->password;
    }

    public function getConnection() {
        $this->conn = null;
        try {
            // Set timezone to Asia/Kolkata for Indian time
            date_default_timezone_set('Asia/Kolkata');
            
            // Connect directly to your database
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            // Set MySQL timezone to match PHP timezone
            $this->conn->exec("SET time_zone = '+05:30'");
            
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            
            // Show user-friendly error message
            die("
                <div style='font-family: Arial; padding: 20px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; margin: 20px; color: #721c24; background-color: #f8d7da; border-color: #f5c6cb;'>
                    <h3>Database Connection Error</h3>
                    <p><strong>Could not connect to database:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>
                    <p><strong>Database Details:</strong></p>
                    <ul>
                        <li>Host: " . htmlspecialchars($this->host) . "</li>
                        <li>Database: " . htmlspecialchars($this->db_name) . "</li>
                        <li>Username: " . htmlspecialchars($this->username) . "</li>
                    </ul>
                    <p>Please check your database credentials and ensure the database exists.</p>
                    <p><a href='setup_database.php' style='color: #0066cc;'>Try Database Setup</a></p>
                </div>
            ");
        }
        return $this->conn;
    }
}
?>