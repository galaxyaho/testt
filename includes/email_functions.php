<?php
/**
 * Email Functions for L.P.S.T Bookings System
 * This file uses a manual installation of PHPMailer.
 */

// Load PHPMailer classes from our manual upload location
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// --- SOLUTION FOR MANUAL INSTALL ---
// We now include the 3 files we uploaded directly.
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
// --- END SOLUTION ---


/**
 * Sends an email using the SMTP settings from the database.
 */
function send_email($to_email, $subject, $body, $pdo, $admin_id) {
    // This function remains the same as before, it will now work correctly.
    $email_log_id = null;

    try {
        $stmt = $pdo->prepare("
            SELECT setting_key, setting_value FROM settings 
            WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'hotel_name')
        ");
        $stmt->execute();
        $settings = array_column($stmt->fetchAll(), 'setting_value', 'setting_key');
        
        if (empty($settings['smtp_host']) || empty($settings['smtp_username']) || empty($settings['smtp_password'])) {
            throw new Exception('SMTP configuration is incomplete. Please save all required SMTP settings first.');
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO email_logs (recipient_email, subject, status, admin_id) VALUES (?, ?, 'PENDING', ?)");
            $stmt->execute([$to_email, $subject, $admin_id]);
            $email_log_id = $pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("Failed to log email to database: " . $e->getMessage());
        }

        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = $settings['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtp_username'];
        $mail->Password   = $settings['smtp_password'];
        $mail->SMTPSecure = $settings['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = intval($settings['smtp_port']);

        $hotel_name = htmlspecialchars($settings['hotel_name'] ?? 'L.P.S.T Hotel');
        $mail->setFrom($settings['smtp_username'], $hotel_name);
        $mail->addAddress($to_email);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();

        if ($email_log_id) {
            $stmt = $pdo->prepare("UPDATE email_logs SET status = 'SENT', response_data = 'OK' WHERE id = ?");
            $stmt->execute([$email_log_id]);
        }
        
        return ['success' => true, 'message' => 'Email sent successfully.'];

    } catch (Exception $e) {
        $error_message = ($e instanceof PHPMailerException) ? $e->errorMessage() : $e->getMessage();
        if ($email_log_id) {
            $stmt = $pdo->prepare("UPDATE email_logs SET status = 'FAILED', response_data = ? WHERE id = ?");
            $stmt->execute([$error_message, $email_log_id]);
        }
        return ['success' => false, 'message' => $error_message];
    }
}

/**
 * Test the email configuration.
 */
function test_email_configuration($test_email, $pdo, $admin_id) {
    $subject = 'L.P.S.T Bookings - Email Configuration Test';
    $body = "<html><body><h2>✅ Test Successful!</h2><p>Your SMTP email settings are working correctly.</p></body></html>";
    return send_email($test_email, $subject, $body, $pdo, $admin_id);
}
?>