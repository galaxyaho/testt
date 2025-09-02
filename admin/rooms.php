<?php
require_once '../config/database.php';
require_once '../includes/auto_checkout.php';

// Handle manual checkout
if (isset($_POST['checkout_room'])) {
    $roomId = $_POST['room_id'];
    
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
        
        $success_message = "Room checked out successfully!";
    } catch (Exception $e) {
        $error_message = "Error checking out room: " . $e->getMessage();
    }
}

// Handle check-in
if (isset($_POST['checkin_room'])) {
    $roomId = $_POST['room_id'];
    $guestName = $_POST['guest_name'];
    $guestPhone = $_POST['guest_phone'];
    $guestEmail = $_POST['guest_email'];
    $checkOutDate = $_POST['check_out_date'];
    
    try {
        $stmt = $pdo->prepare("
            UPDATE rooms 
            SET status = 'occupied',
                guest_name = ?,
                guest_phone = ?,
                guest_email = ?,
                check_in_date = CURDATE(),
                check_in_time = CURTIME(),
                check_out_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$guestName, $guestPhone, $guestEmail, $checkOutDate, $roomId]);
        
        $success_message = "Room checked in successfully!";
    } catch (Exception $e) {
        $error_message = "Error checking in room: " . $e->getMessage();
    }
}

// Get all rooms
$stmt = $pdo->query("SELECT * FROM rooms ORDER BY room_number");
$rooms = $stmt->fetchAll();

// Get system settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Management - Hotel System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .room-card {
            border: 2px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .room-available { border-color: #28a745; background-color: #f8fff9; }
        .room-occupied { border-color: #dc3545; background-color: #fff8f8; }
        .room-cleaning { border-color: #ffc107; background-color: #fffdf5; }
        .room-maintenance { border-color: #6c757d; background-color: #f8f9fa; }
        .auto-checkout-notice {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.8; }
            100% { opacity: 1; }
        }
        .room-notice {
            background-color: #e3f2fd;
            border: 1px solid #2196f3;
            border-radius: 5px;
            padding: 8px;
            margin-top: 10px;
            font-size: 0.85em;
            color: #1976d2;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="auto-checkout-notice">
                    <i class="fas fa-clock me-2"></i>
                    DAILY 10AM AUTO CHECKOUT SYSTEM ACTIVE
                    <br>
                    <small>All occupied rooms will be automatically checked out at 10:00 AM daily</small>
                </div>
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="fas fa-bed me-2"></i>Room Management</h1>
                    <div>
                        <button class="btn btn-info me-2" onclick="runManualCheckout()">
                            <i class="fas fa-play me-1"></i>Run Manual Checkout
                        </button>
                        <a href="auto_checkout_logs.php" class="btn btn-secondary">
                            <i class="fas fa-history me-1"></i>Checkout Logs
                        </a>
                    </div>
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <?php foreach ($rooms as $room): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card room-card room-<?php echo $room['status']; ?>">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="fas fa-door-open me-2"></i>
                                        Room <?php echo $room['room_number']; ?>
                                    </h5>
                                    <span class="badge bg-<?php 
                                        echo $room['status'] === 'available' ? 'success' : 
                                            ($room['status'] === 'occupied' ? 'danger' : 
                                            ($room['status'] === 'cleaning' ? 'warning' : 'secondary')); 
                                    ?>">
                                        <?php echo ucfirst($room['status']); ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Type:</strong> <?php echo $room['room_type']; ?></p>
                                    
                                    <?php if ($room['status'] === 'occupied'): ?>
                                        <hr>
                                        <p><strong>Guest:</strong> <?php echo $room['guest_name']; ?></p>
                                        <p><strong>Phone:</strong> <?php echo $room['guest_phone']; ?></p>
                                        <p><strong>Email:</strong> <?php echo $room['guest_email']; ?></p>
                                        <p><strong>Check-in:</strong> <?php echo $room['check_in_date'] . ' ' . $room['check_in_time']; ?></p>
                                        <p><strong>Check-out:</strong> <?php echo $room['check_out_date']; ?></p>
                                        
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                            <button type="submit" name="checkout_room" class="btn btn-danger btn-sm" 
                                                    onclick="return confirm('Are you sure you want to checkout this room?')">
                                                <i class="fas fa-sign-out-alt me-1"></i>Manual Checkout
                                            </button>
                                        </form>
                                    <?php elseif ($room['status'] === 'available'): ?>
                                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" 
                                                data-bs-target="#checkinModal<?php echo $room['id']; ?>">
                                            <i class="fas fa-sign-in-alt me-1"></i>Check In
                                        </button>
                                    <?php elseif ($room['status'] === 'cleaning'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                            <button type="submit" name="make_available" class="btn btn-success btn-sm">
                                                <i class="fas fa-check me-1"></i>Mark Available
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <div class="room-notice">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <strong>Daily 10AM Auto Checkout</strong><br>
                                        This room will be automatically checked out at 10:00 AM if occupied
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Check-in Modal -->
                        <div class="modal fade" id="checkinModal<?php echo $room['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Check In - Room <?php echo $room['room_number']; ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Guest Name *</label>
                                                <input type="text" class="form-control" name="guest_name" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Phone Number *</label>
                                                <input type="tel" class="form-control" name="guest_phone" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="guest_email">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Expected Check-out Date *</label>
                                                <input type="date" class="form-control" name="check_out_date" 
                                                       min="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                            <div class="alert alert-info">
                                                <i class="fas fa-clock me-2"></i>
                                                <strong>Auto Checkout Notice:</strong> This room will be automatically 
                                                checked out at 10:00 AM daily regardless of the expected checkout date.
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="checkin_room" class="btn btn-primary">Check In</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function runManualCheckout() {
            if (confirm('Are you sure you want to run manual checkout for all rooms?')) {
                fetch('../cron/auto_checkout_cron.php?manual_run=1')
                    .then(response => response.json())
                    .then(data => {
                        alert('Manual checkout completed: ' + data.status + 
                              '\nChecked out: ' + (data.checked_out || 0) + ' rooms' +
                              '\nFailed: ' + (data.failed || 0) + ' rooms');
                        location.reload();
                    })
                    .catch(error => {
                        alert('Error running manual checkout: ' + error);
                    });
            }
        }

        // Auto-refresh page every 5 minutes to show updated room status
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>