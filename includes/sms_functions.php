<?php
/**
 * SMS Functions for L.P.S.T Bookings System
 * Handles SMS sending for booking notifications
 */

/**
 * Send SMS using configurable API
 * @param string $mobile - 10 digit mobile number
 * @param string $message - SMS message content
 * @param string $sms_type - Type of SMS (BOOKING, CHECKOUT, CANCELLATION, ADVANCE)
 * @param int $booking_id - Booking ID for logging
 * @param int $admin_id - Admin ID who triggered the SMS
 * @param PDO $pdo - Database connection
 * @return array - Response with success status and message
 */
function send_sms($mobile, $message, $sms_type, $booking_id, $admin_id, $pdo) {
    try {
        // Validate mobile number (must be 10 digits)
        if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
            throw new Exception('Invalid mobile number format. Must be 10 digits starting with 6-9.');
        }
        
        // Get SMS settings from database
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_url', 'sms_api_key', 'sms_sender_id')");
        $stmt->execute();
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        $api_url = $settings['sms_api_url'] ?? '';
        $api_key = $settings['sms_api_key'] ?? '';
        $sender_id = $settings['sms_sender_id'] ?? 'LPSTHT';
        
        if (empty($api_url) || empty($api_key)) {
            throw new Exception('SMS API configuration not found. Please configure SMS settings.');
        }
        
        // Log SMS attempt
        $stmt = $pdo->prepare("
            INSERT INTO sms_logs (booking_id, mobile_number, message, sms_type, status, admin_id) 
            VALUES (?, ?, ?, ?, 'PENDING', ?)
        ");
        $stmt->execute([$booking_id, $mobile, $message, $sms_type, $admin_id]);
        $sms_log_id = $pdo->lastInsertId();
        
        // Prepare SMS data based on API provider
        // This example uses TextLocal API format - modify according to your SMS provider
        $post_data = [
            'apikey' => $api_key,
            'numbers' => $mobile,
            'message' => $message,
            'sender' => $sender_id
        ];
        
        // Alternative formats for different SMS providers:
        
        // For MSG91 API:
        // $post_data = [
        //     'authkey' => $api_key,
        //     'mobiles' => $mobile,
        //     'message' => $message,
        //     'sender' => $sender_id,
        //     'route' => '4'
        // ];
        
        // For Fast2SMS API:
        // $post_data = [
        //     'authorization' => $api_key,
        //     'numbers' => $mobile,
        //     'message' => $message,
        //     'sender_id' => $sender_id,
        //     'route' => 'p'
        // ];
        
        // Send SMS via cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // Add headers if required by your SMS provider
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            throw new Exception('cURL Error: ' . $curl_error);
        }
        
        // Parse response (modify according to your SMS provider's response format)
        $response_data = json_decode($response, true);
        
        // Check if SMS was sent successfully
        $success = false;
        $error_message = '';
        
        // TextLocal API response format
        if ($http_code == 200 && isset($response_data['status']) && $response_data['status'] == 'success') {
            $success = true;
        } else {
            $error_message = $response_data['errors'][0]['message'] ?? 'SMS sending failed';
        }
        
        // Update SMS log with response
        $status = $success ? 'SENT' : 'FAILED';
        $stmt = $pdo->prepare("
            UPDATE sms_logs 
            SET status = ?, response_data = ? 
            WHERE id = ?
        ");
        $stmt->execute([$status, $response, $sms_log_id]);
        
        if ($success) {
            // Update booking SMS status
            $stmt = $pdo->prepare("UPDATE bookings SET sms_sent = 1 WHERE id = ?");
            $stmt->execute([$booking_id]);
            
            return [
                'success' => true,
                'message' => 'SMS sent successfully',
                'response' => $response_data
            ];
        } else {
            return [
                'success' => false,
                'message' => $error_message,
                'response' => $response_data
            ];
        }
        
    } catch (Exception $e) {
        // Log error
        if (isset($sms_log_id)) {
            $stmt = $pdo->prepare("
                UPDATE sms_logs 
                SET status = 'FAILED', response_data = ? 
                WHERE id = ?
            ");
            $stmt->execute(['Error: ' . $e->getMessage(), $sms_log_id]);
        }
        
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'response' => null
        ];
    }
}

/**
 * Generate booking confirmation SMS message
 */
function generate_booking_sms($client_name, $resource_name, $check_in_time, $hotel_name) {
    return "Dear {$client_name}, your room {$resource_name} booked successfully at {$check_in_time} at {$hotel_name}. Thank you!";
}

