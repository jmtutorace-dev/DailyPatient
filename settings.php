<?php
require_once 'config.php';
require_once 'includes/auth.php';

if (!is_admin()) {
    redirect_with_msg('dashboard.php', 'Access denied.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_viewer') {
    $new_username = trim($_POST['username'] ?? '');
    $new_full_name = trim($_POST['full_name'] ?? '');
    $new_password = $_POST['password'] ?? '';
    $new_password_confirm = $_POST['password_confirm'] ?? '';

    if ($new_username === '' || $new_full_name === '') {
        set_flash('Username and full name are required.', 'error');
    } elseif (strlen($new_username) < 3) {
        set_flash('Username must be at least 3 characters long.', 'error');
    } elseif (strlen($new_password) < 4) {
        set_flash('Password must be at least 4 characters long.', 'error');
    } elseif ($new_password !== $new_password_confirm) {
        set_flash('Password confirmation does not match.', 'error');
    } else {
        $existing = $conn->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
        $existing->bind_param('s', $new_username);
        $existing->execute();
        $already = $existing->get_result()->fetch_assoc();
        $existing->close();

        if ($already) {
            set_flash('That username is already in use. Please choose another one.', 'error');
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $role = 'viewer';
            $stmt = $conn->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)');
            $stmt->bind_param('ssss', $new_username, $hash, $new_full_name, $role);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Viewer account created successfully.');
            } else {
                set_flash('Error creating viewer account: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    }
}

$viewer_users = $conn->query("SELECT user_id, username, full_name, role, is_active, created_at FROM users WHERE role = 'viewer' ORDER BY full_name ASC, username ASC")->fetch_all(MYSQLI_ASSOC);

include 'includes/header.php';
?>

<div class="page-header">
    <h2>⚙️ Settings</h2>
</div>

<div class="card">
    <h2>Viewer Access Management</h2>

    <form method="post" action="settings.php" class="filter-form" style="margin-bottom:1.25rem; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="action" value="add_viewer">
        <div class="form-group" style="min-width:220px;">
            <label for="viewer_full_name">Full Name</label>
            <input type="text" id="viewer_full_name" name="full_name" placeholder="e.g. Jane Viewer" required>
        </div>
        <div class="form-group" style="min-width:220px;">
            <label for="viewer_username">Username</label>
            <input type="text" id="viewer_username" name="username" placeholder="e.g. janew" required>
        </div>
        <div class="form-group" style="min-width:180px;">
            <label for="viewer_password">Password</label>
            <input type="password" id="viewer_password" name="password" placeholder="Minimum 4 chars" required>
        </div>
        <div class="form-group" style="min-width:180px;">
            <label for="viewer_password_confirm">Confirm Password</label>
            <input type="password" id="viewer_password_confirm" name="password_confirm" placeholder="Re-enter password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">+ Add Viewer</button>
    </form>

    <?php if (empty($viewer_users)): ?>
        <p class="empty-state">No viewer accounts created yet.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewer_users as $viewer): ?>
                        <tr>
                            <td><?= h($viewer['full_name']) ?></td>
                            <td><?= h($viewer['username']) ?></td>
                            <td><span class="badge badge-viewer"><?= h(ucfirst($viewer['role'])) ?></span></td>
                            <td><?= h(date('M j, Y', strtotime($viewer['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
