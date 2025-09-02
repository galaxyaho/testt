<?php
/**
 * Enhanced Auto Checkout System for L.P.S.T Hotel Booking System
 * Fixed to work properly with daily 10am automatic checkout
 */

class AutoCheckout {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        // Set timezone for this instance
        date_default_timezone_set('Asia/Kolkata');
    }
    
    /**
     * Execute daily auto checkout at configured time
     */
    public function executeDailyCheckout() {
        try {
            // Get system settings
            $settings = $this->getSystemSettings();
            
            if (!$settings['auto_checkout_enabled']) {
                return ['status' => 'disabled', 'message' => 'Auto checkout is disabled'];
            }
            
            $currentTime = date('H:i');
            $checkoutTime = $settings['auto_checkout_time'];
            $today = date('Y-m-d');
            
            // Allow manual execution anytime for testing
            $isManualRun = isset($_GET['manual_run']) || isset($_GET['test']) || 
                          (php_sapi_name() !== 'cli' && isset($_SERVER['REQUEST_METHOD']));
            
            // Check if it's time for auto checkout (only for automatic cron runs)
            if (!$isManualRun && php_sapi_name() === 'cli') {
                if ($currentTime < $checkoutTime) {
                    return ['status' => 'not_time', 'message' => "Not yet time for auto checkout. Current: $currentTime, Scheduled: $checkoutTime"];
                }
                
                // Check if auto checkout already ran today
                $lastRun = $settings['last_auto_checkout_run'];
                if ($lastRun && date('Y-m-d', strtotime($lastRun)) === $today) {
                    return ['status' => 'already_run', 'message' => 'Auto checkout already executed today'];
                }
            }
            
            // Get all active bookings that need checkout
            $bookings = $this->getBookingsForCheckout();
            $checkedOutBookings = [];
            $failedBookings = [];
            
            if (empty($bookings)) {
                return [
                    'status' => 'no_bookings',
                    'message' => 'No active bookings found for checkout',
                    'checked_out' => 0,
                    'failed' => 0
                ];
            }
            
            foreach ($bookings as $booking) {
                $result = $this->checkoutBooking($booking);
                if ($result['success']) {
                    $checkedOutBookings[] = $booking;
                } else {
                    $failedBookings[] = ['booking' => $booking, 'error' => $result['error']];
                }
            }
            
            // Update last run time only for automatic runs
            if (!$isManualRun || php_sapi_name() === 'cli') {
                $this->updateLastRunTime();
            }
            
            return [
                'status' => 'completed',
                'checked_out' => count($checkedOutBookings),
                'failed' => count($failedBookings),
                'total_processed' => count($bookings),
                'details' => [
                    'successful' => $checkedOutBookings,
                    'failed' => $failedBookings
                ],
                'run_type' => $isManualRun ? 'manual' : 'automatic',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            error_log("Auto checkout error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get active bookings that need to be checked out
     */
    private function getBookingsForCheckout() {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // Get all active bookings that should be checked out
        $stmt = $this->pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, r.type
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            WHERE b.status IN ('BOOKED', 'PENDING')
            AND (
                DATE(b.check_in) <= ?
                OR b.auto_checkout_processed = 0
            )
            AND b.id NOT IN (
                SELECT DISTINCT booking_id 
                FROM auto_checkout_logs 
                WHERE DATE(created_at) = ? AND status = 'success'
                AND booking_id IS NOT NULL
            )
            ORDER BY b.check_in ASC
        ");
        $stmt->execute([$today, $today]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Checkout a specific booking
     */
    private function checkoutBooking($booking) {
        try {
            $this->pdo->beginTransaction();
            
            // Calculate duration
            $checkInTime = $booking['actual_check_in'] ?: $booking['check_in'];
            $checkOutTime = date('Y-m-d H:i:s');
            
            $start = new DateTime($checkInTime);
            $end = new DateTime($checkOutTime);
            $diff = $start->diff($end);
            $durationMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
            
            // Calculate amount based on duration (₹100 per hour for rooms, ₹500 per hour for halls)
            $hourlyRate = $booking['type'] === 'hall' ? 500 : 100;
            $hours = max(1, ceil($durationMinutes / 60)); // Minimum 1 hour
            $amount = $hours * $hourlyRate;
            
            // Update booking status to completed
            $stmt = $this->pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED',
                    actual_check_out = ?,
                    duration_minutes = ?,
                    total_amount = ?,
                    auto_checkout_processed = 1,
                    actual_checkout_date = CURDATE(),
                    actual_checkout_time = CURTIME()
                WHERE id = ?
            ");
            $stmt->execute([$checkOutTime, $durationMinutes, $amount, $booking['id']]);
            
            // Create payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO payments 
                (booking_id, resource_id, amount, payment_method, payment_status, payment_notes, admin_id) 
                VALUES (?, ?, ?, 'AUTO_CHECKOUT', 'COMPLETED', ?, 1)
            ");
            $paymentNotes = "Auto checkout at {$checkOutTime} - Duration: {$hours}h - Rate: ₹{$hourlyRate}/hour";
            $stmt->execute([$booking['id'], $booking['resource_id'], $amount, $paymentNotes]);
            
            // Log the auto checkout
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_logs 
                (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, 'success', ?)
            ");
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $notes = "Automatic checkout - Duration: {$hours}h - Amount: ₹{$amount}";
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $resourceName,
                $booking['client_name'],
                date('Y-m-d'),
                date('H:i:s'),
                $notes
            ]);
            
            // Send checkout SMS if SMS is configured
            try {
                if (file_exists(__DIR__ . '/sms_functions.php')) {
                    require_once __DIR__ . '/sms_functions.php';
                    send_checkout_confirmation_sms($booking['id'], $this->pdo);
                }
            } catch (Exception $e) {
                // SMS failure shouldn't stop checkout
                error_log("SMS failed during auto checkout: " . $e->getMessage());
            }
            
            $this->pdo->commit();
            return ['success' => true, 'amount' => $amount, 'duration' => $hours];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            // Log failed checkout
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO auto_checkout_logs 
                    (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, 'failed', ?)
                ");
                $resourceName = $booking['custom_name'] ?: $booking['display_name'];
                $stmt->execute([
                    $booking['id'],
                    $booking['resource_id'],
                    $resourceName,
                    $booking['client_name'],
                    date('Y-m-d'),
                    date('H:i:s'),
                    'Error: ' . $e->getMessage()
                ]);
            } catch (Exception $logError) {
                error_log("Failed to log auto checkout error: " . $logError->getMessage());
            }
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get system settings with defaults
     */
    private function getSystemSettings() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings");
            $settings = [];
            
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Set defaults if not found
            return [
                'auto_checkout_enabled' => $settings['auto_checkout_enabled'] ?? '1',
                'auto_checkout_time' => $settings['auto_checkout_time'] ?? '10:00',
                'timezone' => $settings['timezone'] ?? 'Asia/Kolkata',
                'last_auto_checkout_run' => $settings['last_auto_checkout_run'] ?? ''
            ];
        } catch (Exception $e) {
            // Return defaults if settings table doesn't exist
            return [
                'auto_checkout_enabled' => '1',
                'auto_checkout_time' => '10:00',
                'timezone' => 'Asia/Kolkata',
                'last_auto_checkout_run' => ''
            ];
        }
    }
    
    /**
     * Update last run time
     */
    private function updateLastRunTime() {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES ('last_auto_checkout_run', NOW()) 
                ON DUPLICATE KEY UPDATE setting_value = NOW()
            ");
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to update last run time: " . $e->getMessage());
        }
    }
    
    /**
     * Get checkout statistics
     */
    public function getCheckoutStats() {
        try {
            $today = date('Y-m-d');
            
            // Today's auto checkouts
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count, 
                       COALESCE(SUM(p.amount), 0) as total_amount
                FROM auto_checkout_logs acl
                LEFT JOIN payments p ON acl.booking_id = p.booking_id AND p.payment_method = 'AUTO_CHECKOUT'
                WHERE DATE(acl.created_at) = ? AND acl.status = 'success'
            ");
            $stmt->execute([$today]);
            $todayStats = $stmt->fetch();
            
            // This week's auto checkouts
            $weekStart = date('Y-m-d', strtotime('-7 days'));
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count, 
                       COALESCE(SUM(p.amount), 0) as total_amount
                FROM auto_checkout_logs acl
                LEFT JOIN payments p ON acl.booking_id = p.booking_id AND p.payment_method = 'AUTO_CHECKOUT'
                WHERE DATE(acl.created_at) >= ? AND acl.status = 'success'
            ");
            $stmt->execute([$weekStart]);
            $weekStats = $stmt->fetch();
            
            return [
                'today' => [
                    'count' => $todayStats['count'] ?: 0,
                    'amount' => $todayStats['total_amount'] ?: 0
                ],
                'week' => [
                    'count' => $weekStats['count'] ?: 0,
                    'amount' => $weekStats['total_amount'] ?: 0
                ]
            ];
        } catch (Exception $e) {
            return [
                'today' => ['count' => 0, 'amount' => 0],
                'week' => ['count' => 0, 'amount' => 0]
            ];
        }
    }
    
    /**
     * Manual test function for admin/owner
     */
    public function testAutoCheckout() {
        return $this->executeDailyCheckout();
    }
}
?>