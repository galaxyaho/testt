<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filter by activity type
$activity_filter = isset($_GET['activity_type']) ? $_GET['activity_type'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';

// Build query
$where_conditions = [];
$params = [];

if ($activity_filter) {
    $where_conditions[] = "aa.activity_type = ?";
    $params[] = $activity_filter;
}

if ($date_filter) {
    $where_conditions[] = "DATE(aa.created_at) = ?";
    $params[] = $date_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM admin_activities aa 
    JOIN users u ON aa.admin_id = u.id 
    $where_clause
");
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get activities with pagination
$stmt = $pdo->prepare("
    SELECT 
        aa.*,
        u.username as admin_name,
        r.room_number,
        r.room_name,
        b.guest_name
    FROM admin_activities aa
    JOIN users u ON aa.admin_id = u.id
    LEFT JOIN rooms r ON aa.room_id = r.id
    LEFT JOIN bookings b ON aa.booking_id = b.id
    $where_clause
    ORDER BY aa.created_at DESC
    LIMIT ? OFFSET ?
");

$params[] = $per_page;
$params[] = $offset;
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get activity types for filter
$types_stmt = $pdo->query("SELECT DISTINCT activity_type FROM admin_activities ORDER BY activity_type");
$activity_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Activity Log - Hotel Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .activity-icon {
            width: 30px;
            text-align: center;
        }
        .auto-activity {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        .manual-activity {
            background-color: #f3e5f5;
            border-left: 4px solid #9c27b0;
        }
        .activity-time {
            font-size: 0.85rem;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="booking_manage.php">
                                <i class="fas fa-calendar-check"></i> Manage Bookings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="admin_activity.php">
                                <i class="fas fa-history"></i> Admin Activity
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="rooms.php">
                                <i class="fas fa-bed"></i> Manage Rooms
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-10 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Admin Activity Log</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="activity_type" class="form-label">Activity Type</label>
                                <select class="form-select" id="activity_type" name="activity_type">
                                    <option value="">All Activities</option>
                                    <?php foreach ($activity_types as $type): ?>
                                        <option value="<?php echo $type; ?>" 
                                                <?php echo $activity_filter === $type ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="date" class="form-label">Date</label>
                                <input type="date" class="form-control" id="date" name="date" 
                                       value="<?php echo $date_filter; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <a href="admin_activity.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Clear
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Activity Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-primary">
                                    <?php
                                    $auto_count_stmt = $pdo->query("SELECT COUNT(*) FROM admin_activities WHERE activity_type = 'auto_checkout' AND DATE(created_at) = CURDATE()");
                                    echo $auto_count_stmt->fetchColumn();
                                    ?>
                                </h5>
                                <p class="card-text">Auto Checkouts Today</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-success">
                                    <?php
                                    $manual_count_stmt = $pdo->query("SELECT COUNT(*) FROM admin_activities WHERE activity_type IN ('check_in', 'check_out') AND DATE(created_at) = CURDATE()");
                                    echo $manual_count_stmt->fetchColumn();
                                    ?>
                                </h5>
                                <p class="card-text">Manual Actions Today</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-warning">
                                    <?php
                                    $total_today_stmt = $pdo->query("SELECT COUNT(*) FROM admin_activities WHERE DATE(created_at) = CURDATE()");
                                    echo $total_today_stmt->fetchColumn();
                                    ?>
                                </h5>
                                <p class="card-text">Total Activities Today</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title text-info"><?php echo $total_records; ?></h5>
                                <p class="card-text">Total Records</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activities List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Activity Timeline</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($activities)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($activities as $activity): ?>
                                    <div class="list-group-item <?php echo $activity['activity_type'] === 'auto_checkout' ? 'auto-activity' : 'manual-activity'; ?>">
                                        <div class="d-flex w-100 justify-content-between align-items-start">
                                            <div class="d-flex">
                                                <div class="activity-icon me-3">
                                                    <?php
                                                    $icon = '';
                                                    switch ($activity['activity_type']) {
                                                        case 'auto_checkout':
                                                            $icon = 'fas fa-clock text-primary';
                                                            break;
                                                        case 'check_in':
                                                            $icon = 'fas fa-sign-in-alt text-success';
                                                            break;
                                                        case 'check_out':
                                                            $icon = 'fas fa-sign-out-alt text-warning';
                                                            break;
                                                        case 'cancel':
                                                            $icon = 'fas fa-times text-danger';
                                                            break;
                                                        default:
                                                            $icon = 'fas fa-info-circle text-info';
                                                    }
                                                    ?>
                                                    <i class="<?php echo $icon; ?>"></i>
                                                </div>
                                                <div>
                                                    <h6 class="mb-1">
                                                        <?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?>
                                                        <?php if ($activity['room_number']): ?>
                                                            - Room <?php echo $activity['room_number']; ?>
                                                            <?php if ($activity['room_name']): ?>
                                                                (<?php echo htmlspecialchars($activity['room_name']); ?>)
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <p class="mb-1"><?php echo htmlspecialchars($activity['description']); ?></p>
                                                    <small class="text-muted">
                                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($activity['admin_name']); ?>
                                                        <?php if ($activity['guest_name']): ?>
                                                            | <i class="fas fa-user-friends"></i> <?php echo htmlspecialchars($activity['guest_name']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="activity-time">
                                                <?php echo date('M d, Y H:i:s', strtotime($activity['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No activities found</h5>
                                <p class="text-muted">Admin activities will appear here as they occur.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Activity pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&activity_type=<?php echo $activity_filter; ?>&date=<?php echo $date_filter; ?>">
                                        Previous
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&activity_type=<?php echo $activity_filter; ?>&date=<?php echo $date_filter; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&activity_type=<?php echo $activity_filter; ?>&date=<?php echo $date_filter; ?>">
                                        Next
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>