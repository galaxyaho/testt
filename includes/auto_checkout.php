<?php
/**
 * Enhanced Auto Checkout System for L.P.S.T Hotel Booking System
 * Fixed to work properly with manual testing and daily automatic checkout
 */

class AutoCheckout {
    private $pdo;
    private $timezone;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->timezone = 'Asia/Kolkata';
        date_default_timezone_set($this->timezone);
    }
    
    /**
     * Execute daily auto checkout - works for both manual and automatic runs
     */
    public function executeDailyCheckout() {
        try {
            $settings = $this->getSystemSettings();
            
            if (!$settings['auto_checkout_enabled']) {
                return [
                    'status' => 'disabled', 
                    'message' => 'Auto checkout is disabled in system settings',
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
            
            $currentTime = date('H:i');
            $checkoutTime = $settings['auto_checkout_time'];
            $today = date('Y-m-d');
            
            // Check if this is a manual run
            $isManualRun = $this->isManualRun();
            
            // For automatic cron runs, check if it's the right time
            if (!$isManualRun) {
                // Allow execution within 30 minutes of scheduled time
                $scheduledMinutes = $this->timeToMinutes($checkoutTime);
                $currentMinutes = $this->timeToMinutes($currentTime);
                $gracePeriod = 30; // 30 minutes grace period
                
                if (abs($currentMinutes - $scheduledMinutes) > $gracePeriod) {
                    return [
                        'status' => 'not_time',
                        'message' => "Not time for auto checkout. Current: $currentTime, Scheduled: $checkoutTime",
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                }
                
                // Check if already ran today (only for automatic runs)
                $lastRun = $settings['last_auto_checkout_run'];
                if ($lastRun && date('Y-m-d', strtotime($lastRun)) === $today) {
                    return [
                        'status' => 'already_run',
                        'message' => "Auto checkout already executed today at " . date('H:i', strtotime($lastRun)),
                        'timestamp' => date('Y-m-d H:i:s')
                    ];
                }
            }
            
            // Get bookings to checkout
            $bookings = $this->getBookingsForCheckout();
            
            if (empty($bookings)) {
                $this->updateLastRunTime(); // Update even if no bookings
                return [
                    'status' => 'no_bookings',
                    'message' => 'No active bookings found for checkout',
                    'checked_out' => 0,
                    'failed' => 0,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'run_type' => $isManualRun ? 'manual' : 'automatic'
                ];
            }
            
            $checkedOutBookings = [];
            $failedBookings = [];
            
            foreach ($bookings as $booking) {
                $result = $this->checkoutBooking($booking);
                if ($result['success']) {
                    $checkedOutBookings[] = $booking;
                } else {
                    $failedBookings[] = ['booking' => $booking, 'error' => $result['error']];
                }
            }
            
            // Update last run time
            $this->updateLastRunTime();
            
            // Log system activity
            $this->logSystemActivity(count($checkedOutBookings), count($failedBookings));
            
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
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => "Processed " . count($bookings) . " bookings: " . count($checkedOutBookings) . " successful, " . count($failedBookings) . " failed"
            ];
            
        } catch (Exception $e) {
            error_log("Auto checkout error: " . $e->getMessage());
            return [
                'status' => 'error', 
                'message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Get bookings that need to be checked out
     */
    private function getBookingsForCheckout() {
        $today = date('Y-m-d');
        
        // Get all active bookings that haven't been auto-checked out today
        $stmt = $this->pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, r.type
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            WHERE b.status IN ('BOOKED', 'PENDING')
            AND b.auto_checkout_processed = 0
            AND b.id NOT IN (
                SELECT DISTINCT COALESCE(booking_id, 0)
                FROM auto_checkout_logs 
                WHERE DATE(created_at) = ? 
                AND status = 'success'
                AND booking_id IS NOT NULL
            )
            ORDER BY b.check_in ASC
        ");
        $stmt->execute([$today]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Checkout a specific booking
     */
    private function checkoutBooking($booking) {
        try {
            $this->pdo->beginTransaction();
            
            $checkOutTime = date('Y-m-d H:i:s');
            $checkInTime = $booking['actual_check_in'] ?: $booking['check_in'];
            
            // Calculate duration
            $start = new DateTime($checkInTime);
            $end = new DateTime($checkOutTime);
            $diff = $start->diff($end);
            $durationMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
            
            // Get rates from settings
            $settings = $this->getSystemSettings();
            $roomRate = floatval($settings['auto_checkout_rate_room'] ?? 100);
            $hallRate = floatval($settings['auto_checkout_rate_hall'] ?? 500);
            
            $hourlyRate = $booking['type'] === 'hall' ? $hallRate : $roomRate;
            $hours = max(1, ceil($durationMinutes / 60)); // Minimum 1 hour
            $amount = $hours * $hourlyRate;
            
            // Update booking
            $stmt = $this->pdo->prepare("
                UPDATE bookings 
                SET status = 'COMPLETED',
                    actual_check_out = ?,
                    duration_minutes = ?,
                    total_amount = ?,
                    auto_checkout_processed = 1,
                    actual_checkout_date = CURDATE(),
                    actual_checkout_time = CURTIME(),
                    is_paid = 1
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
            
            // Log the checkout
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $notes = "Automatic checkout - Duration: {$hours}h - Amount: ₹{$amount}";
            
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_logs 
                (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, 'success', ?)
            ");
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $resourceName,
                $booking['client_name'],
                date('Y-m-d'),
                date('H:i:s'),
                $notes
            ]);
            
            // Send SMS if available
            $this->sendCheckoutSMS($booking);
            
            $this->pdo->commit();
            return [
                'success' => true, 
                'amount' => $amount, 
                'duration' => $hours,
                'resource_name' => $resourceName
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            // Log failed checkout
            $this->logFailedCheckout($booking, $e->getMessage());
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if this is a manual run
     */
    private function isManualRun() {
        return isset($_GET['manual_run']) || 
               isset($_GET['test']) || 
               (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') ||
               (php_sapi_name() !== 'cli');
    }
    
    /**
     * Convert time string to minutes
     */
    private function timeToMinutes($time) {
        list($hours, $minutes) = explode(':', $time);
        return ($hours * 60) + $minutes;
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
            
            return [
                'auto_checkout_enabled' => ($settings['auto_checkout_enabled'] ?? '1') === '1',
                'auto_checkout_time' => $settings['auto_checkout_time'] ?? '10:00',
                'timezone' => $settings['timezone'] ?? 'Asia/Kolkata',
                'last_auto_checkout_run' => $settings['last_auto_checkout_run'] ?? '',
                'checkout_grace_minutes' => intval($settings['checkout_grace_minutes'] ?? 30),
                'auto_checkout_rate_room' => $settings['auto_checkout_rate_room'] ?? '100',
                'auto_checkout_rate_hall' => $settings['auto_checkout_rate_hall'] ?? '500'
            ];
        } catch (Exception $e) {
            // Return defaults if table doesn't exist
            return [
                'auto_checkout_enabled' => true,
                'auto_checkout_time' => '10:00',
                'timezone' => 'Asia/Kolkata',
                'last_auto_checkout_run' => '',
                'checkout_grace_minutes' => 30,
                'auto_checkout_rate_room' => '100',
                'auto_checkout_rate_hall' => '500'
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
     * Log failed checkout
     */
    private function logFailedCheckout($booking, $error) {
        try {
            $resourceName = $booking['custom_name'] ?: $booking['display_name'];
            $stmt = $this->pdo->prepare("
                INSERT INTO auto_checkout_logs 
                (booking_id, resource_id, resource_name, guest_name, checkout_date, checkout_time, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, 'failed', ?)
            ");
            $stmt->execute([
                $booking['id'],
                $booking['resource_id'],
                $resourceName,
                $booking['client_name'],
                date('Y-m-d'),
                date('H:i:s'),
                'Error: ' . $error
            ]);
        } catch (Exception $e) {
            error_log("Failed to log checkout error: " . $e->getMessage());
        }
    }
    
    /**
     * Log system activity
     */
    private function logSystemActivity($successful, $failed) {
        try {
            $description = "Auto checkout completed: {$successful} successful, {$failed} failed";
            $stmt = $this->pdo->prepare("
                INSERT INTO activity_logs (activity_type, description) 
                VALUES ('auto_checkout', ?)
            ");
            $stmt->execute([$description]);
        } catch (Exception $e) {
            error_log("Failed to log system activity: " . $e->getMessage());
        }
    }
    
    /**
     * Send checkout SMS
     */
    private function sendCheckoutSMS($booking) {
        try {
            if (file_exists(__DIR__ . '/sms_functions.php')) {
                require_once __DIR__ . '/sms_functions.php';
                send_checkout_confirmation_sms($booking['id'], $this->pdo);
            }
        } catch (Exception $e) {
            error_log("SMS failed during auto checkout: " . $e->getMessage());
        }
    }
    
    /**
     * Get checkout statistics
     */
    public function getCheckoutStats() {
        try {
            $today = date('Y-m-d');
            $weekStart = date('Y-m-d', strtotime('-7 days'));
            
            // Today's stats
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count, 
                       COALESCE(SUM(p.amount), 0) as total_amount
                FROM auto_checkout_logs acl
                LEFT JOIN payments p ON acl.booking_id = p.booking_id AND p.payment_method = 'AUTO_CHECKOUT'
                WHERE DATE(acl.created_at) = ? AND acl.status = 'success'
            ");
            $stmt->execute([$today]);
            $todayStats = $stmt->fetch();
            
            // Week's stats
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
     * Test auto checkout (for manual testing)
     */
    public function testAutoCheckout() {
        return $this->executeDailyCheckout();
    }
    
    /**
     * Force checkout all active bookings (for emergency use)
     */
    public function forceCheckoutAll() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.*, r.display_name, r.custom_name, r.type
                FROM bookings b 
                JOIN resources r ON b.resource_id = r.id 
                WHERE b.status IN ('BOOKED', 'PENDING')
                ORDER BY b.check_in ASC
            ");
            $stmt->execute();
            $bookings = $stmt->fetchAll();
            
            $results = [];
            foreach ($bookings as $booking) {
                $results[] = $this->checkoutBooking($booking);
            }
            
            return [
                'status' => 'force_completed',
                'total_processed' => count($bookings),
                'results' => $results,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}
?>