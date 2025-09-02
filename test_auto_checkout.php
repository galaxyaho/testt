<?php
date_default_timezone_set('Asia/Kolkata');

/**
 * Test Auto Checkout System for L.P.S.T Hotel Booking
 * Use this file to test the auto checkout functionality manually
 */

echo "<h1>🕙 Testing Auto Checkout System for L.P.S.T Hotel</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Timezone: " . date_default_timezone_get() . "</p>";

// Test database connection first
echo "<h3>1. Testing Database Connection...</h3>";
$host = 'localhost';
$dbname = 'u261459251_patel';
$username = 'u261459251_levagt';
$password = 'GtPatelsamaj@0330';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '+05:30'");
    echo "✅ Database connection successful!<br>";
} catch(PDOException $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
    exit;
}

// Check if required tables exist
echo "<h3>2. Checking Database Tables...</h3>";
$tables = ['bookings', 'resources', 'auto_checkout_logs', 'system_settings'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✅ Table '$table' exists with $count records<br>";
    } catch (Exception $e) {
        echo "❌ Table '$table' missing or error: " . $e->getMessage() . "<br>";
        if ($table === 'auto_checkout_logs' || $table === 'system_settings') {
            echo "ℹ️ Please run the setup_auto_checkout.sql file in phpMyAdmin<br>";
        }
    }
}

// Check active bookings
echo "<h3>3. Checking Active Bookings...</h3>";
try {
    $stmt = $pdo->query("
        SELECT b.*, r.display_name, r.custom_name, r.type
        FROM bookings b 
        JOIN resources r ON b.resource_id = r.id 
        WHERE b.status IN ('BOOKED', 'PENDING')
        ORDER BY b.check_in DESC
    ");
    $activeBookings = $stmt->fetchAll();
    
    if (empty($activeBookings)) {
        echo "ℹ️ No active bookings found. Auto checkout needs active bookings to work.<br>";
        echo "💡 Create some bookings in the admin panel to test auto checkout.<br>";
    } else {
        echo "✅ Found " . count($activeBookings) . " active bookings:<br>";
        foreach ($activeBookings as $booking) {
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $checkInTime = date('M j, H:i', strtotime($booking['check_in']));
            echo "- {$resourceName}: {$booking['client_name']} (Check-in: {$checkInTime}, Status: {$booking['status']})<br>";
        }
    }
} catch (Exception $e) {
    echo "❌ Error checking bookings: " . $e->getMessage() . "<br>";
}

// Check system settings
echo "<h3>4. Checking System Settings...</h3>";
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
        echo "<strong>{$row['setting_key']}:</strong> {$row['setting_value']}<br>";
    }
    
    // Check if required settings exist
    $requiredSettings = ['auto_checkout_time', 'auto_checkout_enabled', 'timezone'];
    foreach ($requiredSettings as $setting) {
        if (!isset($settings[$setting])) {
            echo "⚠️ Missing setting: {$setting}<br>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error reading settings: " . $e->getMessage() . "<br>";
    echo "ℹ️ Please run the setup_auto_checkout.sql file in phpMyAdmin<br>";
}

// Test auto checkout system
echo "<h3>5. Testing Auto Checkout System...</h3>";
try {
    require_once 'includes/auto_checkout.php';
    
    $autoCheckout = new AutoCheckout($pdo);
    $result = $autoCheckout->executeDailyCheckout();
    
    echo "✅ Auto checkout test completed!<br>";
    echo "<strong>Result:</strong> " . $result['status'] . "<br>";
    
    if (isset($result['checked_out'])) {
        echo "<strong>Bookings checked out:</strong> " . $result['checked_out'] . "<br>";
    }
    
    if (isset($result['failed'])) {
        echo "<strong>Failed checkouts:</strong> " . $result['failed'] . "<br>";
    }
    
    if (isset($result['message'])) {
        echo "<strong>Message:</strong> " . $result['message'] . "<br>";
    }
    
    if (isset($result['details']) && !empty($result['details']['successful'])) {
        echo "<strong>Successfully checked out:</strong><br>";
        foreach ($result['details']['successful'] as $booking) {
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            echo "- {$resourceName}: {$booking['client_name']}<br>";
        }
    }
    
    if (isset($result['details']) && !empty($result['details']['failed'])) {
        echo "<strong>Failed checkouts:</strong><br>";
        foreach ($result['details']['failed'] as $failed) {
            $booking = $failed['booking'];
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            echo "- {$resourceName}: {$booking['client_name']} - Error: {$failed['error']}<br>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Auto checkout test failed: " . $e->getMessage() . "<br>";
}

// Check auto checkout logs
echo "<h3>6. Recent Auto Checkout Logs...</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT acl.*, r.display_name, r.custom_name
        FROM auto_checkout_logs acl
        LEFT JOIN resources r ON acl.resource_id = r.id
        ORDER BY acl.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    if (empty($logs)) {
        echo "ℹ️ No auto checkout logs found yet.<br>";
    } else {
        echo "✅ Recent auto checkout logs:<br>";
        foreach ($logs as $log) {
            $resourceName = $log['custom_name'] ?: $log['display_name'] ?: $log['resource_name'];
            $time = date('M j, H:i', strtotime($log['created_at']));
            $status = $log['status'] === 'success' ? '✅' : '❌';
            echo "- {$status} {$time}: {$resourceName} - {$log['guest_name']}<br>";
        }
    }
} catch (Exception $e) {
    echo "❌ Error reading logs: " . $e->getMessage() . "<br>";
}

echo "<h3>7. Manual Test Links</h3>";
echo "<a href='cron/auto_checkout_cron.php?manual_run=1' target='_blank' style='color: var(--primary-color); text-decoration: none; font-weight: bold;'>🧪 Test Cron Script</a><br>";
echo "<a href='admin/auto_checkout_settings.php' style='color: var(--primary-color); text-decoration: none; font-weight: bold;'>⚙️ Auto Checkout Settings</a><br>";
echo "<a href='admin/auto_checkout_logs.php' style='color: var(--primary-color); text-decoration: none; font-weight: bold;'>📋 View All Checkout Logs</a><br>";
echo "<a href='grid.php' style='color: var(--primary-color); text-decoration: none; font-weight: bold;'>🏠 Back to Main Grid</a><br>";

echo "<h3>8. Cron Job Setup for Hostinger</h3>";
echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid var(--primary-color);'>";
echo "<p><strong>Add this to your Hostinger cron jobs:</strong></p>";
echo "<code style='background: white; padding: 10px; border-radius: 4px; display: block; font-family: monospace;'>";
echo "*/5 * * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php";
echo "</code>";
echo "<p><strong>This will run every 5 minutes and execute auto checkout at the configured time (default: 10:00 AM)</strong></p>";
echo "</div>";

echo "<h3>9. Next Steps</h3>";
echo "<ol>";
echo "<li>Run the <strong>setup_auto_checkout.sql</strong> file in phpMyAdmin</li>";
echo "<li>Set up the cron job in Hostinger control panel</li>";
echo "<li>Configure auto checkout time in <a href='admin/auto_checkout_settings.php'>Auto Checkout Settings</a></li>";
echo "<li>Test the system using the manual test button</li>";
echo "<li>Monitor the logs to ensure everything works correctly</li>";
echo "</ol>";

echo "<div style='background: rgba(40, 167, 69, 0.1); padding: 15px; border-radius: 8px; margin-top: 20px;'>";
echo "<h4 style='color: var(--success-color);'>✅ System Ready!</h4>";
echo "<p>Your auto checkout system is now configured and ready to use. All active bookings will be automatically checked out daily at the configured time.</p>";
echo "</div>";
        
?>