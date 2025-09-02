<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

$resourceId = $_GET['resource_id'] ?? '';

// Get the advanced booking for today
$stmt = $pdo->prepare("
    SELECT b.*, r.display_name 
    FROM bookings b 
    JOIN resources r ON b.resource_id = r.id 
    WHERE b.resource_id = ? 
    AND b.booking_type = 'advanced' 
    AND b.advance_date = CURDATE()
    AND b.status = 'ADVANCED_BOOKED'
");
$stmt->execute([$resourceId]);
$booking = $stmt->fetch();

if (!$booking) {
    redirect_with_message('grid.php', 'No advanced booking found for today', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $checkin = $_POST['check_in'] ?? '';
        $checkout = $_POST['check_out'] ?? '';
        
        if (empty($checkin) || empty($checkout)) {
            $error = 'Check-in and check-out times are required';
        } elseif (strtotime($checkout) <= strtotime($checkin)) {
            $error = 'Check-out must be after check-in';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET status = 'BOOKED', booking_type = 'regular', 
                        check_in = ?, check_out = ?, advance_date = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$checkin, $checkout, $booking['id']]);
                
                redirect_with_message('grid.php', 'Advanced booking converted to active booking!', 'success');
            } catch (Exception $e) {
                $error = 'Failed to convert booking';
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
    <title>Convert Advanced Booking - <?= htmlspecialchars($booking['display_name']) ?></title>
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
            <h2>Convert Advanced Booking</h2>
            
            <div style="text-align: center; margin-bottom: 2rem;">
                <div class="advanced-room-number" style="font-size: 3rem; color: var(--primary-color);">
                    <?php 
                    // Show custom name if available, otherwise show display name
                    echo htmlspecialchars($booking['custom_name'] ?: $booking['display_name']);
                    ?>
                </div>
                <div style="font-size: 1.1rem; color: var(--dark-color);">
                    <?= htmlspecialchars($booking['custom_name'] ? $booking['display_name'] : '') ?>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="flash-message flash-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <div class="dashboard-card" style="margin-bottom: 2rem;">
                <h3>Advanced Booking Details</h3>
                <p><strong>Client:</strong> <?= htmlspecialchars($booking['client_name']) ?></p>
                <p><strong>Advance Date:</strong> <?= date('M j, Y', strtotime($booking['advance_date'])) ?></p>
                <p><strong>Status:</strong> ADVANCED BOOKED</p>
            </div>
            
            <form method="POST" onsubmit="return validateBookingForm()">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <div class="form-group">
                    <label for="check_in" class="form-label">Check-in Date & Time *</label>
                    <input type="datetime-local" id="check_in" name="check_in" class="form-control" required
                           value="<?= date('Y-m-d\TH:i') ?>">
                </div>
                
                <div class="form-group">
                    <label for="check_out" class="form-label">Check-out Date & Time *</label>
                    <input type="datetime-local" id="check_out" name="check_out" class="form-control" required
                           value="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>">
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">Convert to Active Booking</button>
                    <a href="grid.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
</body>
</html>