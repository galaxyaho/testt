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

// Get dashboard statistics
$stats = [];

// Total rooms
$stmt = $pdo->query("SELECT COUNT(*) FROM rooms");
$stats['total_rooms'] = $stmt->fetchColumn();

// Available rooms
$stmt = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'available'");
$stats['available_rooms'] = $stmt->fetchColumn();

// Occupied rooms
$stmt = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'occupied'");
$stats['occupied_rooms'] = $stmt->fetchColumn();

// Today's bookings
$stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = CURDATE()");
$stats['todays_bookings'] = $stmt->fetchColumn();

// Checked in today
$stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(check_in_date) = CURDATE() AND status = 'checked_in'");
$stats['checked_in_today'] = $stmt->fetchColumn();

// Auto checkouts today
$stmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE(checkout_date) = CURDATE() AND auto_checkout = 1");
$stats['auto_checkouts_today'] = $stmt->fetchColumn();

// Recent activities
$stmt = $pdo->prepare("
    SELECT 
        aa.*,
        u.username as admin_name,
        r.room_number,
        r.room_name
    FROM admin_activities aa
    JOIN users u ON aa.admin_id = u.id
    LEFT JOIN rooms r ON aa.room_id = r.id
    ORDER BY aa.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Upcoming checkouts (rooms that will be auto-checked out tomorrow)
$stmt = $pdo->prepare("
    SELECT 
        b.*,
        r.room_number,
        r.room_name,
        u.username as admin_name
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    LEFT JOIN users u ON b.booked_by_admin = u.id
    WHERE b.status = 'checked_in'
    AND DATE(b.check_in_date) <= CURDATE()
    ORDER BY b.check_in_date ASC
");
$stmt->execute();
$upcoming_checkouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Hotel Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .activity-timeline {
            max-height: 400px;
            overflow-y: auto;
        }
        .auto-checkout-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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
                            <a class="nav-link active" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="booking_manage.php">
                                <i class="fas fa-calendar-check"></i> Manage Bookings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin_activity.php">
                                <i class="fas fa-history"></i> Admin Activity
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="rooms.php">
                                <i class="fas fa-bed"></i> Manage Rooms
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../logout.php">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-10 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Dashboard</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <span class="badge bg-primary">
                                <i class="fas fa-clock"></i> <?php echo date('M d, Y H:i'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Auto Checkout Info -->
                <div class="auto-checkout-info">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4><i class="fas fa-robot"></i> Auto Checkout System</h4>
                            <p class="mb-0">
                                All checked-in rooms are automatically checked out daily at 10:00 AM. 
                                Today's auto checkouts: <strong><?php echo $stats['auto_checkouts_today']; ?></strong>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="h2 mb-0"><?php echo count($upcoming_checkouts); ?></div>
                            <small>Rooms to be auto-checked out</small>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card text-center border-primary">
                            <div class="card-body">
                                <i class="fas fa-bed fa-2x text-primary mb-2"></i>
                                <h3 class="text-primary"><?php echo $stats['total_rooms']; ?></h3>
                                <p class="card-text">Total Rooms</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center border-success">
                            <div class="card-body">
                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <h3 class="text-success"><?php echo $stats['available_rooms']; ?></h3>
                                <p class="card-text">Available Rooms</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center border-warning">
                            <div class="card-body">
                                <i class="fas fa-user-friends fa-2x text-warning mb-2"></i>
                                <h3 class="text-warning"><?php echo $stats['occupied_rooms']; ?></h3>
                                <p class="card-text">Occupied Rooms</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card text-center border-info">
                            <div class="card-body">
                                <i class="fas fa-calendar-plus fa-2x text-info mb-2"></i>
                                <h3 class="text-info"><?php echo $stats['todays_bookings']; ?></h3>
                                <p class="card-text">Today's Bookings</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Recent Activities -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Recent Activities</h5>
                                <a href="admin_activity.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="card-body activity-timeline">
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="d-flex mb-3">
                                            <div class="me-3">
                                                <?php
                                                $icon_class = '';
                                                switch ($activity['activity_type']) {
                                                    case 'auto_checkout':
                                                        $icon_class = 'fas fa-clock text-primary';
                                                        break;
                                                    case 'check_in':
                                                        $icon_class = 'fas fa-sign-in-alt text-success';
                                                        break;
                                                    case 'check_out':
                                                        $icon_class = 'fas fa-sign-out-alt text-warning';
                                                        break;
                                                    case 'cancel':
                                                        $icon_class = 'fas fa-times text-danger';
                                                        break;
                                                    default:
                                                        $icon_class = 'fas fa-info-circle text-info';
                                                }
                                                ?>
                                                <i class="<?php echo $icon_class; ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold">
                                                    <?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?>
                                                    <?php if ($activity['room_number']): ?>
                                                        - Room <?php echo $activity['room_number']; ?>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo $activity['admin_name']; ?> • 
                                                    <?php echo date('M d, H:i', strtotime($activity['created_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center">No recent activities</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Rooms Scheduled for Auto Checkout -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-clock"></i> Rooms for Auto Checkout
                                    <small class="text-muted">(Tomorrow at 10:00 AM)</small>
                                </h5>
                            </div>
                            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                <?php if (!empty($upcoming_checkouts)): ?>
                                    <?php foreach ($upcoming_checkouts as $checkout): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                            <div>
                                                <strong>Room <?php echo $checkout['room_number']; ?></strong>
                                                <?php if ($checkout['room_name']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($checkout['room_name']); ?></small>
                                                <?php endif; ?>
                                                <br><small>Guest: <?php echo htmlspecialchars($checkout['guest_name']); ?></small>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted">
                                                    Checked in: <?php echo date('M d', strtotime($checkout['check_in_date'])); ?>
                                                    <?php if ($checkout['admin_name']): ?>
                                                        <br>By: <?php echo htmlspecialchars($checkout['admin_name']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center text-muted">
                                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                                        <p>No rooms scheduled for auto checkout</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Quick Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <a href="booking_manage.php" class="btn btn-primary w-100 mb-2">
                                            <i class="fas fa-calendar-check"></i> Manage Bookings
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="admin_activity.php" class="btn btn-info w-100 mb-2">
                                            <i class="fas fa-history"></i> View Activities
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="rooms.php" class="btn btn-success w-100 mb-2">
                                            <i class="fas fa-bed"></i> Manage Rooms
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-warning w-100 mb-2" onclick="location.reload()">
                                            <i class="fas fa-sync-alt"></i> Refresh Dashboard
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto refresh dashboard every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>