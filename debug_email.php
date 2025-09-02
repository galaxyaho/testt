<?php
// Debug email configuration
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/functions.php';
require_once 'config/database.php';

echo "<h2>Email Configuration Debug</h2>";

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    echo "<p>✅ Database connection successful</p>";
    
    // Check if email_logs table exists
    try {
        $stmt = $pdo->query("DESCRIBE email_logs");
        echo "<p>✅ email_logs table exists</p>";
    } catch (Exception $e) {
        echo "<p>⚠️ email_logs table missing - creating it...</p>";
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                email_type ENUM('EXPORT','REPORT','NOTIFICATION') NOT NULL,
                status ENUM('SENT','FAILED','PENDING') DEFAULT 'PENDING',
                response_data TEXT DEFAULT NULL,
                admin_id INT NOT NULL,
                sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (admin_id) REFERENCES users(id)
            )
        ");
        
        echo "<p>✅ email_logs table created</p>";
    }
    
    // Get current email settings
    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value 
        FROM settings 
        WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'hotel_name')
    ");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    echo "<h3>Current Email Settings:</h3>";
    echo "<ul>";
    echo "<li>SMTP Host: " . htmlspecialchars($settings['smtp_host'] ?? 'Not set') . "</li>";
    echo "<li>SMTP Port: " . htmlspecialchars($settings['smtp_port'] ?? 'Not set') . "</li>";
    echo "<li>SMTP Username: " . htmlspecialchars($settings['smtp_username'] ?? 'Not set') . "</li>";
    echo "<li>SMTP Password: " . (empty($settings['smtp_password']) ? 'Not set' : '***SET***') . "</li>";
    echo "<li>SMTP Encryption: " . htmlspecialchars($settings['smtp_encryption'] ?? 'Not set') . "</li>";
    echo "<li>Hotel Name: " . htmlspecialchars($settings['hotel_name'] ?? 'Not set') . "</li>";
    echo "</ul>";
    
    // Check PHPMailer availability
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        echo "<p>✅ PHPMailer available via Composer</p>";
        require_once __DIR__ . '/vendor/autoload.php';
        echo "<p>✅ PHPMailer loaded successfully</p>";
    } else {
        echo "<p>⚠️ PHPMailer not available - will use PHP mail() function as fallback</p>";
        echo "<p style='background: #fff3cd; padding: 10px; border-radius: 5px; color: #856404;'>";
        echo "<strong>Recommendation:</strong> Install PHPMailer for better email delivery:<br>";
        echo "<code>composer require phpmailer/phpmailer</code>";
        echo "</p>";
    }
    
    // Check if all required settings are present
    $required = ['smtp_host', 'smtp_username', 'smtp_password'];
    $missing = [];
    foreach ($required as $key) {
        if (empty($settings[$key])) {
            $missing[] = $key;
        }
    }
    
    if (!empty($missing)) {
        echo "<p style='color: red;'>❌ Missing required settings: " . implode(', ', $missing) . "</p>";
    } else {
        echo "<p style='color: green;'>✅ All required email settings are configured</p>";
        
        // Test SMTP connection
        echo "<h3>Testing SMTP Connection:</h3>";
        try {
            if (function_exists('fsockopen')) {
                $host = $settings['smtp_host'];
                $port = $settings['smtp_port'] ?? 587;
                $timeout = 10;
                
                $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
                if ($connection) {
                    echo "<p style='color: green;'>✅ SMTP server connection successful ($host:$port)</p>";
                    fclose($connection);
                } else {
                    echo "<p style='color: red;'>❌ Cannot connect to SMTP server ($host:$port) - Error: $errstr</p>";
                }
            } else {
                echo "<p style='color: orange;'>⚠️ Cannot test SMTP connection - fsockopen function not available</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ SMTP connection test failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    // Show current PHP mail configuration
    echo "<h3>PHP Mail Configuration:</h3>";
    echo "<ul>";
    echo "<li>sendmail_path: " . (ini_get('sendmail_path') ?: 'Not set') . "</li>";
    echo "<li>SMTP (Windows): " . (ini_get('SMTP') ?: 'Not set') . "</li>";
    echo "<li>smtp_port (Windows): " . (ini_get('smtp_port') ?: 'Not set') . "</li>";
    echo "</ul>";
    
    echo "<hr>";
    echo "<p><a href='owner/settings.php'>Go to Settings</a></p>";
    echo "<p><a href='owner/settings.php#test-email'>Test Email Configuration</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack trace:</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>