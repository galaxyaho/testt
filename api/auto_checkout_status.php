<?php
/**
 * Auto Checkout Status API
 * Returns current auto checkout system status
 */

require_once '../includes/functions.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

try {
    // Get auto checkout settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auto_checkout_enabled', 'auto_checkout_time', 'last_auto_checkout_run')");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $enabled = ($settings['auto_checkout_enabled'] ?? '1') === '1';
    $time = $settings['auto_checkout_time'] ?? '10:00';
    $lastRun = $settings['last_auto_checkout_run'] ?? '';
    
    // Get active bookings count
    $stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('BOOKED', 'PENDING')");
    $activeBookings = $stmt->fetchColumn();
    
    // Get today's auto checkout count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM auto_checkout_logs WHERE DATE(created_at) = CURDATE() AND status = 'success'");
    $stmt->execute();
    $todayCheckouts = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'enabled' => $enabled,
        'time' => $time,
        'last_run' => $lastRun,
        'active_bookings' => $activeBookings,
        'today_checkouts' => $todayCheckouts,
        'current_time' => date('H:i'),
        'next_run' => $enabled ? "Tomorrow at $time" : 'Disabled',
        'timezone' => 'Asia/Kolkata'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>