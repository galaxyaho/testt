<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

$database = new Database();
$pdo = $database->getConnection();

// Handle export requests
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    $email = $_GET['email'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    $adminId = $_GET['admin_id'] ?? '';
    
    // Build query
    $whereConditions = [];
    $params = [];
    
    if (!empty($startDate)) {
        $whereConditions[] = "DATE(b.created_at) >= ?";
        $params[] = $startDate;
    }
    
    if (!empty($endDate)) {
        $whereConditions[] = "DATE(b.created_at) <= ?";
        $params[] = $endDate;
    }
    
    if (!empty($adminId)) {
        $whereConditions[] = "b.admin_id = ?";
        $params[] = $adminId;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $stmt = $pdo->prepare("
        SELECT b.*, 
               COALESCE(r.custom_name, r.display_name) as resource_name,
               r.display_name as original_name,
               r.custom_name,
               r.type, 
               u.username as admin_name
        FROM bookings b 
        JOIN resources r ON b.resource_id = r.id 
        JOIN users u ON b.admin_id = u.id 
        $whereClause
        ORDER BY b.created_at DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
    
    if ($format === 'csv' && empty($email)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="lpst_bookings_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'ID', 'Resource', 'Type', 'Client Name', 'Mobile', 'Aadhar', 'License', 
            'Receipt No', 'Payment Mode', 'Check-in', 'Check-out', 'Status', 
            'Paid', 'Amount', 'Admin', 'Created', 'Booking Type', 'Advance Date'
        ]);
        
        foreach ($bookings as $booking) {
            fputcsv($output, [
                $booking['id'],
                $booking['resource_name'],
                $booking['type'],
                $booking['client_name'],
                $booking['client_mobile'],
                $booking['client_aadhar'] ?: '',
                $booking['client_license'] ?: '',
                $booking['receipt_number'] ?: '',
                $booking['payment_mode'] ?: 'OFFLINE',
                $booking['check_in'],
                $booking['check_out'],
                $booking['status'],
                $booking['is_paid'] ? 'Yes' : 'No',
                $booking['total_amount'],
                $booking['admin_name'],
                $booking['created_at'],
                $booking['booking_type'],
                $booking['advance_date'] ?: ''
            ]);
        }
        
        fclose($output);
        exit;
    } elseif ($format === 'email' && !empty($email)) {
        // Send export via email
        require_once '../includes/email_functions.php';
        
        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'admin_id' => $adminId
        ];
        
        $result = send_export_email($email, $bookings, $filters, $pdo, $_SESSION['user_id']);
        
        if ($result['success']) {
            redirect_with_message('reports.php', 'Export sent to email successfully!', 'success');
        } else {
            redirect_with_message('reports.php', 'Failed to send email: ' . $result['message'], 'error');
        }
    }
}

// Get filter data
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$adminId = $_GET['admin_id'] ?? '';

// Get all admins for filter
$stmt = $pdo->prepare("SELECT id, username FROM users WHERE role = 'ADMIN' ORDER BY username");
$stmt->execute();
$admins = $stmt->fetchAll();

// Build filtered query
$whereConditions = [];
$params = [];

if (!empty($startDate)) {
    $whereConditions[] = "DATE(b.created_at) >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $whereConditions[] = "DATE(b.created_at) <= ?";
    $params[] = $endDate;
}

