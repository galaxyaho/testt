<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    redirect_with_message('grid.php', 'Invalid request', 'error');
}

$action = $_POST['action'] ?? '';
$bookingId = $_POST['booking_id'] ?? '';

if (empty($bookingId)) {
    redirect_with_message('grid.php', 'Booking ID required', 'error');
}

try {
    switch ($action) {
        case 'cancel_advanced':
            // Cancel advanced booking
            $stmt = $pdo->prepare("
                SELECT b.*, r.display_name, r.custom_name, u.username as admin_name
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
            
            // Update booking status to cancelled
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED', 
                    payment_notes = CONCAT(IFNULL(payment_notes, ''), ' - Advanced booking cancelled by admin')
                WHERE id = ?
            ");
            if ($stmt->execute([$bookingId])) {
                // Send cancellation SMS
                require_once 'includes/sms_functions.php';
                send_cancellation_sms($bookingId, $pdo);
                
                // Record cancellation for owner dashboard
                try {
                    $resourceName = $booking['custom_name'] ?: $booking['display_name'];
                    $stmt = $pdo->prepare("
                        INSERT INTO booking_cancellations (booking_id, resource_id, cancelled_by, cancellation_reason, original_client_name, original_advance_date) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $bookingId, 
                        $booking['resource_id'], 
                        $_SESSION['user_id'],
                        "Advanced booking cancelled by " . $_SESSION['username'],
                        $booking['client_name'],
                        $booking['advance_date']
                    ]);
                } catch (Exception $e) {
                    // Continue even if cancellation recording fails
                }
                
                redirect_with_message('grid.php', 'Advanced booking cancelled successfully! Room is now available.', 'success');
            } else {
                redirect_with_message('grid.php', 'Failed to cancel advanced booking', 'error');
            }
            break;
            
        case 'mark_paid':
            // Get payment details from form
            $amount = floatval($_POST['amount'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? 'OFFLINE';
            
            if ($amount <= 0) {
                redirect_with_message('grid.php', 'Valid amount is required', 'error');
            }
            
            // Get booking and resource details
            $stmt = $pdo->prepare("
                SELECT b.*, r.display_name, r.custom_name 
                FROM bookings b 
                JOIN resources r ON b.resource_id = r.id 
                WHERE b.id = ?
            ");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();
            
            if (!$booking) {
                redirect_with_message('grid.php', 'Booking not found', 'error');
            }
            
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            
            // Mark booking as paid and update amount
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET is_paid = 1, total_amount = ?, status = 'PAID'
                WHERE id = ?
            ");
            if ($stmt->execute([$amount, $bookingId])) {
                // Record the payment
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments (booking_id, resource_id, amount, payment_method, payment_status, admin_id, payment_notes) 
                        VALUES (?, ?, ?, ?, 'COMPLETED', ?, ?)
                    ");
                    $stmt->execute([
                        $bookingId, 
                        $booking['resource_id'], 
                        $amount, 
                        $paymentMethod,
                        $_SESSION['user_id'],
                        "Payment received for {$resourceName} - Method: {$paymentMethod}"
                    ]);
                } catch (Exception $e) {
                    // Continue even if payment recording fails
                }
                
                redirect_with_message('grid.php', 'Payment recorded successfully! Amount: ₹' . number_format($amount, 2), 'success');
            } else {
                redirect_with_message('grid.php', 'Failed to record payment', 'error');
            }
            break;
            
        case 'checkout':
            // Get checkout details from form
            $checkoutDateTime = $_POST['checkout_datetime'] ?? date('Y-m-d H:i:s');
            
            // Get booking details for payment recording
            $stmt = $pdo->prepare("
                SELECT b.*, r.display_name, r.custom_name 
                FROM bookings b 
                JOIN resources r ON b.resource_id = r.id 
                WHERE b.id = ?
            ");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();
            
            if (!$booking) {
                redirect_with_message('grid.php', 'Booking not found', 'error');
            }
            
            // Update booking with custom checkout time
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED', 
                    actual_check_out = ?
                WHERE id = ?
            ");
            if ($stmt->execute([$checkoutDateTime, $bookingId])) {
                // Send checkout SMS
                require_once 'includes/sms_functions.php';
                send_checkout_confirmation_sms($bookingId, $pdo);
                
                // Record checkout completion
                $resourceName = $booking['custom_name'] ?: $booking['display_name'];
                $duration = calculate_duration($booking['check_in'], $checkoutDateTime);
                $amount = max(500, $duration['hours'] * 100);
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments (booking_id, resource_id, amount, payment_method, payment_status, admin_id, payment_notes) 
                        VALUES (?, ?, ?, 'CHECKOUT_COMPLETE', 'COMPLETED', ?, ?)
                    ");
                    $stmt->execute([
                        $bookingId, 
                        $booking['resource_id'], 
                        $amount, 
                        $_SESSION['user_id'],
                        "Checkout completed for {$resourceName} - Duration: {$duration['formatted']} - Checkout: {$checkoutDateTime}"
                    ]);
                } catch (Exception $e) {
                    // Continue even if payment recording fails
                }
                
                redirect_with_message('grid.php', 'Checkout completed successfully at ' . date('M j, g:i A', strtotime($checkoutDateTime)) . '!', 'success');
            } else {
                redirect_with_message('grid.php', 'Failed to complete checkout', 'error');
            }
            break;
            
        case 'cancel_booking':
            // Cancel regular booking
            $stmt = $pdo->prepare("
                SELECT b.*, r.display_name, r.custom_name, u.username as admin_name
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
            
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED', 
                    actual_check_out = NOW(),
                    payment_notes = CONCAT(IFNULL(payment_notes, ''), ' - Booking cancelled by admin')
                WHERE id = ?
            ");
            if ($stmt->execute([$bookingId])) {
                // Send cancellation SMS
                require_once 'includes/sms_functions.php';
                send_cancellation_sms($bookingId, $pdo);
                
                // Record cancellation for owner dashboard
                try {
                    $resourceName = $booking['custom_name'] ?: $booking['display_name'];
                    $duration = calculate_duration($booking['check_in']);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO booking_cancellations (booking_id, resource_id, cancelled_by, cancellation_reason, original_client_name, duration_at_cancellation) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $bookingId, 
                        $booking['resource_id'], 
                        $_SESSION['user_id'],
                        "Regular booking cancelled by " . $_SESSION['username'] . " after " . $duration['formatted'],
                        $booking['client_name'],
                        $duration['total_minutes']
                    ]);
                } catch (Exception $e) {
                    // Continue even if cancellation recording fails
                }
                
                redirect_with_message('grid.php', 'Booking cancelled successfully! Room is now available.', 'success');
            } else {
                redirect_with_message('grid.php', 'Failed to cancel booking', 'error');
            }
            break;
            
        default:
            redirect_with_message('grid.php', 'Invalid action', 'error');
    }
} catch (Exception $e) {
    redirect_with_message('grid.php', 'Operation failed: ' . $e->getMessage(), 'error');
}
?>