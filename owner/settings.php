<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

// --- CHANGE FOR MANUAL INSTALL ---
// We no longer check for a 'vendor' folder. We assume the files we uploaded exist.
// This line replaces the old check and prevents the red error box from showing.
define('PHPMAILER_AVAILABLE', true);
// --- END CHANGE ---

$database = new Database();
$pdo = $database->getConnection();

// Handle all form submissions (updates and tests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'change_username':
                $newUsername = sanitize_input($_POST['new_username'] ?? '');
                if (empty($newUsername)) {
                    $error = 'New username is required';
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                        $stmt->execute([$newUsername, $_SESSION['user_id']]);
                        $_SESSION['username'] = $newUsername;
                        redirect_with_message('settings.php', 'Username changed successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to change username - it may already exist.';
                    }
                }
                break;
                
            case 'update_sms':
                $smsApiUrl = sanitize_input($_POST['sms_api_url'] ?? '');
                $smsApiKey = sanitize_input($_POST['sms_api_key'] ?? '');
                $smsSenderId = sanitize_input($_POST['sms_sender_id'] ?? '');
                $hotelName = sanitize_input($_POST['hotel_name'] ?? '');
                if (empty($smsApiUrl) || empty($smsApiKey)) {
                    $error = 'SMS API URL and API Key are required';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
                        $stmt->execute(['sms_api_url', $smsApiUrl, $_SESSION['user_id'], $smsApiUrl, $_SESSION['user_id']]);
                        $stmt->execute(['sms_api_key', $smsApiKey, $_SESSION['user_id'], $smsApiKey, $_SESSION['user_id']]);
                        $stmt->execute(['sms_sender_id', $smsSenderId, $_SESSION['user_id'], $smsSenderId, $_SESSION['user_id']]);
                        $stmt->execute(['hotel_name', $hotelName, $_SESSION['user_id'], $hotelName, $_SESSION['user_id']]);
                        redirect_with_message('settings.php', 'SMS settings updated successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to update SMS settings.';
                    }
                }
                break;
                
            case 'update_email':
                $smtpHost = sanitize_input($_POST['smtp_host'] ?? '');
                $smtpPort = sanitize_input($_POST['smtp_port'] ?? '');
                $smtpUsername = sanitize_input($_POST['smtp_username'] ?? '');
                $smtpPassword = $_POST['smtp_password'] ?? ''; // Do not sanitize password
                $smtpEncryption = $_POST['smtp_encryption'] ?? 'ssl';
                $ownerEmail = sanitize_input($_POST['owner_email'] ?? '');
                if (empty($smtpHost) || empty($smtpUsername)) {
                    $error = 'SMTP Host and Username are required';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
                        $stmt->execute(['smtp_host', $smtpHost, $_SESSION['user_id'], $smtpHost, $_SESSION['user_id']]);
                        $stmt->execute(['smtp_port', $smtpPort, $_SESSION['user_id'], $smtpPort, $_SESSION['user_id']]);
                        $stmt->execute(['smtp_username', $smtpUsername, $_SESSION['user_id'], $smtpUsername, $_SESSION['user_id']]);
                        if (!empty($smtpPassword)) {
                            $stmt->execute(['smtp_password', $smtpPassword, $_SESSION['user_id'], $smtpPassword, $_SESSION['user_id']]);
                        }
                        $stmt->execute(['smtp_encryption', $smtpEncryption, $_SESSION['user_id'], $smtpEncryption, $_SESSION['user_id']]);
                        $stmt->execute(['owner_email', $ownerEmail, $_SESSION['user_id'], $ownerEmail, $_SESSION['user_id']]);
                        redirect_with_message('settings.php', 'Email settings updated successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to update email settings.';
                    }
                }
                break;
                
            case 'test_email':
                $testEmail = sanitize_input($_POST['test_email'] ?? '');
                if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'A valid email address is required for testing';
                } else {
                    try {
                        // Load the email functions file ONLY when it is needed.
                        require_once '../includes/email_functions.php';
                        $result = test_email_configuration($testEmail, $pdo, $_SESSION['user_id']);
                        
                        if ($result['success']) {
                            redirect_with_message('settings.php', 'Test email sent successfully! Check your inbox.', 'success');
                        } else {
                            redirect_with_message('settings.php', 'Email test failed: ' . htmlspecialchars($result['message']), 'error');
                        }
                    } catch (Exception $e) {
                        redirect_with_message('settings.php', 'A critical error occurred: ' . $e->getMessage(), 'error');
                    }
                }
                break;

            case 'update_upi':
                $upiId = sanitize_input($_POST['upi_id'] ?? '');
                if (empty($upiId)) {
                    $error = 'UPI ID is required';
                } else {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_by) VALUES ('upi_id', ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?");
                        $stmt->execute([$upiId, $_SESSION['user_id'], $upiId, $_SESSION['user_id']]);
                        redirect_with_message('settings.php', 'UPI settings updated successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to update UPI settings.';
                    }
                }
                break;
                
            case 'change_password':
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                if (empty($newPassword) || strlen($newPassword) < 6) {
                    $error = 'Password must be at least 6 characters long';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'The new passwords do not match';
                } else {
                    try {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
                        session_destroy();
                        redirect_with_message('../login.php', 'Password changed! Please login again.', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to change password.';
                    }
                }
                break;
                
            case 'update_auto_checkout':
                $autoCheckoutTime = $_POST['auto_checkout_time'] ?? '10:00';
                $autoCheckoutEnabled = isset($_POST['auto_checkout_enabled']) ? '1' : '0';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO system_settings (setting_key, setting_value) 
                        VALUES ('auto_checkout_time', ?), ('auto_checkout_enabled', ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");
                    $stmt->execute([$autoCheckoutTime, $autoCheckoutEnabled]);
                    redirect_with_message('settings.php', 'Auto checkout settings updated successfully!', 'success');
                } catch (Exception $e) {
                    $error = 'Failed to update auto checkout settings.';
                }
                break;
        }
    }
}

