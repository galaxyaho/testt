<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Get all resources for selection
$stmt = $pdo->prepare("SELECT * FROM resources WHERE is_active = 1 ORDER BY type, CAST(identifier AS UNSIGNED), identifier");
$stmt->execute();
$resources = $stmt->fetchAll();

// Get all advanced bookings
$stmt = $pdo->prepare("
    SELECT b.*, r.display_name, r.type, r.identifier 
    FROM bookings b 
    JOIN resources r ON b.resource_id = r.id 
    WHERE b.booking_type = 'advanced' AND b.status = 'ADVANCED_BOOKED'
    ORDER BY b.advance_date ASC
");
$stmt->execute();
$advancedBookings = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $resourceId = $_POST['resource_id'] ?? '';
        $advanceDate = $_POST['advance_date'] ?? '';
        $clientName = sanitize_input($_POST['client_name'] ?? '');
        $clientMobile = sanitize_input($_POST['client_mobile'] ?? '');
        $clientAadhar = sanitize_input($_POST['client_aadhar'] ?? '');
        $clientLicense = sanitize_input($_POST['client_license'] ?? '');
        $receiptNumber = sanitize_input($_POST['receipt_number'] ?? '');
        $advancePaymentMode = $_POST['advance_payment_mode'] ?? 'OFFLINE';
        
        if (empty($resourceId) || empty($advanceDate) || empty($clientName) || empty($clientMobile)) {
            $error = 'Resource, date, name and mobile number are required';
        } elseif (!preg_match('/^[6-9]\d{9}$/', $clientMobile)) {
            $error = 'Mobile number must be 10 digits starting with 6-9';
        } elseif (!empty($clientAadhar) && !preg_match('/^\d{12}$/', $clientAadhar)) {
            $error = 'Aadhar number must be 12 digits';
        } elseif (!empty($clientLicense) && strlen($clientLicense) < 8) {
            $error = 'License number must be at least 8 characters';
        } elseif (strtotime($advanceDate) <= strtotime('today')) {
            $error = 'Advance date must be in the future';
        } else {
            // Check if resource already has advance booking for this date
            $stmt = $pdo->prepare("
                SELECT id FROM bookings 
                WHERE resource_id = ? AND advance_date = ? AND status = 'ADVANCED_BOOKED'
            ");
            $stmt->execute([$resourceId, $advanceDate]);
            
            if ($stmt->fetch()) {
                $error = 'Resource already has an advance booking for this date';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO bookings (resource_id, client_name, client_mobile, client_aadhar, client_license, receipt_number, advance_payment_mode, advance_date, admin_id, status, booking_type, check_in, check_out) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ADVANCED_BOOKED', 'advanced', ?, ?)
                    ");
                    // Set dummy check-in/out for advance bookings
                    $dummyTime = $advanceDate . ' 12:00:00';
                    $stmt->execute([$resourceId, $clientName, $clientMobile, $clientAadhar ?: null, $clientLicense ?: null, $receiptNumber ?: null, $advancePaymentMode, $advanceDate, $_SESSION['user_id'], $dummyTime, $dummyTime]);
                    
                    $bookingId = $pdo->lastInsertId();
                    
                    // Send SMS notification
                    require_once 'includes/sms_functions.php';
                    $sms_result = send_advance_booking_sms($bookingId, $pdo);
                    
                    $message = 'Advanced booking created successfully!';
                    if (!$sms_result['success']) {
                        $message .= ' (SMS failed: ' . $sms_result['message'] . ')';
                    }
                    
                    redirect_with_message('advanced.php', $message, 'success');
                } catch (Exception $e) {
                    $error = 'Failed to create advance booking';
                }
            }
        }
    }
}

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Bookings - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="grid.php" class="nav-button">← Back to Grid</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <a href="logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <h2>Advanced Bookings</h2>
        
        <div class="form-container">
            <h3>Create New Advanced Booking</h3>
            
            <?php if (isset($error)): ?>
                <div class="flash-message flash-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <div class="form-group">
                    <label for="resource_id" class="form-label">Select Room/Hall *</label>
                    <select id="resource_id" name="resource_id" class="form-control" required>
                        <option value="">Choose a resource...</option>
                        <?php foreach ($resources as $resource): ?>
                            <option value="<?= $resource['id'] ?>" 
                                    <?= (isset($_POST['resource_id']) && $_POST['resource_id'] == $resource['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($resource['custom_name'] ?: $resource['display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="advance_date" class="form-label">Advance Date *</label>
                    <input type="date" id="advance_date" name="advance_date" class="form-control" 
                           min="<?= date('Y-m-d', strtotime('tomorrow', time())) ?>" required
                           value="<?= isset($_POST['advance_date']) ? $_POST['advance_date'] : '' ?>">
                </div>
                
                <div class="form-group">
                    <label for="client_name" class="form-label">Client Name *</label>
                    <input type="text" id="client_name" name="client_name" class="form-control" required
                           value="<?= isset($_POST['client_name']) ? htmlspecialchars($_POST['client_name']) : '' ?>">
                </div>
                
                <div class="form-group">
                    <label for="client_mobile" class="form-label">Mobile Number *</label>
                    <input type="tel" id="client_mobile" name="client_mobile" class="form-control" required
                           pattern="[6-9][0-9]{9}" maxlength="10"
                           placeholder="10 digit mobile number"
                           value="<?= isset($_POST['client_mobile']) ? htmlspecialchars($_POST['client_mobile']) : '' ?>">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="client_aadhar" class="form-label">Aadhar Number (Optional)</label>
                        <input type="text" id="client_aadhar" name="client_aadhar" class="form-control"
                               pattern="[0-9]{12}" maxlength="12"
                               placeholder="12 digit Aadhar number"
                               value="<?= isset($_POST['client_aadhar']) ? htmlspecialchars($_POST['client_aadhar']) : '' ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="client_license" class="form-label">Driving License (Optional)</label>
                        <input type="text" id="client_license" name="client_license" class="form-control"
                               placeholder="License number"
                               value="<?= isset($_POST['client_license']) ? htmlspecialchars($_POST['client_license']) : '' ?>">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="receipt_number" class="form-label">Receipt Number (Optional)</label>
                        <input type="text" id="receipt_number" name="receipt_number" class="form-control"
                               placeholder="Receipt/Invoice number"
                               value="<?= isset($_POST['receipt_number']) ? htmlspecialchars($_POST['receipt_number']) : '' ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="advance_payment_mode" class="form-label">Advance Payment Mode</label>
                        <select id="advance_payment_mode" name="advance_payment_mode" class="form-control">
                            <option value="OFFLINE" <?= (isset($_POST['advance_payment_mode']) && $_POST['advance_payment_mode'] === 'OFFLINE') ? 'selected' : '' ?>>Offline (Cash/Card)</option>
                            <option value="ONLINE" <?= (isset($_POST['advance_payment_mode']) && $_POST['advance_payment_mode'] === 'ONLINE') ? 'selected' : '' ?>>Online (UPI/Net Banking)</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Create Advanced Booking</button>
            </form>
        </div>
        
        <?php if (!empty($advancedBookings)): ?>
            <div class="advanced-section">
                <h3>Current Advanced Bookings</h3>
                <div class="advanced-grid">
                    <?php foreach ($advancedBookings as $booking): ?>
                        <div class="advanced-box">
                            <div class="advanced-room-number" style="font-size: 2rem; margin-bottom: 0.5rem;">
                                <?php 
                                // Show custom name if available, otherwise show display name
                                echo htmlspecialchars($booking['custom_name'] ?: $booking['display_name']);
                                ?>
                            </div>
                            <div style="font-size: 0.9rem; margin-bottom: 0.5rem; opacity: 0.8;">
                                <?= htmlspecialchars($booking['custom_name'] ? $booking['display_name'] : '') ?>
                            </div>
                            <strong>Date:</strong> <?= date('M j, Y', strtotime($booking['advance_date'])) ?><br>
                            <strong>Client:</strong> <?= htmlspecialchars($booking['client_name']) ?><br>
                            <strong>Mobile:</strong> <?= htmlspecialchars($booking['client_mobile']) ?><br>
                            <strong>Status:</strong> 
                            <span class="status-badge status-advanced">ADVANCED BOOKED</span><br>
                            
                            <?php if ($booking['advance_date'] === date('Y-m-d')): ?>
                                <div style="margin-top: 0.5rem;">
                                    <span style="background: var(--warning-color); color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.7rem;">
                                        DUE TODAY
                                    </span>
                                    <a href="booking_advance_convert.php?resource_id=<?= $booking['resource_id'] ?>" 
                                       class="btn btn-success" style="margin-top: 0.5rem; font-size: 0.8rem;">
                                        Convert to Active
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-format mobile number
        document.getElementById('client_mobile').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 10) value = value.slice(0, 10);
            e.target.value = value;
        });
        
        // Auto-format Aadhar number
        document.getElementById('client_aadhar').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 12) value = value.slice(0, 12);
            e.target.value = value;
        });
    </script>
    <script src="assets/script.js"></script>
</body>
</html>