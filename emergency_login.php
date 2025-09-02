<?php
require_once 'includes/functions.php';
require_once 'config/database.php';

$database = new Database();
$pdo = $database->getConnection();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        
        if (empty($username)) {
            $error = 'Please enter username';
        } else {
            $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE username = ? AND role = 'OWNER'");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['emergency_login'] = true;
                
                header('Location: owner/index.php');
                exit;
            } else {
                $error = 'Owner username not found';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Login - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1 style="text-align: center; margin-bottom: 2rem; color: var(--danger-color);">Emergency Owner Login</h1>
            
            <?php if ($error): ?>
                <div class="flash-message flash-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <div class="form-group">
                    <label for="username" class="form-label">Owner Username</label>
                    <input type="text" id="username" name="username" class="form-control" required 
                           value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
                </div>
                
                <button type="submit" class="btn btn-danger" style="width: 100%;">Emergency Login</button>
            </form>
            
            <div style="margin-top: 2rem; text-align: center;">
                <a href="login.php" class="btn btn-outline">Back to Normal Login</a>
            </div>
        </div>
    </div>
</body>
</html>