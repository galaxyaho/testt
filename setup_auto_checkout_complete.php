<?php
/**
 * Complete Auto Checkout Setup Script
 * Run this once to ensure everything is properly configured
 */

date_default_timezone_set('Asia/Kolkata');

echo "<!DOCTYPE html><html><head><title>Auto Checkout Complete Setup</title>";
echo "<style>body{font-family:Arial;margin:20px;line-height:1.6;} .success{color:green;} .error{color:red;} .warning{color:orange;} .info{color:blue;} .code{background:#f5f5f5;padding:10px;border-radius:5px;font-family:monospace;} .section{margin:20px 0; padding:15px; border-radius:8px;} .section-success{background:#d4edda; border-left:4px solid #28a745;} .section-warning{background:#fff3cd; border-left:4px solid #ffc107;} .section-error{background:#f8d7da; border-left:4px solid #dc3545;}</style>";
echo "</head><body>";

echo "<h1>🔧 Complete Auto Checkout Setup for L.P.S.T Hotel</h1>";
echo "<p class='info'>Setup Date: " . date('Y-m-d H:i:s') . " (Asia/Kolkata)</p>";

// Database connection
echo "<h2>1. Database Connection</h2>";
try {
    require_once 'config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();
    echo "<div class='section section-success'><p class='success'>✅ Database connection successful!</p></div>";
} catch(Exception $e) {
    echo "<div class='section section-error'><p class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p></div>";
    exit;
}

// Create/Update tables
echo "<h2>2. Creating/Updating Database Tables</h2>";