if (!empty($adminId)) {
    $whereConditions[] = "b.admin_id = ?";
    $params[] = $adminId;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get bookings
$stmt = $pdo->prepare("
    SELECT b.*, 
           COALESCE(r.custom_name, r.display_name) as resource_name,
           r.display_name as original_name,
           r.custom_name,
           r.type, 
           u.username as admin_name
    FROM bookings b 
    JOIN resources r ON b.resource_id = r.id 
    JOIN users u ON b.admin_id = u.id 
    $whereClause
    ORDER BY b.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get summary stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_bookings,
        SUM(CASE WHEN is_paid = 1 THEN total_amount ELSE 0 END) as total_paid,
        SUM(CASE WHEN is_paid = 0 THEN 1 ELSE 0 END) as unpaid_count,
        COUNT(CASE WHEN status = 'BOOKED' THEN 1 END) as active_bookings,
        COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as pending_bookings
    FROM bookings b 
    $whereClause
");
$stmt->execute($params);
$stats = $stmt->fetch();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="index.php" class="nav-button">← Dashboard</a>
            <a href="admins.php" class="nav-button">Manage Admins</a>
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

        <h2>Reports & Analytics</h2>
        
        <!-- Filters -->
        <div class="form-container">
            <h3>Filters</h3>
            <form method="GET">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="form-group">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" 
                               value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" 
                               value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_id" class="form-label">Admin</label>
                        <select id="admin_id" name="admin_id" class="form-control">
                            <option value="">All Admins</option>
                            <?php foreach ($admins as $admin): ?>
                                <option value="<?= $admin['id'] ?>" 
                                        <?= $adminId == $admin['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($admin['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" 
                       class="btn btn-success">Export CSV</a>
                    <button type="button" onclick="showEmailExport()" class="btn btn-warning">📧 Email Export</button>
                </div>
            </form>
        </div>
        
        <!-- Summary Stats -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>Total Bookings</h3>
                <div class="dashboard-value"><?= $stats['total_bookings'] ?></div>
            </div>
            
            <div class="dashboard-card">
                <h3>Total Revenue</h3>
                <div class="dashboard-value"><?= format_currency($stats['total_paid'] ?: 0) ?></div>
            </div>
            
            <div class="dashboard-card">
                <h3>Unpaid Bookings</h3>
                <div class="dashboard-value" style="color: var(--danger-color);"><?= $stats['unpaid_count'] ?></div>
            </div>
            
            <div class="dashboard-card">
                <h3>Active Bookings</h3>
                <div class="dashboard-value"><?= $stats['active_bookings'] ?></div>
            </div>
            
            <div class="dashboard-card">
                <h3>Pending Actions</h3>
                <div class="dashboard-value" style="color: var(--warning-color);"><?= $stats['pending_bookings'] ?></div>
            </div>
        </div>
        
        <!-- Bookings Table -->
        <div class="form-container">
            <h3>Recent Bookings (Last 100)</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">ID</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Resource</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Mobile</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Client</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Check-in</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Status</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Paid</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Admin</th>
                            <th style="padding: 0.5rem; text-align: left; border-bottom: 1px solid var(--border-color);">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <?= $booking['id'] ?>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($booking['resource_name']) ?>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($booking['client_mobile']) ?>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($booking['client_name']) ?>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <?= date('M j, g:i A', strtotime($booking['check_in'])) ?>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <span class="status-badge status-<?= strtolower($booking['status']) ?>">
                                        <?= $booking['status'] ?>
                                    </span>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="color: <?= $booking['is_paid'] ? 'var(--success-color)' : 'var(--danger-color)' ?>">
                                        <?= $booking['is_paid'] ? 'PAID' : 'UNPAID' ?>
                                    </span>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($booking['admin_name']) ?>
                                </td>
                                <td style="padding: 0.5rem; border-bottom: 1px solid var(--border-color);">
                                    <?= date('M j, g:i A', strtotime($booking['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Email Export Modal -->
    <div id="emailExportModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Email Export</h3>
                <button type="button" class="close-modal" onclick="closeEmailModal()">&times;</button>
            </div>
            <form method="GET">
                <input type="hidden" name="export" value="email">
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                <input type="hidden" name="admin_id" value="<?= htmlspecialchars($adminId) ?>">
                
                <div class="form-group">
                    <label for="email" class="form-label">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" required
                           placeholder="Enter email address to send export">
                </div>
                
                <div style="background: rgba(37, 99, 235, 0.1); padding: 1rem; border-radius: 8px; margin: 1rem 0;">
                    <h4 style="color: var(--primary-color); margin-bottom: 0.5rem;">Export Details:</h4>
                    <ul style="color: var(--dark-color); margin: 0;">
                        <li>Date Range: <?= $startDate ?: 'All' ?> to <?= $endDate ?: 'All' ?></li>
                        <li>Admin Filter: <?= $adminId ? 'Specific Admin' : 'All Admins' ?></li>
                        <li>Total Records: <?= $stats['total_bookings'] ?></li>
                        <li>Format: CSV Attachment</li>
                    </ul>
                </div>
                
                <button type="submit" class="btn btn-primary">Send Export Email</button>
            </form>
        </div>
    </div>
    
    <script>
        function showEmailExport() {
            document.getElementById('emailExportModal').style.display = 'flex';
        }
        
        function closeEmailModal() {
            document.getElementById('emailExportModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('emailExportModal');
            if (e.target === modal) {
                closeEmailModal();
            }
        });
    </script>
</body>
</html>