<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

if (!isset($_SESSION['payment_amount']) || !isset($_SESSION['upi_url'])) {
    redirect_with_message('grid.php', 'Payment session expired', 'error');
}

// Get current UPI settings from database
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('upi_id', 'upi_name')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$amount = $_SESSION['payment_amount'];
$upiId = $settings['upi_id'] ?? 'owner@upi';
$upiName = $settings['upi_name'] ?? 'L.P.S.T Bookings';

// Generate fresh UPI URL with current settings
$upiUrl = "upi://pay?pa=" . urlencode($upiId) . "&pn=" . urlencode($upiName) . "&am=" . $amount . "&cu=INR&tn=Room%20Payment";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <h2>Complete Payment</h2>
            
            <div class="dashboard-card" style="text-align: center;">
                <h3>Amount: <?= format_currency($amount) ?></h3>
                
                <div style="margin: 1rem 0;">
                    <strong>UPI ID:</strong> <?= htmlspecialchars($upiId) ?><br>
                    <strong>Name:</strong> <?= htmlspecialchars($upiName) ?>
                </div>
                
                <!-- QR Code -->
                <div class="qr-code-container">
                    <canvas id="qrcode"></canvas>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem; color: var(--dark-color);">
                        Scan QR code with any UPI app
                    </p>
                </div>
                
                <div style="margin: 2rem 0;">
                    <a href="<?= htmlspecialchars($upiUrl) ?>" class="btn btn-primary" style="font-size: 1.2rem;">
                        💳 Pay Now via UPI
                    </a>
                </div>
                
                <div style="background: rgba(16, 185, 129, 0.1); padding: 1rem; border-radius: 8px; margin: 1rem 0;">
                    <h4 style="color: var(--success-color); margin-bottom: 0.5rem;">Payment Instructions:</h4>
                    <ol style="text-align: left; color: var(--dark-color);">
                        <li>Scan the QR code or click the UPI button</li>
                        <li>Complete payment in your UPI app</li>
                        <li>Take a screenshot of the payment confirmation</li>
                        <li>Contact admin for payment verification</li>
                    </ol>
                </div>
                
                <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                    <p style="font-size: 1rem; color: var(--success-color); font-weight: 600;">
                        ✅ After payment completion, please contact admin for confirmation.
                    </p>
                    <p style="font-size: 0.9rem; color: var(--dark-color); margin-top: 0.5rem;">
                        Thank you for choosing L.P.S.T Bookings!
                    </p>
                    <a href="grid.php" class="btn btn-outline">Return to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Generate QR code
        const canvas = document.getElementById('qrcode');
        const upiUrl = <?= json_encode($upiUrl) ?>;
        
        QRCode.toCanvas(canvas, upiUrl, {
            width: 200,
            margin: 2,
            color: {
                dark: '#000000',
                light: '#FFFFFF'
            }
        }, function (error) {
            if (error) console.error(error);
        });
    </script>
</body>
</html>

<?php
// Clear payment session
unset($_SESSION['payment_amount']);
unset($_SESSION['payment_resource']);
unset($_SESSION['payment_upi_id']);
unset($_SESSION['payment_upi_name']);
unset($_SESSION['upi_url']);
?>