<?php
/**
 * Enhanced Auto Checkout Cron Job for L.P.S.T Hotel Booking System
 * 
 * HOSTINGER CRON JOB COMMANDS:
 * 
 * Option 1 (Recommended): Run every 5 minutes, checks if it's time
 * */5 * * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php
 * 
 * Option 2: Run exactly at 10:00 AM daily
 * 0 10 * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php
 * 
 * Option 3: Run every minute for testing (remove after testing)
 * * * * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php
 */

// Set timezone first
date_default_timezone_set('Asia/Kolkata');

// Create logs directory
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Enhanced logging function
function logMessage($message, $level = 'INFO') {
    global $logDir;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message\n";
    
    // Write to log file
    file_put_contents($logDir . '/auto_checkout.log', $logMessage, FILE_APPEND | LOCK_EX);
    
    // Also write to daily log
    $dailyLogFile = $logDir . '/auto_checkout_' . date('Y-m-d') . '.log';
    file_put_contents($dailyLogFile, $logMessage, FILE_APPEND | LOCK_EX);
    
    // Output for manual runs
    if (isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli') {
        echo $logMessage;
    }
}

// Check if this is a manual run
$isManualRun = isset($_GET['manual_run']) || isset($_GET['test']) || php_sapi_name() !== 'cli';

if ($isManualRun) {
    header('Content-Type: application/json');
    logMessage("MANUAL AUTO CHECKOUT TEST STARTED", 'TEST');
} else {
    logMessage("AUTOMATIC CRON AUTO CHECKOUT STARTED", 'CRON');
}

// Database connection with enhanced error handling
try {
    $host = 'localhost';
    $dbname = 'u261459251_patel';
    $username = 'u261459251_levagt';
    $password = 'GtPatelsamaj@0330';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 30
    ]);
    
    $pdo->exec("SET time_zone = '+05:30'");
    logMessage("Database connection successful");
    
} catch(PDOException $e) {
    $error = "Database connection failed: " . $e->getMessage();
    logMessage($error, 'ERROR');
    
    if ($isManualRun) {
        echo json_encode(['status' => 'error', 'message' => $error, 'timestamp' => date('Y-m-d H:i:s')]);
    }
    exit(1);
}

// Load and execute auto checkout
try {
    require_once dirname(__DIR__) . '/includes/auto_checkout.php';
    
    $autoCheckout = new AutoCheckout($pdo);
    $result = $autoCheckout->executeDailyCheckout();
    
    // Enhanced logging
    $logLevel = $result['status'] === 'error' ? 'ERROR' : 'INFO';
    $logMessage = "Auto Checkout Result: " . json_encode($result, JSON_PRETTY_PRINT);
    logMessage($logMessage, $logLevel);
    
    // Output result
    if ($isManualRun) {
        echo json_encode($result, JSON_PRETTY_PRINT);
    } else {
        echo "Auto checkout executed: " . $result['status'] . "\n";
        if (isset($result['checked_out'])) {
            echo "Checked out: " . $result['checked_out'] . " bookings\n";
        }
        if (isset($result['failed'])) {
            echo "Failed: " . $result['failed'] . " bookings\n";
        }
        if (isset($result['message'])) {
            echo "Message: " . $result['message'] . "\n";
        }
    }
    
    // Exit with appropriate code
    exit($result['status'] === 'error' ? 1 : 0);
    
} catch (Exception $e) {
    $errorMessage = "Auto Checkout Critical Error: " . $e->getMessage();
    logMessage($errorMessage, 'CRITICAL');
    
    if ($isManualRun) {
        http_response_code(500);
        echo json_encode([
            'status' => 'critical_error', 
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s'),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        echo "Critical Error: " . $e->getMessage() . "\n";
    }
    
    exit(1);
}
?>