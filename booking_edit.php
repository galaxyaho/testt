<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

$bookingId = $_GET['id'] ?? '';

// Get booking details
$stmt = $pdo->prepare("
    SELECT b.*, r.display_name, r.custom_name, r.type, r.identifier, u.username as admin_name
    FROM bookings b 
    JOIN resources r ON b.resource_id = r.id 
    JOIN users u ON b.admin_id = u.id 
    WHERE b.id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    redirect_with_message('grid.php', 'Booking not found', 'error');
}

// Check if current admin can edit this booking
if ($booking['admin_id'] != $_SESSION['user_id']) {
    redirect_with_message('grid.php', 'You can only edit bookings created by you', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $clientAadhar = sanitize_input($_POST['client_aadhar'] ?? '');
        $clientLicense = sanitize_input($_POST['client_license'] ?? '');
        $receiptNumber = sanitize_input($_POST['receipt_number'] ?? '');
        $paymentMode = $_POST['payment_mode'] ?? 'OFFLINE';
        
        // Validate inputs
        if (!empty($clientAadhar) && !preg_match('/^\d{12}$/', $clientAadhar)) {
            $error = 'Aadhar number must be 12 digits';
        } elseif (!empty($clientLicense) && strlen($clientLicense) < 8) {
            $error = 'License number must be at least 8 characters';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET client_aadhar = ?, client_license = ?, receipt_number = ?, payment_mode = ?, updated_at = NOW()
                    WHERE id = ? AND admin_id = ?
                ");
                $stmt->execute([
                    $clientAadhar ?: null, 
                    $clientLicense ?: null, 
                    $receiptNumber ?: null, 
                    $paymentMode, 
                    $bookingId, 
                    $_SESSION['user_id']
                ]);
                
                redirect_with_message('grid.php', 'Booking details updated successfully!', 'success');
            } catch (Exception $e) {
                $error = 'Failed to update booking details';
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
    <title>Edit Booking Details - <?= htmlspecialchars($booking['display_name']) ?></title>
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
            <h2>Edit Booking Details - <?= htmlspecialchars($booking['display_name']) ?></h2>
            
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
            
            <div class="dashboard-card" style="margin-bottom: 2rem;">
                <h3>Booking Information</h3>
                <p><strong>Client:</strong> <?= htmlspecialchars($booking['client_name']) ?></p>
                <p><strong>Mobile:</strong> <?= htmlspecialchars($booking['client_mobile']) ?></p>
                <p><strong>Check-in:</strong> <?= date('M j, Y g:i A', strtotime($booking['check_in'])) ?></p>
                <p><strong>Check-out:</strong> <?= date('M j, Y g:i A', strtotime($booking['check_out'])) ?></p>
                <p><strong>Status:</strong> 
                    <span class="status-badge status-<?= strtolower($booking['status']) ?>">
                        <?= $booking['status'] ?>
                    </span>
                </p>
                <p><strong>Booked by:</strong> <?= htmlspecialchars($booking['admin_name']) ?></p>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <h3>Editable Details</h3>
                <p style="color: var(--dark-color); margin-bottom: 1.5rem;">
                    You can update the following details for this booking:
                </p>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="client_aadhar" class="form-label">Aadhar Number</label>
                        <input type="text" id="client_aadhar" name="client_aadhar" class="form-control"
                               pattern="[0-9]{12}" maxlength="12"
                               placeholder="12 digit Aadhar number"
                               value="<?= htmlspecialchars($booking['client_aadhar'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="client_license" class="form-label">Driving License</label>
                        <input type="text" id="client_license" name="client_license" class="form-control"
                               placeholder="License number"
                               value="<?= htmlspecialchars($booking['client_license'] ?? '') ?>">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="receipt_number" class="form-label">Receipt Number</label>
                        <input type="text" id="receipt_number" name="receipt_number" class="form-control"
                               placeholder="Receipt/Invoice number"
                               value="<?= htmlspecialchars($booking['receipt_number'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_mode" class="form-label">Payment Mode</label>
                        <select id="payment_mode" name="payment_mode" class="form-control">
                            <option value="OFFLINE" <?= $booking['payment_mode'] === 'OFFLINE' ? 'selected' : '' ?>>Offline (Cash/Card)</option>
                            <option value="ONLINE" <?= $booking['payment_mode'] === 'ONLINE' ? 'selected' : '' ?>>Online (UPI/Net Banking)</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary">Update Details</button>
                    <a href="grid.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
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