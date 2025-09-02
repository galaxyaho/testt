<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_settings') {
            $autoCheckoutTime = $_POST['auto_checkout_time'] ?? '10:00';
            $autoCheckoutEnabled = isset($_POST['auto_checkout_enabled']) ? '1' : '0';
            
            try {
                // Update or insert settings
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value) 
                    VALUES ('auto_checkout_time', ?), ('auto_checkout_enabled', ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                ");
                $stmt->execute([$autoCheckoutTime, $autoCheckoutEnabled]);
                
                redirect_with_message('auto_checkout_settings.php', 'Auto checkout settings updated successfully!', 'success');
            } catch (Exception $e) {
                $error = 'Failed to update settings: ' . $e->getMessage();
            }
        } elseif ($action === 'test_checkout') {
            try {
                require_once '../includes/auto_checkout.php';
                $autoCheckout = new AutoCheckout($pdo);
                $result = $autoCheckout->executeDailyCheckout();
                
                $message = "Test completed: {$result['status']} - Checked out: " . ($result['checked_out'] ?? 0) . " bookings";
                redirect_with_message('auto_checkout_settings.php', $message, 'success');
            } catch (Exception $e) {
                $error = 'Test failed: ' . $e->getMessage();
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

// Get recent auto checkout logs
$stmt = $pdo->prepare("
    SELECT acl.*, r.display_name, r.custom_name
    FROM auto_checkout_logs acl
    LEFT JOIN resources r ON acl.resource_id = r.id
    ORDER BY acl.created_at DESC
    LIMIT 20
");
$stmt->execute();
$recentLogs = $stmt->fetchAll();

// Get auto checkout statistics
require_once '../includes/auto_checkout.php';
$autoCheckout = new AutoCheckout($pdo);
$stats = $autoCheckout->getCheckoutStats();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Checkout Settings - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .auto-checkout-notice {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
            font-weight: bold;
            box-shadow: 0 4px 15px rgba(0,123,255,0.3);
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            box-shadow: var(--shadow-md);
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="../grid.php" class="nav-button">← Back to Grid</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Auto Checkout Settings</span>
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

        <div class="auto-checkout-notice">
            <h2>🕙 DAILY AUTO CHECKOUT SYSTEM</h2>
            <p>Automatically checkout all active bookings at the configured time daily</p>
            <div style="margin-top: 10px;">
                <span class="status-indicator <?= $autoCheckoutEnabled ? 'status-active' : 'status-inactive' ?>"></span>
                Status: <?= $autoCheckoutEnabled ? 'ACTIVE' : 'DISABLED' ?>
                <?php if ($autoCheckoutEnabled): ?>
                    | Next run: Tomorrow at <?= $autoCheckoutTime ?>
                <?php endif; ?>
            </div>
        </div>

        <h2>Auto Checkout Configuration</h2>
        
        <!-- Settings Form -->
        <div class="form-container">
            <h3>System Settings</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="auto_checkout_enabled" <?= $autoCheckoutEnabled ? 'checked' : '' ?>>
                        Enable Daily Auto Checkout
                    </label>
                    <small style="color: var(--dark-color);">When enabled, all active bookings will be automatically checked out daily</small>
                </div>
                
                <div class="form-group">
                    <label for="auto_checkout_time" class="form-label">Daily Checkout Time</label>
                    <input type="time" id="auto_checkout_time" name="auto_checkout_time" class="form-control" 
                           value="<?= htmlspecialchars($autoCheckoutTime) ?>" required>
                    <small style="color: var(--dark-color);">Time when auto checkout will run daily (24-hour format)</small>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">Update Settings</button>
                    <button type="submit" name="action" value="test_checkout" class="btn btn-warning" 
                            onclick="return confirm('This will test the auto checkout system now. Continue?')">
                        🧪 Test Auto Checkout
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Statistics -->
        <div class="form-container">
            <h3>Auto Checkout Statistics</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <h4>Today's Auto Checkouts</h4>
                    <div class="dashboard-value"><?= $stats['today']['count'] ?></div>
                    <p>Revenue: <?= format_currency($stats['today']['amount']) ?></p>
                </div>
                
                <div class="stat-card">
                    <h4>This Week's Auto Checkouts</h4>
                    <div class="dashboard-value"><?= $stats['week']['count'] ?></div>
                    <p>Revenue: <?= format_currency($stats['week']['amount']) ?></p>
                </div>
                
                <div class="stat-card">
                    <h4>System Status</h4>
                    <div class="dashboard-value" style="color: <?= $autoCheckoutEnabled ? 'var(--success-color)' : 'var(--danger-color)' ?>">
                        <?= $autoCheckoutEnabled ? 'ACTIVE' : 'DISABLED' ?>
                    </div>
                    <p>Current Time: <?= date('H:i') ?></p>
                </div>
                
                <div class="stat-card">
                    <h4>Last Auto Run</h4>
                    <div class="dashboard-value" style="font-size: 1rem;">
                        <?= $lastRun ? date('M j, H:i', strtotime($lastRun)) : 'Never' ?>
                    </div>
                    <p>Next: Tomorrow <?= $autoCheckoutTime ?></p>
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
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Date & Time</th>
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
                                    No auto checkout logs found. The system will create logs when auto checkout runs.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= date('M j, Y H:i', strtotime($log['created_at'])) ?>
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
                                        <?= htmlspecialchars($log['notes']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Setup Instructions -->
        <div class="form-container">
            <h3>🔧 Cron Job Setup Instructions for Hostinger</h3>
            <div style="background: rgba(37, 99, 235, 0.1); padding: 1.5rem; border-radius: 8px;">
                <h4>Step 1: Access Hostinger Control Panel</h4>
                <p>1. Login to your Hostinger account</p>
                <p>2. Go to your hosting control panel</p>
                <p>3. Find "Cron Jobs" in the Advanced section</p>
                
                <h4>Step 2: Create New Cron Job</h4>
                <p><strong>Command to run:</strong></p>
                <code style="background: white; padding: 0.5rem; border-radius: 4px; display: block; margin: 0.5rem 0;">
                    /usr/bin/php /home/u261459251/domains/soft.galaxytribes.in/public_html/cron/auto_checkout_cron.php
                </code>
                
                <p><strong>Schedule (run every 5 minutes):</strong></p>
                <code style="background: white; padding: 0.5rem; border-radius: 4px; display: block; margin: 0.5rem 0;">
                    */5 * * * *
                </code>
                
                <h4>Step 3: Test the Setup</h4>
                <p>Use the "Test Auto Checkout" button above to verify everything works correctly.</p>
                
                <div style="background: rgba(255, 193, 7, 0.1); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
                    <strong>⚠️ Important Notes:</strong>
                    <ul>
                        <li>The cron job will run every 5 minutes but only execute checkout at the configured time</li>
                        <li>Auto checkout will only run once per day at the specified time</li>
                        <li>All active bookings will be automatically checked out and payment calculated</li>
                        <li>SMS notifications will be sent to guests (if SMS is configured)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>