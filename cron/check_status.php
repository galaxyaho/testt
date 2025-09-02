<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

// This file can be run via cron job every 5 minutes for automatic status updates
// Example cron: */5 * * * * /usr/bin/php /path/to/cron/check_status.php

$database = new Database();
$pdo = $database->getConnection();

try {
    // Update pending bookings
    update_pending_bookings($pdo);
    
    // Log the execution
    error_log("[" . date('Y-m-d H:i:s') . "] LPST Bookings: Status check completed");
    
    echo "Status check completed successfully\n";
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] LPST Bookings: Status check error - " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
?>