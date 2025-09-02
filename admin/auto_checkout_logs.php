<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('ADMIN');

$database = new Database();
$pdo = $database->getConnection();

// Get checkout logs with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Filter options
$dateFilter = $_GET['date'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$whereConditions = [];
$params = [];

if ($dateFilter) {
    $whereConditions[] = "DATE(acl.created_at) = ?";
    $params[] = $dateFilter;
}

if ($statusFilter) {
    $whereConditions[] = "acl.status = ?";
    $params[] = $statusFilter;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$stmt = $pdo->prepare("
    SELECT acl.*, r.display_name, r.custom_name, r.type, p.amount
    FROM auto_checkout_logs acl
    LEFT JOIN resources r ON acl.resource_id = r.id
    LEFT JOIN payments p ON acl.booking_id = p.booking_id AND p.payment_method = 'AUTO_CHECKOUT'
    $whereClause
    ORDER BY acl.created_at DESC
    LIMIT ? OFFSET ?
");

$params[] = $limit;
$params[] = $offset;
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get total count for pagination
$countParams = array_slice($params, 0, -2); // Remove limit and offset
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM auto_checkout_logs acl
    LEFT JOIN resources r ON acl.resource_id = r.id
    $whereClause
");
$countStmt->execute($countParams);
$totalLogs = $countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $limit);

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto Checkout Logs - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="../grid.php" class="nav-button">← Back to Grid</a>
            <a href="auto_checkout_settings.php" class="nav-button">Settings</a>
        </div>
        <a href="/" class="nav-brand">L.P.S.T Bookings</a>
        <div class="nav-links">
            <span style="margin-right: 1rem;">Auto Checkout Logs</span>
            <a href="../logout.php" class="nav-button danger">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($flash): ?>
            <div class="flash-message flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <h2>🕙 Auto Checkout Logs</h2>
        
        <!-- Filters -->
        <div class="form-container">
            <h3>Filters</h3>
            <form method="GET">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label for="date" class="form-label">Date</label>
                        <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($dateFilter) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="auto_checkout_logs.php" class="btn btn-outline">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <!-- Logs Table -->
        <div class="form-container">
            <h3>Checkout History (<?= $totalLogs ?> total records)</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Date & Time</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Guest Name</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Status</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Amount</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" style="padding: 2rem; text-align: center; color: var(--dark-color);">
                                    <div style="text-align: center;">
                                        <div style="font-size: 3rem; margin-bottom: 1rem;">🕙</div>
                                        <h4>No auto checkout logs found</h4>
                                        <p>Auto checkout logs will appear here when the system runs</p>
                                        <a href="auto_checkout_settings.php" class="btn btn-primary">Configure Auto Checkout</a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <strong><?= htmlspecialchars($log['custom_name'] ?: $log['display_name'] ?: $log['resource_name']) ?></strong>
                                        <?php if ($log['type']): ?>
                                            <br><small style="color: var(--dark-color);"><?= ucfirst($log['type']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?= htmlspecialchars($log['guest_name']) ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <span style="color: <?= $log['status'] === 'success' ? 'var(--success-color)' : 'var(--danger-color)' ?>; font-weight: 600;">
                                            <?= $log['status'] === 'success' ? '✅ SUCCESS' : '❌ FAILED' ?>
                                        </span>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <?php if ($log['amount']): ?>
                                            <strong style="color: var(--success-color);"><?= format_currency($log['amount']) ?></strong>
                                        <?php else: ?>
                                            <span style="color: var(--dark-color);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                        <small><?= htmlspecialchars($log['notes']) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div style="text-align: center; margin-top: 2rem;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&date=<?= $dateFilter ?>&status=<?= $statusFilter ?>" 
                           class="btn btn-outline">← Previous</a>
                    <?php endif; ?>
                    
                    <span style="margin: 0 1rem;">Page <?= $page ?> of <?= $totalPages ?></span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&date=<?= $dateFilter ?>&status=<?= $statusFilter ?>" 
                           class="btn btn-outline">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>