<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

$database = new Database();
$pdo = $database->getConnection();

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                $username = sanitize_input($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                
                if (empty($username) || empty($password)) {
                    $error = 'Username and password are required';
                } else {
                    try {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'ADMIN')");
                        $stmt->execute([$username, $hashedPassword]);
                        redirect_with_message('admins.php', 'Admin added successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to add admin - username may already exist';
                    }
                }
                break;
                
            case 'reset_password':
                $userId = $_POST['user_id'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                
                if (empty($userId) || empty($newPassword)) {
                    $error = 'User ID and new password are required';
                } else {
                    try {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'ADMIN'");
                        $stmt->execute([$hashedPassword, $userId]);
                        redirect_with_message('admins.php', 'Password reset successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to reset password';
                    }
                }
                break;
                
            case 'delete':
                $userId = $_POST['user_id'] ?? '';
                
                if (empty($userId)) {
                    $error = 'User ID is required';
                } else {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'ADMIN'");
                        $stmt->execute([$userId]);
                        redirect_with_message('admins.php', 'Admin deleted successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to delete admin';
                    }
                }
                break;
                
            case 'edit_username':
                $userId = $_POST['user_id'] ?? '';
                $newUsername = sanitize_input($_POST['new_username'] ?? '');
                
                if (empty($userId) || empty($newUsername)) {
                    $error = 'User ID and new username are required';
                } else {
                    try {
                        $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ? AND role = 'ADMIN'");
                        $stmt->execute([$newUsername, $userId]);
                        redirect_with_message('admins.php', 'Username updated successfully!', 'success');
                    } catch (Exception $e) {
                        $error = 'Failed to update username - it may already exist';
                    }
                }
                break;
        }
    }
}

// Get all admins
$stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'ADMIN' ORDER BY username");
$stmt->execute();
$admins = $stmt->fetchAll();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="index.php" class="nav-button">← Dashboard</a>
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
        
        <?php if (isset($error)): ?>
            <div class="flash-message flash-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <h2>Manage Admins</h2>
        
        <!-- Add New Admin -->
        <div class="form-container">
            <h3>Add New Admin</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label for="username" class="form-label">Username *</label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password *</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Add Admin</button>
            </form>
        </div>
        
        <!-- Existing Admins -->
        <div class="form-container">
            <h3>Existing Admins</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Username</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Created</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($admin['username']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= date('M j, Y', strtotime($admin['created_at'])) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <div style="display: flex; gap: 0.5rem;">
                                        <button onclick="showEditUsername(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>')" 
                                                class="btn btn-primary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                            Edit Username
                                        </button>
                                        <button onclick="showResetPassword(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>')" 
                                                class="btn btn-warning" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                            Reset Password
                                        </button>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Delete admin <?= htmlspecialchars($admin['username']) ?>?')">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= $admin['id'] ?>">
                                            <button type="submit" class="btn btn-danger" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit Username Modal -->
    <div id="editUsernameModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Username</h3>
                <button type="button" class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form id="editUsernameForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="edit_username">
                <input type="hidden" name="user_id" id="editUserId">
                
                <div class="form-group">
                    <label class="form-label">Current Username</label>
                    <input type="text" id="currentUsername" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="new_username" class="form-label">New Username *</label>
                    <input type="text" id="new_username" name="new_username" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Update Username</button>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reset Password</h3>
                <button type="button" class="close-modal" onclick="closeResetModal()">&times;</button>
            </div>
            <form id="resetPasswordForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                
                <div class="form-group">
                    <label class="form-label">Admin Username</label>
                    <input type="text" id="resetUsername" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label for="new_password" class="form-label">New Password *</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </form>
        </div>
    </div>

    <script>
        function showEditUsername(userId, username) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('currentUsername').value = username;
            document.getElementById('new_username').value = username;
            document.getElementById('editUsernameModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editUsernameModal').style.display = 'none';
            document.getElementById('editUsernameForm').reset();
        }
        
        function showResetPassword(userId, username) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUsername').value = username;
            document.getElementById('resetPasswordModal').style.display = 'flex';
        }
        
        function closeResetModal() {
            document.getElementById('resetPasswordModal').style.display = 'none';
            document.getElementById('resetPasswordForm').reset();
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const editModal = document.getElementById('editUsernameModal');
            const modal = document.getElementById('resetPasswordModal');
            if (e.target === editModal) {
                closeEditModal();
            }
            if (e.target === modal) {
                closeResetModal();
            }
        });
    </script>
</body>
</html>