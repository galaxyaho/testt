<?php
/**
 * Enhanced Test Auto Checkout System
 * Allows admin/owner to test auto checkout functionality anytime
 */

require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Handle manual test execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_test'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        try {
            require_once '../includes/auto_checkout.php';
            $autoCheckout = new AutoCheckout($pdo);
            $result = $autoCheckout->testAutoCheckout();
            
            $testResult = $result;
        } catch (Exception $e) {
            $error = 'Test failed: ' . $e->getMessage();
        }
    }
}

// Get current system status
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auto_checkout_enabled', 'auto_checkout_time', 'last_auto_checkout_run')");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$autoEnabled = ($settings['auto_checkout_enabled'] ?? '1') === '1';
$autoTime = $settings['auto_checkout_time'] ?? '10:00';
$lastRun = $settings['last_auto_checkout_run'] ?? '';

// Get active bookings count
$stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('BOOKED', 'PENDING')");
$activeBookingsCount = $stmt->fetchColumn();

// Get recent auto checkout logs
$stmt = $pdo->prepare("
    SELECT acl.*, r.display_name, r.custom_name
    FROM auto_checkout_logs acl
    LEFT JOIN resources r ON acl.resource_id = r.id
    ORDER BY acl.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recentLogs = $stmt->fetchAll();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Auto Checkout - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .test-result {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-active { background-color: #28a745; }
        .status-inactive { background-color: #dc3545; }
        .system-info {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin: 1rem 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="../grid.php" class="nav-button">← Back to Grid</a>
            <a href="auto_checkout_settings.php" class="nav-button">Settings</a>
            <a href="auto_checkout_logs.php" class="nav-button">View Logs</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Test Auto Checkout</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <h2>🧪 Test Auto Checkout System</h2>
        
        <!-- System Status -->
        <div class="system-info">
            <h3>🕙 Auto Checkout System Status</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <span class="status-indicator <?= $autoEnabled ? 'status-active' : 'status-inactive' ?>"></span>
                    <strong>Status:</strong> <?= $autoEnabled ? 'ENABLED' : 'DISABLED' ?>
                </div>
                <div>
                    <strong>Daily Time:</strong> <?= $autoTime ?>
                </div>
                <div>
                    <strong>Current Time:</strong> <?= date('H:i') ?>
                </div>
                <div>
                    <strong>Active Bookings:</strong> <?= $activeBookingsCount ?>
                </div>
            </div>
            <?php if ($lastRun): ?>
                <p style="margin-top: 1rem; opacity: 0.9;">
                    <strong>Last Auto Run:</strong> <?= date('M j, Y H:i', strtotime($lastRun)) ?>
                </p>
            <?php endif; ?>
        </div>
        
        <!-- Test Controls -->
        <div class="form-container">
            <h3>Manual Test Controls</h3>
            <p>Use these controls to test the auto checkout system anytime, regardless of the scheduled time.</p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div style="display: flex; gap: 1rem; align-items: center; margin: 1rem 0;">
                    <button type="submit" name="run_test" class="btn btn-primary" style="font-size: 1.1rem; padding: 1rem 2rem;">
                        🧪 Run Auto Checkout Test Now
                    </button>
                    <span style="color: var(--dark-color);">
                        This will process all active bookings immediately
                    </span>
                </div>
            </form>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 2rem;">
                <a href="../cron/auto_checkout_cron.php?manual_run=1" target="_blank" class="btn btn-warning">
                    🔗 Test Cron Script Directly
                </a>
                <a href="auto_checkout_settings.php" class="btn btn-outline">
                    ⚙️ Configure Settings
                </a>
                <a href="auto_checkout_logs.php" class="btn btn-outline">
                    📋 View All Logs
                </a>
            </div>
        </div>
        
        <!-- Test Results -->
        <?php if (isset($testResult)): ?>
            <div class="form-container">
                <h3>Test Results</h3>
                <div class="test-result">
                    <strong>Status:</strong> <?= $testResult['status'] ?>
                    <strong>Timestamp:</strong> <?= $testResult['timestamp'] ?? date('Y-m-d H:i:s') ?>
                    <strong>Run Type:</strong> <?= $testResult['run_type'] ?? 'manual' ?>
                    
                    <?php if (isset($testResult['checked_out'])): ?>
                    <strong>Bookings Checked Out:</strong> <?= $testResult['checked_out'] ?>
                    <?php endif; ?>
                    
                    <?php if (isset($testResult['failed'])): ?>
                    <strong>Failed Checkouts:</strong> <?= $testResult['failed'] ?>
                    <?php endif; ?>
                    
                    <?php if (isset($testResult['total_processed'])): ?>
                    <strong>Total Processed:</strong> <?= $testResult['total_processed'] ?>
                    <?php endif; ?>
                    
                    <?php if (isset($testResult['message'])): ?>
                    <strong>Message:</strong> <?= $testResult['message'] ?>
                    <?php endif; ?>
                    
                    <?php if (isset($testResult['details']['successful']) && !empty($testResult['details']['successful'])): ?>
                    
                    <strong>Successfully Checked Out:</strong>
                    <?php foreach ($testResult['details']['successful'] as $booking): ?>
                    - <?= $booking['custom_name'] ?: $booking['display_name'] ?>: <?= $booking['client_name'] ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if (isset($testResult['details']['failed']) && !empty($testResult['details']['failed'])): ?>
                    
                    <strong>Failed Checkouts:</strong>
                    <?php foreach ($testResult['details']['failed'] as $failed): ?>
                    - <?= $failed['booking']['custom_name'] ?: $failed['booking']['display_name'] ?>: <?= $failed['booking']['client_name'] ?> (Error: <?= $failed['error'] ?>)
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- System Information -->
        <div class="form-container">
            <h3>System Information</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>Current Configuration:</h4>
                <ul>
                    <li><strong>Auto Checkout:</strong> <?= $autoEnabled ? '✅ Enabled' : '❌ Disabled' ?></li>
                    <li><strong>Daily Time:</strong> <?= $autoTime ?> (Asia/Kolkata timezone)</li>
                    <li><strong>Active Bookings:</strong> <?= $activeBookingsCount ?> bookings ready for checkout</li>
                    <li><strong>Cron Job:</strong> Should run every 5 minutes or at 10:00 AM daily</li>
                    <li><strong>Manual Testing:</strong> Available anytime for admin/owner</li>
                </ul>
                
                <h4>How It Works:</h4>
                <ol>
                    <li>Cron job runs every 5 minutes (or at 10:00 AM)</li>
                    <li>System checks if it's time for auto checkout (10:00 AM by default)</li>
                    <li>All active bookings are automatically checked out</li>
                    <li>Payment records are created automatically</li>
                    <li>SMS notifications are sent to guests</li>
                    <li>Detailed logs are maintained for tracking</li>
                </ol>
                
                <h4>Cron Job Command (from your Hostinger setup):</h4>
                <code style="background: white; padding: 0.5rem; border-radius: 4px; display: block; margin: 0.5rem 0;">
                    0 10 * * * /usr/bin/php /home/u261459251/domains/lpstnashik.in/public_html/cron/auto_checkout_cron.php
                </code>
                <p><small>This runs daily at 10:00 AM. You can also use */5 * * * * for every 5 minutes.</small></p>
            </div>
        </div>
        
        <!-- Recent Logs -->
        <div class="form-container">
            <h3>Recent Auto Checkout Logs</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Time</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Guest</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Status</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentLogs)): ?>
                            <tr>
                                <td colspan="5" style="padding: 2rem; text-align: center; color: var(--dark-color);">
                                    <div style="text-align: center;">
                                        <div style="font-size: 3rem; margin-bottom: 1rem;">🕙</div>
                                        <h4>No auto checkout logs found</h4>
                                        <p>Run a test to see logs appear here</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= date('M j, H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= htmlspecialchars($log['custom_name'] ?: $log['display_name'] ?: $log['resource_name']) ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= htmlspecialchars($log['guest_name']) ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <span style="color: <?= $log['status'] === 'success' ? 'var(--success-color)' : 'var(--danger-color)' ?>; font-weight: 600;">
                                            <?= $log['status'] === 'success' ? '✅ SUCCESS' : '❌ FAILED' ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <small><?= htmlspecialchars($log['notes']) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Auto refresh every 30 seconds when testing
        let autoRefresh = false;
        
        function toggleAutoRefresh() {
            autoRefresh = !autoRefresh;
            const button = document.getElementById('refreshToggle');
            
            if (autoRefresh) {
                button.textContent = '⏸️ Stop Auto Refresh';
                button.className = 'btn btn-warning';
                setTimeout(refreshPage, 30000);
            } else {
                button.textContent = '🔄 Enable Auto Refresh';
                button.className = 'btn btn-outline';
            }
        }
        
        function refreshPage() {
            if (autoRefresh) {
                location.reload();
            }
        }
        
        // Show current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-IN', {
                timeZone: 'Asia/Kolkata',
                hour12: false
            });
            document.getElementById('currentTime').textContent = timeString;
        }
        
        // Update time every second
        setInterval(updateTime, 1000);
        updateTime();
    </script>
    
    <div style="position: fixed; bottom: 20px; right: 20px;">
        <button id="refreshToggle" onclick="toggleAutoRefresh()" class="btn btn-outline">
            🔄 Enable Auto Refresh
        </button>
    </div>
    
    <div style="position: fixed; bottom: 20px; left: 20px; background: white; padding: 0.5rem 1rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <strong>Current Time:</strong> <span id="currentTime"></span>
    </div>
</body>
</html>