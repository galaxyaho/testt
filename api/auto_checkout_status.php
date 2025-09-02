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
    
    // Check if cron is working (last run should be recent)
    $cronWorking = false;
    if ($lastRun) {
        $lastRunTime = strtotime($lastRun);
        $now = time();
        $timeDiff = $now - $lastRunTime;
        $cronWorking = $timeDiff < (24 * 60 * 60); // Less than 24 hours ago
    }
    
    echo json_encode([
        'success' => true,
        'enabled' => $enabled,
        'time' => $time,
        'last_run' => $lastRun,
        'active_bookings' => $activeBookings,
        'today_checkouts' => $todayCheckouts,
        'current_time' => date('H:i'),
        'current_datetime' => date('Y-m-d H:i:s'),
        'next_run' => $enabled ? "Tomorrow at $time" : 'Disabled',
        'timezone' => 'Asia/Kolkata',
        'cron_working' => $cronWorking,
        'time_until_next' => $enabled ? $this->getTimeUntilNext($time) : null
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function getTimeUntilNext($checkoutTime) {
    $now = new DateTime();
    $checkout = new DateTime();
    
    list($hours, $minutes) = explode(':', $checkoutTime);
    $checkout->setTime($hours, $minutes, 0);
    
    // If checkout time has passed today, set for tomorrow
    if ($checkout <= $now) {
        $checkout->add(new DateInterval('P1D'));
    }
    
    $diff = $now->diff($checkout);
    return [
        'hours' => $diff->h + ($diff->days * 24),
        'minutes' => $diff->i,
        'formatted' => $diff->format('%h hours %i minutes')
    ];
}
?>