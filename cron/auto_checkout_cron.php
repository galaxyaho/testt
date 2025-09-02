<?php
/**
 * Enhanced Auto Checkout Cron Job for L.P.S.T Hotel Booking System
 * This file should be executed by cron every 5 minutes
 * 
 * Cron command for Hostinger (as shown in your image):
 * 0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
 * 
 * Or every 5 minutes to check if it's time:
 * */5 * * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
 */

// Set timezone first
date_default_timezone_set('Asia/Kolkata');

// Create logs directory if it doesn't exist
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Function to log messages
function logMessage($message) {
    global $logDir;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($logDir . '/auto_checkout.log', $logMessage, FILE_APPEND | LOCK_EX);
    
    // Also output for manual runs
    if (isset($_GET['manual_run']) || isset($_GET['test'])) {
        echo $logMessage;
    }
}

// Allow manual testing via browser
$isManualRun = isset($_GET['manual_run']) || isset($_GET['test']);

if ($isManualRun) {
    // Allow browser access for testing
    header('Content-Type: application/json');
} else if (php_sapi_name() !== 'cli') {
    // Prevent direct browser access in production
    http_response_code(403);
    die('Access denied. This script should only be run via cron job. Add ?manual_run=1 for testing.');
}

logMessage("Auto checkout cron started - " . ($isManualRun ? 'MANUAL RUN' : 'AUTOMATIC'));

// Direct database connection for cron
$host = 'localhost';
$dbname = 'u261459251_patel';
$username = 'u261459251_levagt';
$password = 'GtPatelsamaj@0330';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '+05:30'");
    
    logMessage("Database connection successful");
} catch(PDOException $e) {
    $error = "Database connection failed: " . $e->getMessage();
    logMessage($error);
    
    if ($isManualRun) {
        echo json_encode(['status' => 'error', 'message' => $error]);
    }
    exit;
}

// Include the auto checkout class
require_once dirname(__DIR__) . '/includes/auto_checkout.php';

try {
    $autoCheckout = new AutoCheckout($pdo);
    $result = $autoCheckout->executeDailyCheckout();
    
    // Log the result
    $logMessage = "Auto Checkout Result: " . json_encode($result);
    logMessage($logMessage);
    
    // Output result
    if ($isManualRun) {
        echo json_encode($result);
    } else {
        echo "Auto checkout executed: " . $result['status'] . "\n";
        if (isset($result['checked_out'])) {
            echo "Checked out: " . $result['checked_out'] . " bookings\n";
        }
        if (isset($result['failed'])) {
            echo "Failed: " . $result['failed'] . " bookings\n";
        }
    }
    
} catch (Exception $e) {
    $errorMessage = "Auto Checkout Error: " . $e->getMessage();
    logMessage($errorMessage);
    
    if ($isManualRun) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

logMessage("Auto checkout cron completed");
?>