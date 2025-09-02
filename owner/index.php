<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

$database = new Database();
$pdo = $database->getConnection();

// Get dashboard statistics
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('-7 days'));
$monthStart = date('Y-m-d', strtotime('-30 days'));

// Today's stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as bookings_count,
        SUM(CASE WHEN is_paid = 1 THEN total_amount ELSE 0 END) as paid_amount,
        SUM(CASE WHEN is_paid = 0 THEN 1 ELSE 0 END) as unpaid_count
    FROM bookings 
    WHERE DATE(created_at) = ?
");
$stmt->execute([$today]);
$todayStats = $stmt->fetch();

// Yesterday's stats
$stmt->execute([$yesterday]);
$yesterdayStats = $stmt->fetch();

// Weekly stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as bookings_count,
        SUM(CASE WHEN is_paid = 1 THEN total_amount ELSE 0 END) as paid_amount,
        SUM(CASE WHEN is_paid = 0 THEN 1 ELSE 0 END) as unpaid_count
    FROM bookings 
    WHERE DATE(created_at) >= ?
");
$stmt->execute([$weekStart]);
$weeklyStats = $stmt->fetch();

// Monthly stats
$stmt->execute([$monthStart]);
$monthlyStats = $stmt->fetch();

// Active bookings
$stmt = $pdo->prepare("
    SELECT COUNT(*) as active_count 
    FROM bookings 
    WHERE status IN ('BOOKED', 'PENDING')
");
$stmt->execute();
$activeCount = $stmt->fetchColumn();

// Pending actions
$stmt = $pdo->prepare("
    SELECT COUNT(*) as pending_count 
    FROM bookings 
    WHERE status = 'PENDING'
");
$stmt->execute();
$pendingCount = $stmt->fetchColumn();

// Recent admin activities
$stmt = $pdo->prepare("
    SELECT b.*, r.display_name, r.custom_name, u.username 
    FROM bookings b 
    JOIN resources r ON b.resource_id = r.id 
    JOIN users u ON b.admin_id = u.id 
    ORDER BY b.created_at DESC 
    LIMIT 10
");
$stmt->execute();
$recentActivities = $stmt->fetchAll();

// Get recent payments
$stmt = $pdo->prepare("
    SELECT p.*, r.display_name, r.custom_name, u.username as admin_name
    FROM payments p 
    JOIN resources r ON p.resource_id = r.id 
    JOIN users u ON p.admin_id = u.id 
    WHERE p.payment_status = 'COMPLETED'
    ORDER BY p.created_at DESC 
    LIMIT 10
");
$stmt->execute();
$recentPayments = $stmt->fetchAll();

// Get recent cancellations
$stmt = $pdo->prepare("
    SELECT bc.*, r.display_name, r.custom_name, u.username as admin_name
    FROM booking_cancellations bc
    JOIN resources r ON bc.resource_id = r.id 
    JOIN users u ON bc.cancelled_by = u.id 
    ORDER BY bc.cancelled_at DESC 
    LIMIT 10
");
$stmt->execute();
$recentCancellations = $stmt->fetchAll();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="resources.php" class="nav-button">Resources</a>
            <a href="admins.php" class="nav-button">Manage Admins</a>
            <a href="reports.php" class="nav-button">Reports</a>
            <a href="settings.php" class="nav-button">Settings</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Owner Panel</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <h2>Owner Dashboard</h2>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>Today</h3>
                <div class="dashboard-value"><?= $todayStats['bookings_count'] ?></div>
                <p>Bookings | <?= format_currency($todayStats['paid_amount'] ?: 0) ?> Paid</p>
                <?php if ($todayStats['unpaid_count'] > 0): ?>
                    <p style="color: var(--danger-color);"><?= $todayStats['unpaid_count'] ?> Unpaid</p>
                <?php endif; ?>
            </div>
            
            <div class="dashboard-card">
                <h3>Yesterday</h3>
                <div class="dashboard-value"><?= $yesterdayStats['bookings_count'] ?></div>
                <p>Bookings | <?= format_currency($yesterdayStats['paid_amount'] ?: 0) ?> Paid</p>
            </div>
            
            <div class="dashboard-card">
                <h3>This Week</h3>
                <div class="dashboard-value"><?= $weeklyStats['bookings_count'] ?></div>
                <p>Bookings | <?= format_currency($weeklyStats['paid_amount'] ?: 0) ?> Paid</p>
            </div>
            
            <div class="dashboard-card">
                <h3>This Month</h3>
                <div class="dashboard-value"><?= $monthlyStats['bookings_count'] ?></div>
                <p>Bookings | <?= format_currency($monthlyStats['paid_amount'] ?: 0) ?> Paid</p>
            </div>
            
            <div class="dashboard-card">
                <h3>Active Stays</h3>
                <div class="dashboard-value"><?= $activeCount ?></div>
                <p>Currently occupied rooms/halls</p>
            </div>
            
            <div class="dashboard-card">
                <h3>Pending Actions</h3>
                <div class="dashboard-value" style="color: var(--danger-color);"><?= $pendingCount ?></div>
                <p>Require immediate attention</p>
            </div>
        </div>
        
        <div class="form-container">
            <h3>Recent Admin Activities</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Time</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Admin</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Client</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivities as $activity): ?>
                            <tr>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= date('M j, g:i A', strtotime($activity['created_at'])) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($activity['username']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($activity['display_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($activity['client_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span class="status-badge status-<?= strtolower($activity['status']) ?>">
                                        <?= $activity['status'] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Payments -->
        <div class="form-container">
            <h3>Recent Payments</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Time</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Admin</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Amount</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Method</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPayments as $payment): ?>
                            <tr>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= date('M j, g:i A', strtotime($payment['created_at'])) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($payment['admin_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($payment['custom_name'] ?: $payment['display_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <strong style="color: var(--success-color);"><?= format_currency($payment['amount']) ?></strong>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="background: <?= $payment['payment_method'] === 'MANUAL' ? 'var(--warning-color)' : 'var(--primary-color)' ?>; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                        <?= $payment['payment_method'] ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="color: var(--success-color); font-weight: 600;">
                                        ✅ <?= $payment['payment_status'] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Cancellations -->
        <div class="form-container">
            <h3>Recent Booking Cancellations</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Time</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Admin</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Client</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Reason</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentCancellations as $cancellation): ?>
                            <tr>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= date('M j, g:i A', strtotime($cancellation['cancelled_at'])) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="color: var(--danger-color); font-weight: 600;">
                                        <?= htmlspecialchars($cancellation['admin_name']) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <strong><?= htmlspecialchars($cancellation['custom_name'] ?: $cancellation['display_name']) ?></strong>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($cancellation['original_client_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="background: var(--danger-color); color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                        CANCELLED
                                    </span>
                                    <?php if ($cancellation['original_advance_date']): ?>
                                        <br><small>Advance: <?= date('M j, Y', strtotime($cancellation['original_advance_date'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?php if ($cancellation['duration_at_cancellation'] > 0): ?>
                                        <?php 
                                        $hours = floor($cancellation['duration_at_cancellation'] / 60);
                                        $minutes = $cancellation['duration_at_cancellation'] % 60;
                                        echo "{$hours}h {$minutes}m";
                                        ?>
                                    <?php else: ?>
                                        <span style="color: var(--warning-color);">Advanced</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>