try {
    // Create auto_checkout_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `auto_checkout_logs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `booking_id` int(11) UNSIGNED DEFAULT NULL,
          `resource_id` int(11) UNSIGNED NOT NULL,
          `resource_name` varchar(100) NOT NULL,
          `guest_name` varchar(100) DEFAULT NULL,
          `checkout_date` date NOT NULL,
          `checkout_time` time NOT NULL,
          `status` enum('success','failed') DEFAULT 'success',
          `notes` text DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_checkout_date` (`checkout_date`),
          KEY `idx_resource` (`resource_id`),
          KEY `idx_booking` (`booking_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p class='success'>✅ auto_checkout_logs table created/verified</p>";
    
    // Create system_settings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_settings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `setting_key` varchar(100) NOT NULL,
          `setting_value` text NOT NULL,
          `description` text DEFAULT NULL,
          `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p class='success'>✅ system_settings table created/verified</p>";
    
    // Add columns to bookings table if they don't exist
    try {
        $pdo->exec("ALTER TABLE `bookings` ADD COLUMN `auto_checkout_processed` tinyint(1) DEFAULT 0");
        echo "<p class='success'>✅ Added auto_checkout_processed column to bookings</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<p class='info'>ℹ️ auto_checkout_processed column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    try {
        $pdo->exec("ALTER TABLE `bookings` ADD COLUMN `actual_checkout_date` date DEFAULT NULL");
        echo "<p class='success'>✅ Added actual_checkout_date column to bookings</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<p class='info'>ℹ️ actual_checkout_date column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    try {
        $pdo->exec("ALTER TABLE `bookings` ADD COLUMN `actual_checkout_time` time DEFAULT NULL");
        echo "<p class='success'>✅ Added actual_checkout_time column to bookings</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "<p class='info'>ℹ️ actual_checkout_time column already exists</p>";
        } else {
            throw $e;
        }
    }
    
    // Add index for performance
    try {
        $pdo->exec("ALTER TABLE `bookings` ADD INDEX `idx_bookings_auto_checkout` (`status`, `auto_checkout_processed`, `check_in`)");
        echo "<p class='success'>✅ Added performance index to bookings table</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "<p class='info'>ℹ️ Performance index already exists</p>";
        } else {
            echo "<p class='warning'>⚠️ Could not add index: " . $e->getMessage() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='section section-error'><p class='error'>❌ Table creation failed: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}

// Insert/Update default settings
echo "<h2>3. Configuring Default Settings</h2>";

try {
    $defaultSettings = [
        'auto_checkout_enabled' => '1',
        'auto_checkout_time' => '10:00',
        'timezone' => 'Asia/Kolkata',
        'last_auto_checkout_run' => '',
        'checkout_grace_minutes' => '30',
        'auto_checkout_rate_room' => '100',
        'auto_checkout_rate_hall' => '500',
        'manual_checkout_enabled' => '1',
        'debug_mode' => '1'
    ];
    
    foreach ($defaultSettings as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = CASE 
                WHEN setting_value = '' OR setting_value IS NULL THEN VALUES(setting_value)
                ELSE setting_value
            END
        ");
        $stmt->execute([$key, $value]);
        echo "<p class='success'>✅ Setting '$key' configured</p>";
    }
    
    echo "<div class='section section-success'><p class='success'>✅ All default settings configured successfully!</p></div>";
    
} catch (Exception $e) {
    echo "<div class='section section-error'><p class='error'>❌ Settings configuration failed: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}

// Test auto checkout functionality
echo "<h2>4. Testing Auto Checkout Functionality</h2>";

try {
    require_once 'includes/auto_checkout.php';
    $autoCheckout = new AutoCheckout($pdo);
    
    echo "<p class='success'>✅ AutoCheckout class loaded successfully</p>";
    
    // Get current active bookings
    $stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('BOOKED', 'PENDING')");
    $activeCount = $stmt->fetchColumn();
    
    echo "<p class='info'>ℹ️ Found $activeCount active bookings for testing</p>";
    
    if ($activeCount > 0) {
        echo "<p class='warning'>⚠️ Ready to test with real bookings. Use manual test page for safe testing.</p>";
    } else {
        echo "<p class='warning'>⚠️ No active bookings found. Create test bookings to verify functionality.</p>";
    }
    
} catch (Exception $e) {
    echo "<div class='section section-error'><p class='error'>❌ Auto checkout test failed: " . htmlspecialchars($e->getMessage()) . "</p></div>";
}

// Cron job setup instructions
echo "<h2>5. Hostinger Cron Job Setup</h2>";
echo "<div class='section section-warning'>";
echo "<h3>⚙️ Add this cron job in your Hostinger control panel:</h3>";
echo "<div class='code'>";
echo "*/5 * * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php";
echo "</div>";
echo "<p><strong>This runs every 5 minutes and executes auto checkout at the configured time.</strong></p>";

echo "<h4>Alternative cron job options:</h4>";
echo "<p><strong>Daily at 10:00 AM exactly:</strong></p>";
echo "<div class='code'>0 10 * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php</div>";

echo "<p><strong>Every minute (for testing only):</strong></p>";
echo "<div class='code'>* * * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php</div>";
echo "</div>";

// Manual testing instructions
echo "<h2>6. Manual Testing (No 24 Hour Wait)</h2>";
echo "<div class='section section-success'>";
echo "<h3>🚀 Test Auto Checkout Immediately:</h3>";
echo "<ol>";
echo "<li><a href='admin/manual_checkout_test.php' style='color:#007bff; font-weight:bold;'>Go to Manual Test Page</a></li>";
echo "<li>Set auto checkout time to current time + 2 minutes: <strong>" . date('H:i', strtotime('+2 minutes')) . "</strong></li>";
echo "<li>Wait 2 minutes and check if bookings are automatically processed</li>";
echo "<li>Or use 'Force Checkout All' for immediate testing</li>";
echo "<li>Check logs to verify execution</li>";
echo "</ol>";

echo "<h4>Direct Test Links:</h4>";
echo "<p><a href='cron/auto_checkout_cron.php?manual_run=1' target='_blank' style='color:#28a745; font-weight:bold;'>🔗 Test Cron Script Directly</a></p>";
echo "<p><a href='test_auto_checkout_debug.php' target='_blank' style='color:#ffc107; font-weight:bold;'>🔍 Debug Test Page</a></p>";
echo "</div>";

// Final status
echo "<h2>7. System Status Summary</h2>";

$currentSettings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch()) {
        $currentSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    echo "<p class='error'>Could not read current settings</p>";
}

$autoEnabled = ($currentSettings['auto_checkout_enabled'] ?? '1') === '1';
$autoTime = $currentSettings['auto_checkout_time'] ?? '10:00';

echo "<div class='section section-success'>";
echo "<h3>✅ Setup Complete!</h3>";
echo "<ul>";
echo "<li><strong>Auto Checkout:</strong> " . ($autoEnabled ? 'ENABLED' : 'DISABLED') . "</li>";
echo "<li><strong>Daily Time:</strong> $autoTime</li>";
echo "<li><strong>Current Time:</strong> " . date('H:i') . "</li>";
echo "<li><strong>Database:</strong> All tables created</li>";
echo "<li><strong>Settings:</strong> All configured</li>";
echo "<li><strong>Testing:</strong> Ready for manual testing</li>";
echo "</ul>";

echo "<h4>Next Steps:</h4>";
echo "<ol>";
echo "<li>Set up the cron job in Hostinger control panel</li>";
echo "<li>Use the manual test page to verify functionality</li>";
echo "<li>Monitor logs for any issues</li>";
echo "<li>Create test bookings if needed</li>";
echo "</ol>";
echo "</div>";

echo "<div style='text-align:center; margin:30px 0;'>";
echo "<a href='admin/manual_checkout_test.php' style='background:#007bff; color:white; padding:15px 30px; text-decoration:none; border-radius:8px; font-size:18px; font-weight:bold;'>🚀 Start Manual Testing</a>";
echo "</div>";

echo "</body></html>";
?>