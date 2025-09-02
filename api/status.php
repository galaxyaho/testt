<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$database = new Database();
$pdo = $database->getConnection();

// Update pending bookings
update_pending_bookings($pdo);

// Get all resources with their current status
$stmt = $pdo->prepare("
    SELECT r.id, r.display_name, r.type, r.identifier,
           b.status, b.client_name, b.check_in, b.is_paid,
           CASE WHEN b.id IS NOT NULL THEN 1 ELSE 0 END as is_occupied
    FROM resources r
    LEFT JOIN bookings b ON r.id = b.resource_id 
        AND b.status IN ('BOOKED', 'PENDING', 'ADVANCED_BOOKED')
        AND (b.booking_type = 'regular' OR (b.booking_type = 'advanced' AND b.advance_date = CURDATE()))
    WHERE r.is_active = 1
    ORDER BY r.type, CAST(r.identifier AS UNSIGNED), r.identifier
");
$stmt->execute();
$resources = $stmt->fetchAll();

$resourceData = [];
foreach ($resources as $resource) {
    $resourceData[$resource['id']] = [
        'id' => $resource['id'],
        'display_name' => $resource['display_name'],
        'status' => $resource['status'] ?: 'VACANT',
        'is_occupied' => (bool)$resource['is_occupied'],
        'client_name' => $resource['client_name'],
        'check_in' => $resource['check_in'],
        'is_paid' => (bool)$resource['is_paid']
    ];
}

echo json_encode([
    'success' => true,
    'resources' => $resourceData,
    'timestamp' => time()
]);
?>