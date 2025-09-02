<?php
require_once '../includes/functions.php';
require_once '../config/database.php';

require_role('OWNER');

$database = new Database();
$pdo = $database->getConnection();

// Handle resource updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_resource') {
            $resourceId = $_POST['resource_id'] ?? '';
            $customName = sanitize_input($_POST['custom_name'] ?? '');
            
            if (empty($resourceId)) {
                $error = 'Resource ID is required';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE resources SET custom_name = ? WHERE id = ?");
                    $stmt->execute([$customName ?: null, $resourceId]);
                    redirect_with_message('resources.php', 'Resource updated successfully!', 'success');
                } catch (Exception $e) {
                    $error = 'Failed to update resource';
                }
            }
        }
    }
}

// Get all resources
$stmt = $pdo->prepare("SELECT * FROM resources WHERE is_active = 1 ORDER BY type, CAST(identifier AS UNSIGNED), identifier");
$stmt->execute();
$resources = $stmt->fetchAll();

$flash = get_flash_message();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Resources - L.P.S.T Bookings</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <nav class="top-nav">
        <div class="nav-links">
            <a href="index.php" class="nav-button">← Dashboard</a>
            <a href="admins.php" class="nav-button">Admins</a>
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

        <h2>Manage Resources</h2>
        
        <div class="form-container">
            <h3>Room & Hall Names</h3>
            <p style="margin-bottom: 1.5rem; color: var(--dark-color);">
                Customize the display names for your rooms and halls. Leave custom name empty to use default name.
            </p>
            
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--light-color);">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Type</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Default Name</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Custom Name</th>
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid var(--border-color);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resources as $resource): ?>
                            <tr>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="background: <?= $resource['type'] === 'room' ? 'var(--primary-color)' : 'var(--success-color)' ?>; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                        <?= strtoupper($resource['type']) ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <?= htmlspecialchars($resource['display_name']) ?>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <form method="POST" style="display: flex; gap: 0.5rem; align-items: center;">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="action" value="update_resource">
                                        <input type="hidden" name="resource_id" value="<?= $resource['id'] ?>">
                                        <input type="text" name="custom_name" class="form-control" 
                                               value="<?= htmlspecialchars($resource['custom_name'] ?? '') ?>"
                                               placeholder="Enter custom name..."
                                               style="margin: 0; font-size: 0.9rem;">
                                        <button type="submit" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem; white-space: nowrap;">
                                            Update
                                        </button>
                                    </form>
                                </td>
                                <td style="padding: 0.75rem; border-bottom: 1px solid var(--border-color);">
                                    <span style="color: <?= $resource['custom_name'] ? 'var(--success-color)' : 'var(--dark-color)' ?>; font-size: 0.8rem;">
                                        <?= $resource['custom_name'] ? '✅ Customized' : '📝 Default' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="form-container">
            <h3>Preview</h3>
            <p style="margin-bottom: 1rem; color: var(--dark-color);">
                This is how your resources will appear to admins:
            </p>
            
            <div class="resources-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <?php foreach (array_slice($resources, 0, 6) as $resource): ?>
                    <div class="resource-box vacant" style="min-height: 120px; padding: 1rem;">
                        <div class="status-badge status-vacant">VACANT</div>
                        <div class="resource-title" style="font-size: 1rem;">
                            <?= htmlspecialchars($resource['custom_name'] ?: $resource['display_name']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--dark-color); margin-top: 0.5rem;">
                            <?= ucfirst($resource['type']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>