// Get all current settings from the database
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$flash = get_flash_message();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="index.php" class="nav-button">← Dashboard</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Owner Panel</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <h2>System Settings</h2>
        
        <!-- Username Change -->
        <div class="form-container">
            <h3>Change Username</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="change_username">
                <div class="form-group">
                    <label for="new_username" class="form-label">New Username *</label>
                    <input type="text" id="new_username" name="new_username" class="form-control" required
                           value="<?= htmlspecialchars($_SESSION['username']) ?>">
                </div>
                <button type="submit" class="btn btn-warning">Change Username</button>
            </form>
        </div>
        
        <!-- SMS Settings -->
        <div class="form-container">
            <h3>SMS Configuration</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_sms">
                <div class="form-group">
                    <label for="hotel_name" class="form-label">Hotel Name *</label>
                    <input type="text" id="hotel_name" name="hotel_name" class="form-control" required
                           value="<?= htmlspecialchars($settings['hotel_name'] ?? 'L.P.S.T Hotel') ?>">
                </div>
                <div class="form-group">
                    <label for="sms_api_url" class="form-label">SMS API URL *</label>
                    <input type="url" id="sms_api_url" name="sms_api_url" class="form-control" required
                           value="<?= htmlspecialchars($settings['sms_api_url'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="sms_api_key" class="form-label">SMS API Key *</label>
                    <input type="text" id="sms_api_key" name="sms_api_key" class="form-control" required
                           value="<?= htmlspecialchars($settings['sms_api_key'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary">Update SMS Settings</button>
            </form>
        </div>
        
        <!-- Email Settings -->
        <div class="form-container">
            <h3>Email Configuration (SMTP)</h3>
            <p>Configure SMTP settings for sending export reports via email.</p>
            
            <!-- The red error box is now removed, as we installed PHPMailer manually -->
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_email">
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="smtp_host" class="form-label">SMTP Host *</label>
                        <input type="text" id="smtp_host" name="smtp_host" class="form-control" required value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="e.g., smtp.hostinger.com">
                    </div>
                    <div class="form-group">
                        <label for="smtp_port" class="form-label">SMTP Port *</label>
                        <input type="number" id="smtp_port" name="smtp_port" class="form-control" required value="<?= htmlspecialchars($settings['smtp_port'] ?? '465') ?>" placeholder="465 (SSL) or 587 (TLS)">
                    </div>
                </div>
                <div class="form-group">
                    <label for="smtp_username" class="form-label">SMTP Username (Email) *</label>
                    <input type="email" id="smtp_username" name="smtp_username" class="form-control" required value="<?= htmlspecialchars($settings['smtp_username'] ?? '') ?>" placeholder="your-email@example.com">
                </div>
                <div class="form-group">
                    <label for="smtp_password" class="form-label">SMTP Password</label>
                    <input type="password" id="smtp_password" name="smtp_password" class="form-control" placeholder="Leave blank to keep current password">
                    <small>Enter your password here to save or update it.</small>
                </div>
                <div class="form-group">
                    <label for="smtp_encryption" class="form-label">Encryption</label>
                    <select id="smtp_encryption" name="smtp_encryption" class="form-control">
                        <option value="ssl" <?= ($settings['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="tls" <?= ($settings['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="owner_email" class="form-label">Owner Email (for reports)</label>
                    <input type="email" id="owner_email" name="owner_email" class="form-control" value="<?= htmlspecialchars($settings['owner_email'] ?? '') ?>" placeholder="owner@example.com">
                </div>
                <button type="submit" class="btn btn-primary">Update Email Settings</button>
            </form>
            
            <!-- Test Email Form -->
            <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eee;">
                <h4>Test Email Configuration</h4>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="action" value="test_email">
                    <div class="form-group">
                        <label for="test_email" class="form-label">Test Email Address</label>
                        <input type="email" id="test_email" name="test_email" class="form-control" required placeholder="test@example.com">
                    </div>
                    <button type="submit" class="btn btn-warning">Send Test Email</button>
                </form>
            </div>
        </div>
        
        <!-- UPI Settings -->
        <div class="form-container">
            <h3>UPI Payment Settings</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_upi">
                <div class="form-group">
                    <label for="upi_id" class="form-label">UPI ID *</label>
                    <input type="text" id="upi_id" name="upi_id" class="form-control" required value="<?= htmlspecialchars($settings['upi_id'] ?? '') ?>" placeholder="yourname@upi">
                </div>
                <button type="submit" class="btn btn-primary">Update UPI Settings</button>
            </form>
        </div>
        
        <!-- Auto Checkout Settings -->
        <div class="form-container">
            <h3>Auto Checkout Configuration</h3>
            <?php
            // Get auto checkout settings
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auto_checkout_enabled', 'auto_checkout_time')");
            $autoSettings = [];
            while ($row = $stmt->fetch()) {
                $autoSettings[$row['setting_key']] = $row['setting_value'];
            }
            $autoEnabled = ($autoSettings['auto_checkout_enabled'] ?? '1') === '1';
            $autoTime = $autoSettings['auto_checkout_time'] ?? '10:00';
            ?>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_auto_checkout">
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="auto_checkout_enabled" <?= $autoEnabled ? 'checked' : '' ?>>
                        Enable Daily Auto Checkout
                    </label>
                    <small style="color: var(--dark-color);">When enabled, all active bookings will be automatically checked out daily</small>
                </div>
                
                <div class="form-group">
                    <label for="auto_checkout_time" class="form-label">Daily Checkout Time</label>
                    <input type="time" id="auto_checkout_time" name="auto_checkout_time" class="form-control" 
                           value="<?= htmlspecialchars($autoTime) ?>" required>
                    <small style="color: var(--dark-color);">Time when auto checkout will run daily (24-hour format)</small>
                </div>
                
                <button type="submit" class="btn btn-primary">Update Auto Checkout Settings</button>
            </form>
            
            <div style="margin-top: 2rem; padding: 1rem; background: rgba(40, 167, 69, 0.1); border-radius: 8px;">
                <h4 style="color: var(--success-color);">Current Status:</h4>
                <p><strong>Auto Checkout:</strong> <?= $autoEnabled ? '✅ ENABLED' : '❌ DISABLED' ?></p>
                <p><strong>Daily Time:</strong> <?= $autoTime ?></p>
                <p><strong>Next Run:</strong> Tomorrow at <?= $autoTime ?></p>
                <a href="../admin/auto_checkout_settings.php" class="btn btn-outline">View Detailed Settings</a>
            </div>
        </div>
        
        <!-- Password Change -->
        <div class="form-container">
            <h3>Change Password</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label for="new_password" class="form-label">New Password *</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6">
                </div>
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                <button type="submit" class="btn btn-danger" onclick="return confirm('You will be logged out. Continue?')">
                    Change Password
                </button>
            </form>
        </div>

    </div>
</body>
</html>