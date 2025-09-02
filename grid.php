<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Auto-update pending bookings
update_pending_bookings($pdo);

// Get all resources
$stmt = $pdo->prepare("SELECT * FROM resources WHERE is_active = 1 ORDER BY type, CAST(identifier AS UNSIGNED), identifier");
$stmt->execute();
$resources = $stmt->fetchAll();

// Get today's advanced bookings
$todayAdvanced = get_today_advanced_bookings($pdo);
$todayAdvancedIds = array_column($todayAdvanced, 'resource_id');

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="advanced.php" class="nav-button">Advanced Booking</a>
            <a href="admin/auto_checkout_settings.php" class="nav-button">Auto Checkout</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <a href="admin/profile.php" class="nav-button">Profile</a>
            <span style="margin-right: 1rem;">Welcome, <?= htmlspecialchars($_SESSION['username']) ?></span>
            <a href="logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <h2>Room & Hall Status</h2>
        
        <!-- Auto Checkout Notice -->
        <?php
        // Get auto checkout settings
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auto_checkout_enabled', 'auto_checkout_time')");
        $autoSettings = [];
        while ($row = $stmt->fetch()) {
            $autoSettings[$row['setting_key']] = $row['setting_value'];
        }
        $autoEnabled = ($autoSettings['auto_checkout_enabled'] ?? '1') === '1';
        $autoTime = $autoSettings['auto_checkout_time'] ?? '10:00';
        ?>
        
        <?php if ($autoEnabled): ?>
            <div style="background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center; font-weight: bold; box-shadow: 0 4px 15px rgba(40,167,69,0.3);">
                🕙 DAILY AUTO CHECKOUT ACTIVE - All active bookings will be automatically checked out at <?= $autoTime ?> daily
                <br><small style="opacity: 0.9;">Next auto checkout: Tomorrow at <?= $autoTime ?></small>
            </div>
        <?php else: ?>
            <div style="background: linear-gradient(45deg, #dc3545, #c82333); color: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center; font-weight: bold;">
                ⚠️ AUTO CHECKOUT DISABLED - Manual checkout required for all bookings
                <br><small style="opacity: 0.9;"><a href="admin/auto_checkout_settings.php" style="color: white; text-decoration: underline;">Enable Auto Checkout</a></small>
            </div>
        <?php endif; ?>
        
        <div class="resources-grid">
            <?php foreach ($resources as $resource): ?>
                <?php 
                $booking = get_resource_status($resource['id'], $pdo);
                $isOccupied = $booking !== false;
                $isTodayAdvance = in_array($resource['id'], $todayAdvancedIds);
                
                // Check for any advanced booking (not just today)
                $hasAdvancedBooking = has_advanced_booking($resource['id'], $pdo);
                
                $displayName = $resource['custom_name'] ?: $resource['display_name'];
                $boxClass = 'vacant';
                if ($isOccupied) {
                    $boxClass = $booking['status'] === 'PAID' ? 'paid' : 'occupied';
                } elseif ($isTodayAdvance) {
                    $boxClass = 'today-advance';
                } elseif ($hasAdvancedBooking) {
                    $boxClass = 'advanced-booked';
                }
                
                // Add auto checkout info to each resource
                $autoCheckoutNotice = $autoEnabled ? "Daily auto checkout at {$autoTime}" : "Auto checkout disabled";
                ?>
                
                <div class="resource-box <?= $boxClass ?>" data-resource-id="<?= $resource['id'] ?>" onclick="toggleResourceBox(this)">
                    <div class="resource-number">
                        <?php 
                        // Show custom name or extract number from room names, or show hall name
                        if ($resource['type'] === 'room') {
                            // If there's a custom name, show it, otherwise show room number
                            if ($resource['custom_name']) {
                                echo htmlspecialchars($resource['custom_name']);
                            } else {
                                preg_match('/\d+/', $displayName, $matches);
                                echo $matches[0] ?? $resource['identifier'];
                            }
                        } else {
                            // For halls, show custom name or abbreviation
                            if ($resource['custom_name']) {
                                echo htmlspecialchars($resource['custom_name']);
                            } else {
                                echo $resource['type'] === 'hall' && strpos($displayName, 'SMALL') !== false ? 'SH' : 'BH';
                            }
                        }
                        ?>
                    </div>
                    
                    <div class="resource-subtitle">
                        <?= htmlspecialchars($displayName) ?>
                    </div>
                    
                    <?php if ($isOccupied): ?>
                        <div class="resource-info">
                            <?= htmlspecialchars($booking['client_name']) ?>
                        </div>
                        <div class="resource-details">
                            <?= date('M j, g:i A', strtotime($booking['check_in'])) ?>
                        </div>
                        <?php if ($booking['status'] === 'PAID'): ?>
                            <?php 
                            $duration = calculate_duration($booking['actual_check_in'] ?: $booking['check_in'], $booking['actual_check_out']);
                            ?>
                            <div class="resource-details">
                                Duration: <?= $duration['formatted'] ?>
                            </div>
                        <?php elseif ($booking['status'] !== 'PENDING'): ?>
                            <div class="live-counter" data-checkin="<?= $booking['check_in'] ?>" style="font-size: 16px; margin: 0.5rem 0;">
                                Calculating...
                            </div>
                        <?php else: ?>
                            <div class="resource-details" style="color: #ffeb3b;">
                                ⚠️ REQUIRES ACTION
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$booking['is_paid']): ?>
                            <div class="status-badge">UNPAID</div>
                        <?php endif; ?>
                    <?php elseif ($isTodayAdvance): ?>
                        <div class="resource-info">ADVANCE BOOKING</div>
                        <div class="resource-details">Ready for Check-in</div>
                        <div class="today-badge">DUE TODAY</div>
                    <?php endif; ?>
                    
                    <div class="status-badge status-<?= $isOccupied ? strtolower($booking['status']) : 'vacant' ?>">
                        <?= $isOccupied ? $booking['status'] : 'VACANT' ?>
                    </div>
                    
                    <!-- Auto Checkout Notice -->
                    <div style="font-size: 12px; color: rgba(255,255,255,0.8); margin-top: 0.5rem; text-align: center;">
                        <?= $autoCheckoutNotice ?>
                    </div>
                    
                    <div class="action-buttons">
                        <?php if (!$isOccupied && !$isTodayAdvance): ?>
                            <a href="booking_new.php?type=<?= $resource['type'] ?>&id=<?= $resource['id'] ?>" class="btn btn-primary" onclick="event.stopPropagation()">Book Now</a>
                        <?php elseif ($isTodayAdvance): ?>
                            <a href="booking_advance_convert.php?resource_id=<?= $resource['id'] ?>" class="btn btn-success" onclick="event.stopPropagation()">Convert to Active</a>
                            <form method="POST" action="booking_actions.php" style="display: inline;" onclick="event.stopPropagation()">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="cancel_advanced">
                                <input type="hidden" name="booking_id" value="<?= $todayAdvanced[array_search($resource['id'], $todayAdvancedIds)]['id'] ?>">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Cancel advanced booking?')">Cancel Advanced</button>
                            </form>
                        <?php elseif ($hasAdvancedBooking): ?>
                            <?php $advancedDetails = get_advanced_booking_details($resource['id'], $pdo); ?>
                            <div class="resource-info" style="color: white; font-weight: 600;">UNAVAILABLE</div>
                            <div class="resource-details" style="color: white;">Advanced booking exists</div>
                            <form method="POST" action="booking_actions.php" style="display: inline;" onclick="event.stopPropagation()">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="cancel_advanced">
                                <input type="hidden" name="booking_id" value="<?= $advancedDetails['id'] ?>">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Cancel advanced booking for <?= date('M j, Y', strtotime($advancedDetails['advance_date'])) ?>?')">Cancel Advanced</button>
                            </form>
                        <?php elseif ($isOccupied && $booking['status'] !== 'PAID'): ?>
                            <a href="booking_manage.php?id=<?= $booking['id'] ?>" class="btn btn-warning" onclick="event.stopPropagation()">Details</a>
                            <?php if ($booking['admin_id'] == $_SESSION['user_id']): ?>
                                <a href="booking_edit.php?id=<?= $booking['id'] ?>" class="btn btn-primary" onclick="event.stopPropagation()">Edit Details</a>
                            <?php endif; ?>
                            <?php if (!$booking['is_paid']): ?>
                                <form method="POST" action="booking_actions.php" style="display: inline;" onclick="event.stopPropagation()">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="action" value="mark_paid">
                                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                    <button type="button" class="btn btn-success" onclick="event.stopPropagation(); showPaymentModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($displayName) ?>')">Mark Paid</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-danger" onclick="event.stopPropagation(); showCheckoutModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($displayName) ?>')">Checkout</button>
                            <form method="POST" action="booking_actions.php" style="display: inline;" onclick="event.stopPropagation()">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="cancel_booking">
                                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                <button type="submit" class="btn btn-cancel" onclick="return confirm('Cancel this booking?')">Cancel Booking</button>
                            </form>
                        <?php endif; ?>
                        
                        <button onclick="event.stopPropagation(); openPaymentModal(<?= $resource['id'] ?>, '<?= htmlspecialchars($displayName) ?>')" 
                                class="btn btn-outline">Payment</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Advanced Booking Button -->
        <div style="text-align: center; margin: 2rem 0;">
            <a href="advanced.php" class="btn btn-primary" style="font-size: 1.2rem; padding: 1rem 2rem;">
                📅 Advanced Booking
            </a>
        </div>
        
        <!-- All Advanced Bookings Display -->
        <?php 
        $allAdvanced = get_all_advanced_bookings($pdo);
        if (!empty($allAdvanced)): 
        ?>
            <div class="advanced-section">
                <h3>All Advanced Bookings</h3>
                <div class="advanced-booking-grid">
                    <?php foreach ($allAdvanced as $booking): ?>
                        <?php 
                        $isToday = $booking['advance_date'] === date('Y-m-d');
                        $displayName = $booking['custom_name'] ?: $booking['display_name'];
                        ?>
                        <div class="advanced-booking-box <?= $isToday ? 'today' : '' ?>">
                            <div class="advanced-room-number">
                                <?php 
                                // Show room number or custom name like main grid
                                if ($booking['type'] === 'room') {
                                    if ($booking['custom_name']) {
                                        echo htmlspecialchars($booking['custom_name']);
                                    } else {
                                        preg_match('/\d+/', $booking['display_name'], $matches);
                                        echo $matches[0] ?? $booking['identifier'];
                                    }
                                } else {
                                    if ($booking['custom_name']) {
                                        echo htmlspecialchars($booking['custom_name']);
                                    } else {
                                        echo strpos($booking['display_name'], 'SMALL') !== false ? 'SH' : 'BH';
                                    }
                                }
                                ?>
                            </div>
                            
                            <div class="advanced-room-name">
                                <?= htmlspecialchars($displayName) ?>
                            </div>
                            
                            <div style="font-size: 1rem; margin-bottom: 1rem;">
                                <strong>Date:</strong> <?= date('M j, Y', strtotime($booking['advance_date'])) ?><br>
                                <strong>Client:</strong> <?= htmlspecialchars($booking['client_name']) ?><br>
                                <strong>Status:</strong> 
                                <span class="status-badge status-advanced">ADVANCED BOOKED</span>
                            </div>
                            
                            <?php if ($isToday): ?>
                                <div style="margin: 1rem 0;">
                                    <span style="background: var(--warning-color); color: white; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600;">
                                        🔔 DUE TODAY
                                    </span>
                                </div>
                                <a href="booking_advance_convert.php?resource_id=<?= $booking['resource_id'] ?>" 
                                   class="btn btn-success" style="width: 100%;">
                                    ✅ Check-in Now
                                </a>
                            <?php else: ?>
                                <div style="color: var(--dark-color); font-weight: 600;">
                                    <?php 
                                    $days = ceil((strtotime($booking['advance_date']) - time()) / (24 * 60 * 60));
                                    echo $days > 0 ? "In $days days" : "Today";
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($todayAdvanced)): ?>
            <div class="advanced-section">
                <h3>Today's Advanced Bookings Ready for Check-in</h3>
                <div class="advanced-grid">
                    <?php foreach ($todayAdvanced as $booking): ?>
                        <div class="advanced-box">
                            <strong><?= htmlspecialchars($booking['display_name']) ?></strong><br>
                            Client: <?= htmlspecialchars($booking['client_name']) ?><br>
                            <a href="booking_advance_convert.php?resource_id=<?= $booking['resource_id'] ?>" 
                               class="btn btn-success" style="margin-top: 0.5rem;">Convert to Active Booking</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Payment Modal for New Payments -->
    <div id="paymentModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Make Payment</h3>
                <button type="button" class="close-modal" onclick="closePaymentModal()">&times;</button>
            </div>
            <form id="paymentForm" method="POST" action="payment_process.php">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="resource_id" id="paymentResourceId">
                
                <div class="form-group">
                    <label class="form-label">Resource</label>
                    <input type="text" id="paymentResourceName" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="amount" class="form-label">Amount (₹)</label>
                    <input type="number" id="amount" name="amount" class="form-control" min="1" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <div style="margin: 0.5rem 0;">
                        <input type="radio" id="upi_payment" name="payment_method" value="upi" checked>
                        <label for="upi_payment">UPI Payment</label>
                    </div>
                    <div>
                        <input type="radio" id="manual_payment" name="payment_method" value="manual">
                        <label for="manual_payment">Manual Payment (Cash/Card)</label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Process Payment</button>
            </form>
        </div>
    </div>

    <!-- Mark Paid Modal -->
    <div id="markPaidModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Mark as Paid</h3>
                <button type="button" class="close-modal" onclick="closeMarkPaidModal()">&times;</button>
            </div>
            <form method="POST" action="booking_actions.php">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="booking_id" id="markPaidBookingId">
                
                <div class="form-group">
                    <label class="form-label">Resource</label>
                    <input type="text" id="markPaidResourceName" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="paid_amount" class="form-label">Amount Received (₹) *</label>
                    <input type="number" id="paid_amount" name="amount" class="form-control" min="1" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <div style="margin: 0.5rem 0;">
                        <input type="radio" id="paid_online" name="payment_method" value="ONLINE" required>
                        <label for="paid_online">Online (UPI/Net Banking/Card)</label>
                    </div>
                    <div>
                        <input type="radio" id="paid_offline" name="payment_method" value="OFFLINE" required>
                        <label for="paid_offline">Offline (Cash)</label>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-success">Mark as Paid</button>
            </form>
        </div>
    </div>

    <!-- Checkout Modal -->
    <div id="checkoutModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Complete Checkout</h3>
                <button type="button" class="close-modal" onclick="closeCheckoutModal()">&times;</button>
            </div>
            <form method="POST" action="booking_actions.php">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="booking_id" id="checkoutBookingId">
                
                <div class="form-group">
                    <label class="form-label">Resource</label>
                    <input type="text" id="checkoutResourceName" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="checkout_datetime" class="form-label">Checkout Date & Time *</label>
                    <input type="datetime-local" id="checkout_datetime" name="checkout_datetime" class="form-control" required
                           value="<?= date('Y-m-d\TH:i', time()) ?>">
                </div>
                
                <button type="submit" class="btn btn-danger" onclick="return confirm('Complete checkout at selected time?')">Complete Checkout</button>
            </form>
        </div>
    </div>
    <script src="assets/script.js"></script>
    <script>
        function showPaymentModal(bookingId, resourceName) {
            document.getElementById('markPaidBookingId').value = bookingId;
            document.getElementById('markPaidResourceName').value = resourceName;
            document.getElementById('markPaidModal').style.display = 'flex';
        }
        
        function closeMarkPaidModal() {
            document.getElementById('markPaidModal').style.display = 'none';
        }
        
        function showCheckoutModal(bookingId, resourceName) {
            document.getElementById('checkoutBookingId').value = bookingId;
            document.getElementById('checkoutResourceName').value = resourceName;
            document.getElementById('checkoutModal').style.display = 'flex';
        }
        
        function closeCheckoutModal() {
            document.getElementById('checkoutModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            const paymentModal = document.getElementById('paymentModal');
            const markPaidModal = document.getElementById('markPaidModal');
            const checkoutModal = document.getElementById('checkoutModal');
            
            if (e.target === paymentModal) {
                closePaymentModal();
            }
            if (e.target === markPaidModal) {
                closeMarkPaidModal();
            }
            if (e.target === checkoutModal) {
                closeCheckoutModal();
            }
        });
    </script>
</body>
</html>