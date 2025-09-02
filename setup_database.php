<?php
// Database setup script - run this once to create tables and default users
require_once 'config/database.php';

echo "<h2>L.P.S.T Bookings - Database Setup</h2>";
echo "<p>Setting up database with your credentials...</p>";

$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    die("<p style='color: red;'>Database connection failed! Please check your credentials in config/database.php</p>");
}

try {
    echo "<p>✅ Database connection successful!</p>";
    echo "<p>Database: u261459251_software</p>";
    echo "<p>Creating tables...</p>";

    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('OWNER', 'ADMIN') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Users table created</p>";

    // Create resources table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS resources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('room', 'hall') NOT NULL,
            identifier VARCHAR(50) NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_resource (type, identifier)
        )
    ");
    echo "<p>✅ Resources table created</p>";

    // Create bookings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            resource_id INT NOT NULL,
            client_name VARCHAR(255) NOT NULL,
            check_in DATETIME NOT NULL,
            check_out DATETIME NOT NULL,
            status ENUM('BOOKED', 'PENDING', 'COMPLETED', 'ADVANCED_BOOKED') DEFAULT 'BOOKED',
            booking_type ENUM('regular', 'advanced') DEFAULT 'regular',
            advance_date DATE NULL,
            admin_id INT NOT NULL,
            is_paid BOOLEAN DEFAULT FALSE,
            total_amount DECIMAL(10,2) DEFAULT 0.00,
            payment_notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (resource_id) REFERENCES resources(id),
            FOREIGN KEY (admin_id) REFERENCES users(id)
        )
    ");
    echo "<p>✅ Bookings table created</p>";

    // Create payments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) DEFAULT 'UPI',
            payment_status ENUM('PENDING', 'COMPLETED', 'FAILED') DEFAULT 'PENDING',
            payment_notes TEXT,
            admin_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES bookings(id),
            FOREIGN KEY (admin_id) REFERENCES users(id)
        )
    ");
    echo "<p>✅ Payments table created</p>";

    // Create settings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(id)
        )
    ");
    echo "<p>✅ Settings table created</p>";

    // Insert default users with hashed passwords
    $ownerPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, role) VALUES (?, ?, ?)");
    
    // Insert owner
    $stmt->execute(['owner', $ownerPassword, 'OWNER']);
    echo "<p>✅ Owner account created (username: owner, password: admin123)</p>";
    
    // Insert admins
    for ($i = 1; $i <= 6; $i++) {
        $stmt->execute(["admin$i", $adminPassword, 'ADMIN']);
    }
    echo "<p>✅ Admin accounts created (admin1-admin6, password: admin123)</p>";

    // Insert resources (26 rooms + 2 halls)
    $stmt = $pdo->prepare("INSERT IGNORE INTO resources (type, identifier, display_name) VALUES (?, ?, ?)");
    
    // Insert rooms
    for ($i = 1; $i <= 26; $i++) {
        $stmt->execute(['room', (string)$i, "ROOM NO $i"]);
    }
    
    // Insert halls
    $stmt->execute(['hall', 'SMALL_PARTY_HALL', 'SMALL PARTY HALL']);
    $stmt->execute(['hall', 'BIG_PARTY_HALL', 'BIG PARTY HALL']);
    echo "<p>✅ Resources created (26 rooms + 2 halls)</p>";

    // Insert default settings
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    $stmt->execute(['upi_id', 'owner@upi']);
    $stmt->execute(['qr_image', '']);
    $stmt->execute(['system_timezone', 'UTC']);
    $stmt->execute(['auto_refresh_interval', '30']);
    $stmt->execute(['checkout_grace_hours', '24']);
    echo "<p>✅ Default settings configured</p>";

    echo "<hr>";
    echo "<h3 style='color: green;'>✅ Database setup completed successfully!</h3>";
    echo "<p><strong>Your system is ready to use!</strong></p>";
    
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; padding: 15px; margin: 10px 0;'>";
    echo "<h4>Login Credentials:</h4>";
    echo "<p><strong>Owner:</strong> username = owner, password = admin123</p>";
    echo "<p><strong>Admins:</strong> username = admin1 to admin6, password = admin123</p>";
    echo "</div>";
    
    echo "<div style='margin: 20px 0;'>";
    echo "<a href='login.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>Go to Login</a>";
    echo "<a href='emergency_login.php' style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Emergency Owner Login</a>";
    echo "</div>";

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error setting up database: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please check your database permissions and try again.</p>";
}
?>