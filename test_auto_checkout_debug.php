<?php
/**
 * Enhanced Debug Test for Auto Checkout System
 * This file provides comprehensive testing and debugging for the auto checkout system
 */

date_default_timezone_set('Asia/Kolkata');

echo "<!DOCTYPE html><html><head><title>Auto Checkout Debug Test</title>";
echo "<style>body{font-family:Arial;margin:20px;} .success{color:green;} .error{color:red;} .warning{color:orange;} .info{color:blue;} pre{background:#f5f5f5;padding:10px;border-radius:5px;}</style>";
echo "</head><body>";

echo "<h1>🕙 Auto Checkout System Debug Test</h1>";
echo "<p class='info'>Current time: " . date('Y-m-d H:i:s') . " (Asia/Kolkata)</p>";
echo "<p class='info'>Timezone: " . date_default_timezone_get() . "</p>";

// Test database connection
echo "<h3>1. Testing Database Connection...</h3>";
try {
    require_once 'config/database.php';
    $database = new Database();
    $pdo = $database->getConnection();
    echo "<p class='success'>✅ Database connection successful!</p>";
} catch(Exception $e) {
    echo "<p class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// Check tables
echo "<h3>2. Checking Database Tables...</h3>";
$tables = ['bookings', 'resources', 'auto_checkout_logs', 'system_settings', 'payments'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "<p class='success'>✅ Table '$table' exists with $count records</p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Table '$table' error: " . htmlspecialchars($e->getMessage()) . "</p>";
        
        if ($table === 'auto_checkout_logs' || $table === 'system_settings') {
            echo "<p class='warning'>⚠️ Please run the SQL migration file to create missing tables</p>";
        }
    }
}

