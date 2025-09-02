<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Handle manual checkout test
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'test_checkout') {
            try {
                require_once '../includes/auto_checkout.php';
                $autoCheckout = new AutoCheckout($pdo);
                $result = $autoCheckout->testAutoCheckout();
                
                $testResult = $result;
            } catch (Exception $e) {
                $error = 'Test failed: ' . $e->getMessage();
            }
        } elseif ($action === 'force_checkout') {
            try {
                require_once '../includes/auto_checkout.php';
                $autoCheckout = new AutoCheckout($pdo);
                $result = $autoCheckout->forceCheckoutAll();
                
                $testResult = $result;
            } catch (Exception $e) {
                $error = 'Force checkout failed: ' . $e->getMessage();
            }
        } elseif ($action === 'update_time') {
            $newTime = $_POST['checkout_time'] ?? '10:00';
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value) 
                    VALUES ('auto_checkout_time', ?)
                    ON DUPLICATE KEY UPDATE setting_value = ?
                ");
                $stmt->execute([$newTime, $newTime]);
                
                redirect_with_message('manual_checkout_test.php', 'Auto checkout time updated to ' . $newTime, 'success');
            } catch (Exception $e) {
                $error = 'Failed to update time: ' . $e->getMessage();
            }
        }
    }
}

// Get current settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$autoCheckoutTime = $settings['auto_checkout_time'] ?? '10:00';
$autoCheckoutEnabled = ($settings['auto_checkout_enabled'] ?? '1') === '1';
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
    <title>Manual Auto Checkout Test - L.P.S.T Bookings</title>
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
            max-height: 400px;
            overflow-y: auto;
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
        .urgent-notice {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            text-align: center;
            font-weight: bold;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
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
            <span style="margin-right: 1rem;">Manual Auto Checkout Test</span>
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

        <div class="urgent-notice">
            🚨 MANUAL AUTO CHECKOUT TESTING - NO 24 HOUR WAIT REQUIRED
            <br><small>Test auto checkout functionality immediately without waiting for scheduled time</small>
        </div>

        <h2>🧪 Manual Auto Checkout Test</h2>
        
        <!-- System Status -->
        <div class="system-info">
            <h3>🕙 Auto Checkout System Status</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <span class="status-indicator <?= $autoCheckoutEnabled ? 'status-active' : 'status-inactive' ?>"></span>
                    <strong>Status:</strong> <?= $autoCheckoutEnabled ? 'ENABLED' : 'DISABLED' ?>
                </div>
                <div>
                    <strong>Daily Time:</strong> <?= $autoCheckoutTime ?>
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
        
        <!-- Quick Time Update -->
        <div class="form-container">
            <h3>⚡ Quick Time Update (For Testing)</h3>
            <p style="color: var(--warning-color); font-weight: 600;">
                Set auto checkout time to current time + 1 minute for immediate testing
            </p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_time">
                
                <div style="display: flex; gap: 1rem; align-items: end;">
                    <div class="form-group" style="margin: 0;">
                        <label for="checkout_time" class="form-label">Auto Checkout Time</label>
                        <input type="time" id="checkout_time" name="checkout_time" class="form-control" 
                               value="<?= date('H:i', strtotime('+1 minute')) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-warning">
                        ⏰ Update Time
                    </button>
                </div>
                
                <small style="color: var(--dark-color);">
                    Current setting: <?= $autoCheckoutTime ?> | Suggested: <?= date('H:i', strtotime('+1 minute')) ?> (1 minute from now)
                </small>
            </form>
        </div>
        
        <!-- Test Controls -->
        <div class="form-container">
            <h3>Manual Test Controls</h3>
            <p>Use these controls to test the auto checkout system anytime, regardless of the scheduled time.</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="test_checkout">
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">
                        🧪 Test Auto Checkout Now
                    </button>
                    <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                        Respects time settings and runs normal logic
                    </small>
                </form>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="force_checkout">
                    <button type="submit" class="btn btn-danger" style="width: 100%; padding: 1rem;"
                            onclick="return confirm('This will force checkout ALL active bookings immediately. Continue?')">
                        🚨 Force Checkout All
                    </button>
                    <small style="display: block; margin-top: 0.5rem; color: var(--dark-color);">
                        Ignores time settings, processes all bookings
                    </small>
                </form>
            </div>
            
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
                <a href="../logs/" target="_blank" class="btn btn-outline">
                    📄 View Log Files
                </a>
            </div>
        </div>
        
        <!-- Test Results -->
        <?php if (isset($testResult)): ?>
            <div class="form-container">
                <h3>Test Results</h3>
                <div class="test-result">
<?= json_encode($testResult, JSON_PRETTY_PRINT) ?>
                </div>
                
                <?php if ($testResult['status'] === 'completed' && isset($testResult['details']['successful'])): ?>
                    <div style="background: rgba(16, 185, 129, 0.1); padding: 1rem; border-radius: 8px; margin-top: 1rem;">
                        <h4 style="color: var(--success-color);">✅ Successfully Checked Out:</h4>
                        <?php foreach ($testResult['details']['successful'] as $booking): ?>
                            <p>- <?= htmlspecialchars($booking['custom_name'] ?: $booking['display_name']) ?>: <?= htmlspecialchars($booking['client_name']) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($testResult['status'] === 'completed' && isset($testResult['details']['failed']) && !empty($testResult['details']['failed'])): ?>
                    <div style="background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 8px; margin-top: 1rem;">
                        <h4 style="color: var(--danger-color);">❌ Failed Checkouts:</h4>
                        <?php foreach ($testResult['details']['failed'] as $failed): ?>
                            <p>- <?= htmlspecialchars($failed['booking']['custom_name'] ?: $failed['booking']['display_name']) ?>: <?= htmlspecialchars($failed['booking']['client_name']) ?> (Error: <?= htmlspecialchars($failed['error']) ?>)</p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- System Information -->
        <div class="form-container">
            <h3>System Information & Troubleshooting</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>Current Configuration:</h4>
                <ul>
                    <li><strong>Auto Checkout:</strong> <?= $autoCheckoutEnabled ? '✅ Enabled' : '❌ Disabled' ?></li>
                    <li><strong>Daily Time:</strong> <?= $autoCheckoutTime ?> (Asia/Kolkata timezone)</li>
                    <li><strong>Current Server Time:</strong> <?= date('Y-m-d H:i:s') ?></li>
                    <li><strong>Active Bookings:</strong> <?= $activeBookingsCount ?> bookings ready for checkout</li>
                    <li><strong>Cron Job:</strong> Should run every 5 minutes or at <?= $autoCheckoutTime ?> daily</li>
                    <li><strong>Manual Testing:</strong> Available anytime for admin/owner</li>
                </ul>
                
                <h4>How It Works:</h4>
                <ol>
                    <li>Cron job runs every 5 minutes (or at <?= $autoCheckoutTime ?>)</li>
                    <li>System checks if it's time for auto checkout (<?= $autoCheckoutTime ?> by default)</li>
                    <li>All active bookings are automatically checked out</li>
                    <li>Payment records are created automatically</li>
                    <li>SMS notifications are sent to guests</li>
                    <li>Detailed logs are maintained for tracking</li>
                </ol>
                
                <h4>Hostinger Cron Job Commands:</h4>
                <div style="background: white; padding: 1rem; border-radius: 4px; margin: 0.5rem 0;">
                    <p><strong>Option 1 (Recommended):</strong> Run every 5 minutes</p>
                    <code style="display: block; background: #f8f9fa; padding: 0.5rem; border-radius: 4px;">
                        */5 * * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php
                    </code>
                </div>
                
                <div style="background: white; padding: 1rem; border-radius: 4px; margin: 0.5rem 0;">
                    <p><strong>Option 2:</strong> Run exactly at <?= $autoCheckoutTime ?> daily</p>
                    <code style="display: block; background: #f8f9fa; padding: 0.5rem; border-radius: 4px;">
                        0 10 * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php
                    </code>
                </div>
                
                <div style="background: white; padding: 1rem; border-radius: 4px; margin: 0.5rem 0;">
                    <p><strong>Option 3 (Testing):</strong> Run every minute</p>
                    <code style="display: block; background: #f8f9fa; padding: 0.5rem; border-radius: 4px;">
                        * * * * * /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php
                    </code>
                </div>
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
        
        <!-- Live System Monitor -->
        <div class="form-container">
            <h3>🔴 Live System Monitor</h3>
            <div id="systemMonitor" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; font-family: monospace;">
                <div>Current Time: <span id="currentTime"></span></div>
                <div>Next Auto Checkout: Tomorrow at <?= $autoCheckoutTime ?></div>
                <div>Active Bookings: <?= $activeBookingsCount ?></div>
                <div>System Status: <span style="color: <?= $autoCheckoutEnabled ? 'green' : 'red' ?>;"><?= $autoCheckoutEnabled ? 'ACTIVE' : 'DISABLED' ?></span></div>
            </div>
            
            <div style="margin-top: 1rem;">
                <button id="refreshMonitor" onclick="refreshSystemStatus()" class="btn btn-outline">
                    🔄 Refresh Status
                </button>
                <button id="autoRefreshToggle" onclick="toggleAutoRefresh()" class="btn btn-outline">
                    ⏸️ Enable Auto Refresh
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let autoRefreshInterval = null;
        
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-IN', {
                timeZone: 'Asia/Kolkata',
                hour12: false
            });
            document.getElementById('currentTime').textContent = timeString;
        }
        
        function refreshSystemStatus() {
            fetch('../api/auto_checkout_status.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('System status updated:', data);
                        // Update display if needed
                    }
                })
                .catch(error => console.error('Status refresh error:', error));
        }
        
        function toggleAutoRefresh() {
            const button = document.getElementById('autoRefreshToggle');
            
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                button.textContent = '▶️ Enable Auto Refresh';
                button.className = 'btn btn-outline';
            } else {
                autoRefreshInterval = setInterval(() => {
                    refreshSystemStatus();
                    location.reload();
                }, 30000); // Refresh every 30 seconds
                button.textContent = '⏸️ Disable Auto Refresh';
                button.className = 'btn btn-warning';
            }
        }
        
        // Update time every second
        setInterval(updateTime, 1000);
        updateTime();
        
        // Auto refresh status every 30 seconds
        setInterval(refreshSystemStatus, 30000);
    </script>
</body>
</html>