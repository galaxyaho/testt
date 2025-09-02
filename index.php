<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

$database = new Database();
$pdo = $database->getConnection();

// Auto-update pending bookings
update_pending_bookings($pdo);

if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Redirect based on role
if ($_SESSION['role'] === 'OWNER') {
    header('Location: owner/index.php');
    exit;
} else {
    header('Location: grid.php');
    exit;
}
?>