// Check system settings
echo "<h3>3. Checking System Settings...</h3>";
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
        echo "<p><strong>{$row['setting_key']}:</strong> {$row['setting_value']}</p>";
    }
    
    // Check required settings
    $required = ['auto_checkout_enabled', 'auto_checkout_time', 'timezone'];
    foreach ($required as $setting) {
        if (!isset($settings[$setting])) {
            echo "<p class='error'>❌ Missing required setting: {$setting}</p>";
        } else {
            echo "<p class='success'>✅ Setting {$setting}: {$settings[$setting]}</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error reading settings: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Check active bookings
echo "<h3>4. Checking Active Bookings...</h3>";
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
        echo "<p class='warning'>⚠️ No active bookings found. Create some bookings to test auto checkout.</p>";
        echo "<p class='info'>💡 Go to admin panel and create test bookings.</p>";
    } else {
        echo "<p class='success'>✅ Found " . count($activeBookings) . " active bookings:</p>";
        echo "<table border='1' style='border-collapse:collapse; width:100%; margin:10px 0;'>";
        echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Resource</th><th>Client</th><th>Check-in</th><th>Status</th><th>Auto Processed</th></tr>";
        
        foreach ($activeBookings as $booking) {
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $checkInTime = date('M j, H:i', strtotime($booking['check_in']));
            $autoProcessed = $booking['auto_checkout_processed'] ?? 0;
            
            echo "<tr>";
            echo "<td>{$booking['id']}</td>";
            echo "<td>{$resourceName}</td>";
            echo "<td>{$booking['client_name']}</td>";
            echo "<td>{$checkInTime}</td>";
            echo "<td>{$booking['status']}</td>";
            echo "<td>" . ($autoProcessed ? 'YES' : 'NO') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error checking bookings: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test auto checkout class
echo "<h3>5. Testing Auto Checkout Class...</h3>";
try {
    require_once 'includes/auto_checkout.php';
    $autoCheckout = new AutoCheckout($pdo);
    echo "<p class='success'>✅ AutoCheckout class loaded successfully</p>";
    
    // Test the checkout logic
    echo "<h4>5a. Running Manual Test...</h4>";
    $result = $autoCheckout->testAutoCheckout();
    
    echo "<pre>";
    echo json_encode($result, JSON_PRETTY_PRINT);
    echo "</pre>";
    
    if ($result['status'] === 'completed') {
        echo "<p class='success'>✅ Auto checkout test completed successfully!</p>";
        echo "<p>Checked out: " . ($result['checked_out'] ?? 0) . " bookings</p>";
        echo "<p>Failed: " . ($result['failed'] ?? 0) . " bookings</p>";
    } elseif ($result['status'] === 'no_bookings') {
        echo "<p class='warning'>⚠️ No bookings to checkout (this is normal if no active bookings exist)</p>";
    } else {
        echo "<p class='error'>❌ Test result: " . $result['status'] . "</p>";
        if (isset($result['message'])) {
            echo "<p class='error'>Message: " . htmlspecialchars($result['message']) . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Auto checkout test failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Test cron script directly
echo "<h3>6. Testing Cron Script...</h3>";
echo "<p><a href='cron/auto_checkout_cron.php?manual_run=1' target='_blank' style='color:blue; font-weight:bold;'>🔗 Test Cron Script Directly</a></p>";

// Check recent logs
echo "<h3>7. Recent Auto Checkout Logs...</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT acl.*, r.display_name, r.custom_name
        FROM auto_checkout_logs acl
        LEFT JOIN resources r ON acl.resource_id = r.id
        ORDER BY acl.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    if (empty($logs)) {
        echo "<p class='warning'>⚠️ No auto checkout logs found yet.</p>";
    } else {
        echo "<p class='success'>✅ Recent auto checkout logs:</p>";
        echo "<table border='1' style='border-collapse:collapse; width:100%; margin:10px 0;'>";
        echo "<tr style='background:#f0f0f0;'><th>Time</th><th>Resource</th><th>Guest</th><th>Status</th><th>Notes</th></tr>";
        
        foreach ($logs as $log) {
            $resourceName = $log['custom_name'] ?: $log['display_name'] ?: $log['resource_name'];
            $time = date('M j, H:i', strtotime($log['created_at']));
            $status = $log['status'] === 'success' ? '✅' : '❌';
            
            echo "<tr>";
            echo "<td>{$time}</td>";
            echo "<td>{$resourceName}</td>";
            echo "<td>{$log['guest_name']}</td>";
            echo "<td>{$status} {$log['status']}</td>";
            echo "<td>" . htmlspecialchars($log['notes']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error reading logs: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Manual checkout buttons
echo "<h3>8. Manual Test Controls</h3>";
echo "<div style='margin: 20px 0;'>";
echo "<a href='admin/manual_checkout_test.php' style='background:#007bff; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>🧪 Manual Test Page</a>";
echo "<a href='cron/auto_checkout_cron.php?test=1' target='_blank' style='background:#28a745; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;'>🔧 Direct Cron Test</a>";
echo "<a href='admin/auto_checkout_settings.php' style='background:#ffc107; color:black; padding:10px 20px; text-decoration:none; border-radius:5px;'>⚙️ Settings</a>";
echo "</div>";

// System recommendations
echo "<h3>9. System Recommendations</h3>";
echo "<div style='background:#e3f2fd; padding:15px; border-radius:8px; border-left:4px solid #2196f3;'>";
echo "<h4>To Fix Auto Checkout Issues:</h4>";
echo "<ol>";
echo "<li><strong>Import SQL File:</strong> Run the migration SQL file in phpMyAdmin to create/update tables</li>";
echo "<li><strong>Set Cron Job:</strong> Add the cron job command in Hostinger control panel</li>";
echo "<li><strong>Test Manually:</strong> Use the manual test buttons above to verify functionality</li>";
echo "<li><strong>Check Logs:</strong> Monitor the logs directory for detailed execution logs</li>";
echo "<li><strong>Verify Settings:</strong> Ensure auto checkout is enabled and time is set correctly</li>";
echo "</ol>";

echo "<h4>For Immediate Testing (No 24 Hour Wait):</h4>";
echo "<ol>";
echo "<li>Set auto checkout time to current time + 1 minute using the manual test page</li>";
echo "<li>Wait 1 minute and check if bookings are automatically checked out</li>";
echo "<li>Use the 'Force Checkout All' button for immediate testing</li>";
echo "<li>Check the logs to see detailed execution information</li>";
echo "</ol>";
echo "</div>";

echo "<h3>10. Quick Actions</h3>";
echo "<div style='background:#f8f9fa; padding:15px; border-radius:8px;'>";
echo "<p><strong>Current Auto Checkout Time:</strong> " . ($settings['auto_checkout_time'] ?? '10:00') . "</p>";
echo "<p><strong>Suggested Test Time:</strong> " . date('H:i', strtotime('+2 minutes')) . " (2 minutes from now)</p>";
echo "<p><a href='admin/manual_checkout_test.php' style='color:#007bff; font-weight:bold;'>→ Go to Manual Test Page to Update Time</a></p>";
echo "</div>";

echo "<div style='margin-top:30px; padding:20px; background:#d4edda; border-radius:8px;'>";
echo "<h4 style='color:#155724;'>✅ System Ready for Testing!</h4>";
echo "<p>Your auto checkout system is configured and ready. Use the manual test controls above to verify functionality without waiting 24 hours.</p>";
echo "</div>";

echo "</body></html>";
?>