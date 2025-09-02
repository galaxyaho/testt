<?php
session_start();

// Security and utility functions
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function require_role($required_role) {
    require_login();
    if ($_SESSION['role'] !== $required_role) {
        header('Location: /index.php');
        exit;
    }
}

function redirect_with_message($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = ['message' => $message, 'type' => $type];
    header("Location: $url");
    exit;
}

function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

function format_currency($amount) {
    return '₹' . number_format($amount, 2);
}

function get_resource_status($resource_id, $pdo) {
    $stmt = $pdo->prepare("
        SELECT b.*, r.display_name, r.custom_name, r.type, r.identifier
        FROM bookings b 
        JOIN resources r ON b.resource_id = r.id 
        WHERE b.resource_id = ? 
        AND b.status IN ('BOOKED', 'PENDING', 'ADVANCED_BOOKED')
        AND (
            b.booking_type = 'regular' 
            OR (b.booking_type = 'advanced' AND b.advance_date = CURDATE())
        )
        ORDER BY b.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$resource_id]);
    return $stmt->fetch();
}

function update_pending_bookings($pdo) {
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'PENDING' 
        WHERE status = 'BOOKED' 
        AND NOW() >= DATE_ADD(check_in, INTERVAL 24 HOUR)
    ");
    $stmt->execute();
}

function calculate_duration($check_in, $check_out = null) {
    $start = new DateTime($check_in);
    $end = $check_out ? new DateTime($check_out) : new DateTime();
    $diff = $start->diff($end);
    
    $hours = $diff->h + ($diff->days * 24);
    $minutes = $diff->i;
    
    return [
        'hours' => $hours,
        'minutes' => $minutes,
        'total_minutes' => ($hours * 60) + $minutes,
        'formatted' => sprintf('%dh %dm', $hours, $minutes)
    ];
}

function get_all_advanced_bookings($pdo) {
    $stmt = $pdo->prepare("
        SELECT b.*, r.display_name, r.custom_name, r.identifier, r.type
        FROM bookings b 
        JOIN resources r ON b.resource_id = r.id 
        WHERE b.booking_type = 'advanced' 
        AND b.status = 'ADVANCED_BOOKED'
        AND b.advance_date >= CURDATE()
        ORDER BY b.advance_date ASC, r.type, CAST(r.identifier AS UNSIGNED), r.identifier
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function mark_booking_paid($booking_id, $pdo) {
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'PAID', is_paid = 1, actual_check_out = NOW()
        WHERE id = ?
    ");
    return $stmt->execute([$booking_id]);
}

function complete_checkout($booking_id, $pdo) {
    // Calculate duration
    $stmt = $pdo->prepare("SELECT check_in, actual_check_in FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        $start_time = $booking['actual_check_in'] ?: $booking['check_in'];
        $duration = calculate_duration($start_time);
        
        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET status = 'COMPLETED', 
                actual_check_out = NOW(),
                duration_minutes = ?
            WHERE id = ?
        ");
        return $stmt->execute([$duration['total_minutes'], $booking_id]);
    }
    return false;
}

function get_today_advanced_bookings($pdo) {
    $stmt = $pdo->prepare("
        SELECT b.*, r.display_name, r.identifier, r.type
        FROM bookings b 
        JOIN resources r ON b.resource_id = r.id 
        WHERE b.booking_type = 'advanced' 
        AND b.advance_date = CURDATE()
        AND b.status = 'ADVANCED_BOOKED'
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function has_advanced_booking($resource_id, $pdo) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings 
        WHERE resource_id = ? 
        AND booking_type = 'advanced' 
        AND status = 'ADVANCED_BOOKED'
        AND advance_date > CURDATE()
    ");
    $stmt->execute([$resource_id]);
    return $stmt->fetchColumn() > 0;
}

function get_advanced_booking_details($resource_id, $pdo) {
    $stmt = $pdo->prepare("
        SELECT b.*, r.display_name, r.custom_name 
        FROM bookings b 
        JOIN resources r ON b.resource_id = r.id 
        WHERE b.resource_id = ? 
        AND b.booking_type = 'advanced' 
        AND b.status = 'ADVANCED_BOOKED'
        AND b.advance_date > CURDATE()
        ORDER BY b.advance_date ASC 
        LIMIT 1
    ");
    $stmt->execute([$resource_id]);
    return $stmt->fetch();
}

function generate_qr_code($text) {
    // Simple QR code generation using Google Charts API
    $size = '200x200';
    $encoding = 'UTF-8';
    return "https://chart.googleapis.com/chart?chs={$size}&cht=qr&chl=" . urlencode($text) . "&choe={$encoding}";
}
?>