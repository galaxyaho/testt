<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

$resourceId = $_GET['id'] ?? '';
$resourceType = $_GET['type'] ?? '';

// Get resource details
$stmt = $pdo->prepare("SELECT * FROM resources WHERE id = ? AND is_active = 1");
$stmt->execute([$resourceId]);
$resource = $stmt->fetch();

if (!$resource) {
    redirect_with_message('grid.php', 'Resource not found', 'error');
}

// Check if resource is available
$existing = get_resource_status($resourceId, $pdo);
if ($existing) {
    redirect_with_message('grid.php', 'Resource is not available for booking', 'error');
}

// Check if resource has advanced booking
$hasAdvanced = has_advanced_booking($resourceId, $pdo);
if ($hasAdvanced) {
    redirect_with_message('grid.php', 'Resource has an advanced booking and cannot be booked', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $clientName = sanitize_input($_POST['client_name'] ?? '');
        $clientMobile = sanitize_input($_POST['client_mobile'] ?? '');
        $clientAadhar = sanitize_input($_POST['client_aadhar'] ?? '');
        $clientLicense = sanitize_input($_POST['client_license'] ?? '');
        $receiptNumber = sanitize_input($_POST['receipt_number'] ?? '');
        $paymentMode = $_POST['payment_mode'] ?? 'OFFLINE';
        $checkin = $_POST['check_in'] ?? '';
        $checkout = $_POST['check_out'] ?? '';
        
        if (empty($clientName) || empty($clientMobile) || empty($checkin) || empty($checkout)) {
            $error = 'Name, mobile number, check-in and check-out are required';
        } elseif (!preg_match('/^[6-9]\d{9}$/', $clientMobile)) {
            $error = 'Mobile number must be 10 digits starting with 6-9';
        } elseif (!empty($clientAadhar) && !preg_match('/^\d{12}$/', $clientAadhar)) {
            $error = 'Aadhar number must be 12 digits';
        } elseif (!empty($clientLicense) && strlen($clientLicense) < 8) {
            $error = 'License number must be at least 8 characters';
        } elseif (strtotime($checkout) <= strtotime($checkin)) {
            $error = 'Check-out must be after check-in';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (resource_id, client_name, client_mobile, client_aadhar, client_license, receipt_number, payment_mode, check_in, check_out, actual_check_in, admin_id, status, booking_type) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'BOOKED', 'regular')
                ");
                $stmt->execute([$resourceId, $clientName, $clientMobile, $clientAadhar ?: null, $clientLicense ?: null, $receiptNumber ?: null, $paymentMode, $checkin, $checkout, $_SESSION['user_id']]);
                
                $bookingId = $pdo->lastInsertId();
                
                // Send SMS notification
                require_once 'includes/sms_functions.php';
                $sms_result = send_booking_confirmation_sms($bookingId, $pdo);
                
                $message = 'Booking created successfully!';
                if (!$sms_result['success']) {
                    $message .= ' (SMS failed: ' . $sms_result['message'] . ')';
                }
                
                redirect_with_message('grid.php', $message, 'success');
            } catch (Exception $e) {
                $error = 'Failed to create booking: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking - <?= htmlspecialchars($resource['display_name']) ?></title>
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
        <div class="form-container">
            <h2>New Booking - <?= htmlspecialchars($resource['display_name']) ?></h2>
            
            <?php if (isset($error)): ?>
                <div class="flash-message flash-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" onsubmit="return validateBookingForm()">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
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
                    <small style="color: var(--dark-color); font-size: 0.9rem;">
                        Enter 10 digit mobile number starting with 6-9
                    </small>
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
                        <label for="payment_mode" class="form-label">Payment Mode</label>
                        <select id="payment_mode" name="payment_mode" class="form-control">
                            <option value="OFFLINE" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'OFFLINE') ? 'selected' : '' ?>>Offline (Cash/Card)</option>
                            <option value="ONLINE" <?= (isset($_POST['payment_mode']) && $_POST['payment_mode'] === 'ONLINE') ? 'selected' : '' ?>>Online (UPI/Net Banking)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="check_in" class="form-label">Check-in Date & Time *</label>
                    <input type="datetime-local" id="check_in" name="check_in" class="form-control" required
                           value="<?= isset($_POST['check_in']) ? $_POST['check_in'] : date('Y-m-d\TH:i', time()) ?>">
                </div>
                
                <div class="form-group">
                    <label for="check_out" class="form-label">Check-out Date & Time *</label>
                    <input type="datetime-local" id="check_out" name="check_out" class="form-control" required
                           value="<?= isset($_POST['check_out']) ? $_POST['check_out'] : date('Y-m-d\TH:i', strtotime('+1 day', time())) ?>">
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">Create Booking</button>
                    <a href="grid.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
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
        
        // Validate form before submission
        function validateBookingForm() {
            const mobile = document.getElementById('client_mobile').value;
            const aadhar = document.getElementById('client_aadhar').value;
            
            if (mobile && !/^[6-9]\d{9}$/.test(mobile)) {
                alert('Mobile number must be 10 digits starting with 6-9');
                return false;
            }
            
            if (aadhar && !/^\d{12}$/.test(aadhar)) {
                alert('Aadhar number must be exactly 12 digits');
                return false;
            }
            
            const checkin = new Date(document.getElementById('check_in').value);
            const checkout = new Date(document.getElementById('check_out').value);
            
            if (checkout <= checkin) {
                alert('Check-out time must be after check-in time');
                return false;
            }
            
            return true;
        }
        
        // Add form validation to submit
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!validateBookingForm()) {
                e.preventDefault();
            }
        });
    </script>
    <script src="assets/script.js"></script>
</body>
</html>