/**
 * Generate checkout SMS message
 */
function generate_checkout_sms($client_name, $resource_name, $hotel_name) {
    return "Dear {$client_name}, checkout from {$resource_name} completed at {$hotel_name}. Thank you for your visit! Please visit again.";
}

/**
 * Generate cancellation SMS message
 */
function generate_cancellation_sms($client_name, $resource_name, $hotel_name) {
    return "Dear {$client_name}, your booking for {$resource_name} at {$hotel_name} has been cancelled. Thank you.";
}

/**
 * Generate advance booking SMS message
 */
function generate_advance_booking_sms($client_name, $resource_name, $advance_date, $hotel_name) {
    return "Dear {$client_name}, your advance booking for {$resource_name} on {$advance_date} at {$hotel_name} confirmed. Thank you!";
}

/**
 * Send booking confirmation SMS
 */
function send_booking_confirmation_sms($booking_id, $pdo) {
    try {
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, s.setting_value as hotel_name
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            LEFT JOIN settings s ON s.setting_key = 'hotel_name'
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception('Booking not found');
        }
        
        $resource_name = $booking['custom_name'] ?: $booking['display_name'];
        $hotel_name = $booking['hotel_name'] ?: 'L.P.S.T Hotel';
        $check_in_time = date('d-M-Y H:i', strtotime($booking['check_in']));
        
        $message = generate_booking_sms(
            $booking['client_name'],
            $resource_name,
            $check_in_time,
            $hotel_name
        );
        
        return send_sms(
            $booking['client_mobile'],
            $message,
            'BOOKING',
            $booking_id,
            $booking['admin_id'],
            $pdo
        );
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send checkout confirmation SMS
 */
function send_checkout_confirmation_sms($booking_id, $pdo) {
    try {
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, s.setting_value as hotel_name
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            LEFT JOIN settings s ON s.setting_key = 'hotel_name'
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception('Booking not found');
        }
        
        $resource_name = $booking['custom_name'] ?: $booking['display_name'];
        $hotel_name = $booking['hotel_name'] ?: 'L.P.S.T Hotel';
        
        $message = generate_checkout_sms(
            $booking['client_name'],
            $resource_name,
            $hotel_name
        );
        
        return send_sms(
            $booking['client_mobile'],
            $message,
            'CHECKOUT',
            $booking_id,
            $booking['admin_id'],
            $pdo
        );
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send cancellation SMS
 */
function send_cancellation_sms($booking_id, $pdo) {
    try {
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, s.setting_value as hotel_name
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            LEFT JOIN settings s ON s.setting_key = 'hotel_name'
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception('Booking not found');
        }
        
        $resource_name = $booking['custom_name'] ?: $booking['display_name'];
        $hotel_name = $booking['hotel_name'] ?: 'L.P.S.T Hotel';
        
        $message = generate_cancellation_sms(
            $booking['client_name'],
            $resource_name,
            $hotel_name
        );
        
        return send_sms(
            $booking['client_mobile'],
            $message,
            'CANCELLATION',
            $booking_id,
            $booking['admin_id'],
            $pdo
        );
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send advance booking confirmation SMS
 */
function send_advance_booking_sms($booking_id, $pdo) {
    try {
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, r.display_name, r.custom_name, s.setting_value as hotel_name
            FROM bookings b 
            JOIN resources r ON b.resource_id = r.id 
            LEFT JOIN settings s ON s.setting_key = 'hotel_name'
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception('Booking not found');
        }
        
        $resource_name = $booking['custom_name'] ?: $booking['display_name'];
        $hotel_name = $booking['hotel_name'] ?: 'L.P.S.T Hotel';
        $advance_date = date('d-M-Y', strtotime($booking['advance_date']));
        
        $message = generate_advance_booking_sms(
            $booking['client_name'],
            $resource_name,
            $advance_date,
            $hotel_name
        );
        
        return send_sms(
            $booking['client_mobile'],
            $message,
            'ADVANCE',
            $booking_id,
            $booking['admin_id'],
            $pdo
        );
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Test SMS configuration
 */
function test_sms_configuration($test_mobile, $pdo, $admin_id) {
    $test_message = "Test SMS from L.P.S.T Bookings System. Configuration is working correctly!";
    
    return send_sms(
        $test_mobile,
        $test_message,
        'BOOKING',
        0, // No booking ID for test
        $admin_id,
        $pdo
    );
}
?>