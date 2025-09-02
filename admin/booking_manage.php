<?php
require_once '../config/database.php';

// Get room ID from URL
$roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;

if (!$roomId) {
    header('Location: rooms.php');
    exit;
}

// Handle form submissions
if ($_POST) {
    if (isset($_POST['update_booking'])) {
        $guestName = $_POST['guest_name'];
        $guestPhone = $_POST['guest_phone'];
        $guestEmail = $_POST['guest_email'];
        $checkOutDate = $_POST['check_out_date'];
        $autoCheckoutEnabled = isset($_POST['auto_checkout_enabled']) ? 1 : 0;
        
        try {
            $stmt = $pdo->prepare("
                UPDATE rooms 
                SET guest_name = ?, 
                    guest_phone = ?, 
                    guest_email = ?, 
                    check_out_date = ?,
                    auto_checkout_enabled = ?
                WHERE id = ?
            ");
            $stmt->execute([$guestName, $guestPhone, $guestEmail, $checkOutDate, $autoCheckoutEnabled, $roomId]);
            
            $success_message = "Booking updated successfully!";
        } catch (Exception $e) {
            $error_message = "Error updating booking: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['checkout_room'])) {
        try {
            $stmt = $pdo->prepare("
                UPDATE rooms 
                SET status = 'cleaning',
                    guest_name = NULL,
                    guest_phone = NULL,
                    guest_email = NULL,
                    check_in_date = NULL,
                    check_in_time = NULL,
                    check_out_date = NULL,
                    check_out_time = NULL
                WHERE id = ?
            ");
            $stmt->execute([$roomId]);
            
            header('Location: rooms.php?checkout_success=1');
            exit;
        } catch (Exception $e) {
            $error_message = "Error checking out room: " . $e->getMessage();
        }
    }
}

// Get room details
$stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
$stmt->execute([$roomId]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: rooms.php');
    exit;
}

// Get system settings for auto checkout time
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$autoCheckoutTime = $settings['auto_checkout_time'] ?? '10:00';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Booking - Room <?php echo $room['room_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .auto-checkout-alert {
            background: linear-gradient(45deg, #ff6b6b, #ee5a52);
            color: white;
            border: none;
            animation: pulse 2s infinite;
        }
        
        .auto-checkout-notice {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            font-weight: bold;
            box-shadow: 0 4px 15px rgba(0,123,255,0.3);
        }
        
        .room-info-card {
            border: 2px solid #007bff;
            border-radius: 15px;
            background: linear-gradient(135deg, #f8f9ff 0%, #e3f2fd 100%);
        }
        
        .checkout-warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }
        
        .status-badge {
            font-size: 1.1em;
            padding: 8px 15px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <!-- Auto Checkout System Notice -->
                <div class="auto-checkout-notice">
                    <i class="fas fa-clock me-2"></i>
                    <h4 class="mb-2">🕙 DAILY <?php echo strtoupper($autoCheckoutTime); ?> AUTO CHECKOUT SYSTEM ACTIVE</h4>
                    <p class="mb-0">This room will be automatically checked out at <?php echo $autoCheckoutTime; ?> AM every day</p>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="fas fa-edit me-2"></i>Manage Booking - Room <?php echo $room['room_number']; ?></h1>
                    <a href="rooms.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Rooms
                    </a>
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-8">
                        <div class="card room-info-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-door-open me-2"></i>Room <?php echo $room['room_number']; ?> Details
                                </h5>
                                <span class="badge status-badge bg-<?php 
                                    echo $room['status'] === 'available' ? 'success' : 
                                        ($room['status'] === 'occupied' ? 'danger' : 
                                        ($room['status'] === 'cleaning' ? 'warning' : 'secondary')); 
                                ?>">
                                    <?php echo ucfirst($room['status']); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-sm-4"><strong>Room Type:</strong></div>
                                    <div class="col-sm-8"><?php echo $room['room_type']; ?></div>
                                </div>
                                
                                <?php if ($room['status'] === 'occupied'): ?>
                                    <!-- Auto Checkout Warning -->
                                    <div class="alert auto-checkout-alert">
                                        <h5><i class="fas fa-exclamation-triangle me-2"></i>AUTO CHECKOUT WARNING</h5>
                                        <p class="mb-2"><strong>This room will be automatically checked out at <?php echo $autoCheckoutTime; ?> AM daily!</strong></p>
                                        <p class="mb-0">
                                            <i class="fas fa-info-circle me-1"></i>
                                            The system will automatically checkout this guest at <?php echo $autoCheckoutTime; ?> AM regardless of the expected checkout date below.
                                        </p>
                                    </div>
                                    
                                    <form method="POST">
                                        <div class="row mb-3">
                                            <div class="col-sm-4"><strong>Guest Name:</strong></div>
                                            <div class="col-sm-8">
                                                <input type="text" class="form-control" name="guest_name" 
                                                       value="<?php echo htmlspecialchars($room['guest_name']); ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-sm-4"><strong>Phone:</strong></div>
                                            <div class="col-sm-8">
                                                <input type="tel" class="form-control" name="guest_phone" 
                                                       value="<?php echo htmlspecialchars($room['guest_phone']); ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-sm-4"><strong>Email:</strong></div>
                                            <div class="col-sm-8">
                                                <input type="email" class="form-control" name="guest_email" 
                                                       value="<?php echo htmlspecialchars($room['guest_email']); ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-sm-4"><strong>Check-in Date:</strong></div>
                                            <div class="col-sm-8">
                                                <input type="text" class="form-control" 
                                                       value="<?php echo $room['check_in_date'] . ' ' . $room['check_in_time']; ?>" readonly>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-sm-4"><strong>Expected Checkout:</strong></div>
                                            <div class="col-sm-8">
                                                <input type="date" class="form-control" name="check_out_date" 
                                                       value="<?php echo $room['check_out_date']; ?>" min="<?php echo date('Y-m-d'); ?>">
                                                <small class="text-muted">Note: Room will auto-checkout at <?php echo $autoCheckoutTime; ?> AM regardless of this date</small>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-4">
                                            <div class="col-sm-4"><strong>Auto Checkout:</strong></div>
                                            <div class="col-sm-8">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="auto_checkout_enabled" 
                                                           id="autoCheckout" <?php echo $room['auto_checkout_enabled'] ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="autoCheckout">
                                                        Enable daily <?php echo $autoCheckoutTime; ?> AM auto checkout
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex gap-2">
                                            <button type="submit" name="update_booking" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i>Update Booking
                                            </button>
                                            <button type="submit" name="checkout_room" class="btn btn-danger" 
                                                    onclick="return confirm('Are you sure you want to checkout this room now?')">
                                                <i class="fas fa-sign-out-alt me-1"></i>Manual Checkout Now
                                            </button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        This room is currently <?php echo $room['status']; ?>. No booking details to manage.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Auto Checkout Information Panel -->
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Auto Checkout System</h5>
                            </div>
                            <div class="card-body">
                                <div class="checkout-warning">
                                    <h6><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Important Notice</h6>
                                    <ul class="mb-0">
                                        <li><strong>Daily Checkout Time:</strong> <?php echo $autoCheckoutTime; ?> AM</li>
                                        <li><strong>Automatic Process:</strong> No admin action required</li>
                                        <li><strong>Room Status:</strong> Changes to "Cleaning" after checkout</li>
                                        <li><strong>Guest Data:</strong> Automatically cleared</li>
                                    </ul>
                                </div>
                                
                                <div class="mt-3">
                                    <h6><i class="fas fa-cog me-2"></i>System Status</h6>
                                    <div class="d-flex justify-content-between">
                                        <span>Auto Checkout:</span>
                                        <span class="badge bg-success">Active</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Daily Time:</span>
                                        <span class="badge bg-info"><?php echo $autoCheckoutTime; ?> AM</span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>This Room:</span>
                                        <span class="badge bg-<?php echo $room['auto_checkout_enabled'] ? 'success' : 'warning'; ?>">
                                            <?php echo $room['auto_checkout_enabled'] ? 'Enabled' : 'Disabled'; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <button class="btn btn-outline-primary btn-sm w-100" onclick="testAutoCheckout()">
                                        <i class="fas fa-play me-1"></i>Test Auto Checkout
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Recent Auto Checkout Logs -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-history me-2"></i>Recent Auto Checkouts</h6>
                            </div>
                            <div class="card-body">
                                <?php
                                $stmt = $pdo->prepare("
                                    SELECT * FROM auto_checkout_logs 
                                    WHERE room_id = ? 
                                    ORDER BY created_at DESC 
                                    LIMIT 5
                                ");
                                $stmt->execute([$roomId]);
                                $logs = $stmt->fetchAll();
                                
                                if (empty($logs)): ?>
                                    <p class="text-muted mb-0">No auto checkout history for this room.</p>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></small>
                                            <span class="badge bg-<?php echo $log['status'] === 'success' ? 'success' : 'danger'; ?>">
                                                <?php echo $log['status']; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                    <a href="auto_checkout_logs.php" class="btn btn-sm btn-outline-secondary w-100 mt-2">
                                        View All Logs
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testAutoCheckout() {
            if (confirm('This will test the auto checkout system. Continue?')) {
                fetch('../cron/auto_checkout_cron.php?manual_run=1')
                    .then(response => response.json())
                    .then(data => {
                        alert('Auto checkout test completed:\n' + 
                              'Status: ' + data.status + '\n' +
                              'Checked out: ' + (data.checked_out || 0) + ' rooms\n' +
                              'Failed: ' + (data.failed || 0) + ' rooms');
                        location.reload();
                    })
                    .catch(error => {
                        alert('Error testing auto checkout: ' + error);
                    });
            }
        }
        
        // Show current time and next auto checkout
        function updateTimeInfo() {
            const now = new Date();
            const nextCheckout = new Date();
            nextCheckout.setHours(10, 0, 0, 0);
            
            if (now.getHours() >= 10) {
                nextCheckout.setDate(nextCheckout.getDate() + 1);
            }
            
            console.log('Current time:', now.toLocaleTimeString());
            console.log('Next auto checkout:', nextCheckout.toLocaleString());
        }
        
        updateTimeInfo();
        setInterval(updateTimeInfo, 60000); // Update every minute
    </script>
</body>
</html>