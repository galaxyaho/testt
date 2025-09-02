<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    redirect_with_message('grid.php', 'Invalid request', 'error');
}

$resourceId = $_POST['resource_id'] ?? '';
$amount = floatval($_POST['amount'] ?? 0);
$paymentMethod = $_POST['payment_method'] ?? 'upi';

if (empty($resourceId) || $amount <= 0) {
    redirect_with_message('grid.php', 'Invalid payment details', 'error');
}

if ($paymentMethod === 'manual') {
    // Manual payment - just record it
    $resourceName = '';
    $stmt = $pdo->prepare("SELECT display_name, custom_name FROM resources WHERE id = ?");
    $stmt->execute([$resourceId]);
    $resource = $stmt->fetch();
    if ($resource) {
        $resourceName = $resource['custom_name'] ?: $resource['display_name'];
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO payments (resource_id, amount, payment_method, payment_status, admin_id, payment_notes) 
            VALUES (?, ?, 'MANUAL', 'COMPLETED', ?, ?)
        ");
        $stmt->execute([$resourceId, $amount, $_SESSION['user_id'], "Manual payment for $resourceName"]);
        
        redirect_with_message('grid.php', 'Manual payment recorded successfully!', 'success');
    } catch (Exception $e) {
        redirect_with_message('grid.php', 'Payment recording failed', 'error');
    }
} else {
    // UPI payment
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('upi_id', 'upi_name')");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $upiId = $settings['upi_id'] ?: 'owner@upi';
    $upiName = $settings['upi_name'] ?: 'L.P.S.T Bookings';

    // Create payment record
    try {
        $stmt = $pdo->prepare("
            INSERT INTO payments (resource_id, amount, payment_method, payment_status, admin_id) 
            VALUES (?, ?, 'UPI', 'PENDING', ?)
        ");
        $stmt->execute([$resourceId, $amount, $_SESSION['user_id']]);
        
        // Generate UPI payment URL
        $upiUrl = "upi://pay?pa=" . urlencode($upiId) . "&pn=" . urlencode($upiName) . "&am=" . $amount . "&cu=INR&tn=Room%20Payment";
        
        // Redirect to payment confirmation page
        $_SESSION['payment_amount'] = $amount;
        $_SESSION['payment_resource'] = $resourceId;
        $_SESSION['payment_upi_id'] = $upiId;
        $_SESSION['payment_upi_name'] = $upiName;
        $_SESSION['upi_url'] = $upiUrl;
        
        header('Location: payment_confirm.php');
        exit;
        
    } catch (Exception $e) {
        redirect_with_message('grid.php', 'Payment processing failed', 'error');
    }
}
?>