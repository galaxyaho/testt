<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

$bookingId = $_GET['id'] ?? '';

// Get booking details
$stmt = $pdo->prepare("
    SELECT b.*, r.display_name, r.type, r.identifier, u.username as admin_name
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'checkout':
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET status = 'COMPLETED', is_paid = 1, check_out = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$bookingId]);
                redirect_with_message('grid.php', 'Checkout completed successfully!', 'success');
                break;
                
            case 'extend':
                $newCheckout = $_POST['new_checkout'] ?? '';
                if (empty($newCheckout)) {
                    $error = 'New checkout time is required';
                } elseif (strtotime($newCheckout) <= strtotime($booking['check_in'])) {
                    $error = 'New checkout must be after check-in';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE bookings 
                        SET check_out = ?, status = 'BOOKED' 
                        WHERE id = ?
                    ");
                    $stmt->execute([$newCheckout, $bookingId]);
                    redirect_with_message('grid.php', 'Booking extended successfully!', 'success');
                }
                break;
                
            case 'mark_paid':
                $stmt = $pdo->prepare("UPDATE bookings SET is_paid = 1 WHERE id = ?");
                $stmt->execute([$bookingId]);
                redirect_with_message("booking_manage.php?id=$bookingId", 'Marked as paid!', 'success');
                break;
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
    <title>Manage Booking - <?= htmlspecialchars($booking['display_name']) ?></title>
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
            <h2>Manage Booking - <?= htmlspecialchars($booking['display_name']) ?></h2>
            
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
                <h3>Booking Details</h3>
                <p><strong>Client:</strong> <?= htmlspecialchars($booking['client_name']) ?></p>
                <p><strong>Check-in:</strong> <?= date('M j, Y g:i A', strtotime($booking['check_in'])) ?></p>
                <p><strong>Check-out:</strong> <?= date('M j, Y g:i A', strtotime($booking['check_out'])) ?></p>
                <p><strong>Status:</strong> 
                    <span class="status-badge status-<?= strtolower($booking['status']) ?>">
                        <?= $booking['status'] ?>
                    </span>
                </p>
                <p><strong>Payment Status:</strong> 
                    <span style="color: <?= $booking['is_paid'] ? 'var(--success-color)' : 'var(--danger-color)' ?>">
                        <?= $booking['is_paid'] ? 'PAID' : 'UNPAID' ?>
                    </span>
                </p>
                <p><strong>Booked by:</strong> <?= htmlspecialchars($booking['admin_name']) ?></p>
                <p><strong>Duration:</strong> 
                    <div class="live-counter" data-checkin="<?= $booking['check_in'] ?>">
                        Calculating...
                    </div>
                </p>
            </div>
            
            <div style="display: grid; gap: 1rem;">
                <h3>Actions</h3>
                
                <?php if (!$booking['is_paid']): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <button type="submit" class="btn btn-success">Mark as Paid</button>
                    </form>
                <?php endif; ?>
                
                <?php if ($booking['status'] === 'PENDING'): ?>
                    <div style="background: rgba(239, 68, 68, 0.1); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <p style="color: var(--danger-color); font-weight: 600;">⚠️ This booking requires immediate action - over 24 hours elapsed!</p>
                    </div>
                    
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="checkout">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Complete checkout now?')">
                            Complete Checkout
                        </button>
                    </form>
                <?php endif; ?>
                
                <div class="form-group">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="extend">
                        <label for="new_checkout" class="form-label">Extend Check-out Time</label>
                        <input type="datetime-local" id="new_checkout" name="new_checkout" class="form-control" 
                               value="<?= date('Y-m-d\TH:i', strtotime($booking['check_out'] . ' +1 hour')) ?>">
                        <button type="submit" class="btn btn-warning" style="margin-top: 0.5rem;">Extend Time</button>
                    </form>
                </div>
                
                <a href="grid.php" class="btn btn-outline">Back to Grid</a>
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
</body>
</html>