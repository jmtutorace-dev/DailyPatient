<?php
/**
 * YAKAP GAMOT SYSTEM - Settings & Viewer Access Management (settings.php)
 * 
 * Redesigned for a minimal, professional healthcare aesthetic while preserving 
 * all backend logic, database operations, form handling, and adding a delete button.
 */

require_once 'config.php';
require_once 'includes/auth.php';

if (!is_admin()) {
    redirect_with_msg('dashboard.php', 'Access denied.', 'error');
}

// --- POST ACTIONS HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_viewer') {
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
    } elseif ($action === 'delete_viewer') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        // Prevent deleting oneself or non-viewer accounts accidentally if necessary, but here we restrict to role = 'viewer'
        if ($user_id > 0) {
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'viewer'");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Viewer account deleted successfully.');
            } else {
                set_flash('Error deleting viewer account: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    }
}

$viewer_users = $conn->query("SELECT user_id, username, full_name, role, is_active, created_at FROM users WHERE role = 'viewer' ORDER BY full_name ASC, username ASC")->fetch_all(MYSQLI_ASSOC);

include 'includes/header.php';
?>

<style>
/* Modern Healthcare Dashboard Design System */
:root {
    --primary: #0f766e;          /* Clinical Teal */
    --primary-hover: #115e59;
    --primary-light: #f0fdfa;
    --bg-main: #f8fafc;
    --bg-card: #ffffff;
    --border-subtle: #e2e8f0;
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --success: #059669;
    --danger: #dc2626;
    --warning: #d97706;
    --info: #0284c7;
    --radius-sm: 6px;
    --radius-md: 10px;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}

body {
    background-color: var(--bg-main);
    color: var(--text-primary);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.dashboard-container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 1.5rem 1rem;
}

/* Header Section */
.header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    gap: 1rem;
    flex-wrap: wrap;
}

.header-title-wrapper h2 {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.01em;
}

.header-title-wrapper p {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin: 0;
}

/* Modern Minimalist Buttons */
.btn-custom {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.875rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-subtle);
    background: var(--bg-card);
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-custom:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: var(--text-primary);
}

.btn-custom.btn-primary-custom {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-custom.btn-primary-custom:hover {
    background: var(--primary-hover);
    border-color: var(--primary-hover);
    color: white;
}

.btn-custom.btn-danger-custom {
    background: transparent;
    color: var(--danger);
    border-color: transparent;
}

.btn-custom.btn-danger-custom:hover {
    background: #fef2f2;
    border-color: #fecaca;
    color: var(--danger);
}

/* Card Panels */
.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.section-heading h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 0.2rem 0;
}

.section-help {
    margin: 0;
    color: var(--text-secondary);
    font-size: 0.8rem;
}

.section-count {
    background: #f1f5f9;
    color: var(--text-secondary);
    padding: 0.2rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
}

/* Form Grid & Layout */
.settings-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) auto;
    gap: 1rem;
    align-items: flex-end;
    margin-bottom: 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid var(--border-subtle);
}

.form-group-custom {
    display: flex;
    flex-direction: column;
    gap: 0.35srem;
}

.form-group-custom label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.modern-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    color: var(--text-primary);
    background-color: #ffffff;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.modern-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.1);
}

/* Modern Tables */
.table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
}

.modern-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
    white-space: nowrap;
}

.modern-table th {
    background: #f8fafc;
    padding: 0.75rem 1rem;
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 1px solid var(--border-subtle);
}

.modern-table td {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
    vertical-align: middle;
}

.modern-table tbody tr:last-child td {
    border-bottom: none;
}

.modern-table tbody tr:hover {
    background-color: #f8fafc;
}

/* Badges & Status */
.badge-viewer {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
    background: #e0f2fe;
    color: #0369a1;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.row-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.5rem;
}

.row-actions form {
    margin: 0;
}

@media (max-width: 768px) {
    .dashboard-container { padding: 0.5rem; }
    .settings-form-grid { grid-template-columns: 1fr; }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <header class="header-bar">
        <div class="header-title-wrapper">
            <h2>⚙️ System Settings</h2>
            <p>Manage application configurations and restricted user roles.</p>
        </div>
    </header>

    <!-- Viewer Access Management Panel -->
    <section class="card-panel">
        <div class="section-heading">
            <div>
                <h3>Viewer Access Management</h3>
                <p class="section-help">Create and manage accounts with restricted read-only viewer privileges.</p>
            </div>
            <span class="section-count"><?= count($viewer_users) ?> viewer(s)</span>
        </div>

        <!-- Add Viewer Form -->
        <form method="post" action="settings.php">
            <input type="hidden" name="action" value="add_viewer">
            <div class="settings-form-grid">
                <div class="form-group-custom">
                    <label for="viewer_full_name">Full Name</label>
                    <input type="text" id="viewer_full_name" name="full_name" class="modern-input" placeholder="e.g. Jane Viewer" required>
                </div>
                <div class="form-group-custom">
                    <label for="viewer_username">Username</label>
                    <input type="text" id="viewer_username" name="username" class="modern-input" placeholder="e.g. janew" required>
                </div>
                <div class="form-group-custom">
                    <label for="viewer_password">Password</label>
                    <input type="password" id="viewer_password" name="password" class="modern-input" placeholder="Minimum 4 chars" required>
                </div>
                <div class="form-group-custom">
                    <label for="viewer_password_confirm">Confirm Password</label>
                    <input type="password" id="viewer_password_confirm" name="password_confirm" class="modern-input" placeholder="Re-enter password" required>
                </div>
                <div>
                    <button type="submit" class="btn-custom btn-primary-custom" style="width: 100%; justify-content: center;">➕ Add Viewer</button>
                </div>
            </div>
        </form>

        <!-- Viewers Table -->
        <?php if (empty($viewer_users)): ?>
            <div class="empty-state">No viewer accounts created yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($viewer_users as $viewer): ?>
                            <tr>
                                <td><?= h($viewer['full_name']) ?></td>
                                <td><?= h($viewer['username']) ?></td>
                                <td><span class="badge-viewer"><?= h(ucfirst($viewer['role'])) ?></span></td>
                                <td><?= h(date('M j, Y', strtotime($viewer['created_at']))) ?></td>
                                <td style="text-align: right;">
                                    <div class="row-actions">
                                        <form method="post" action="settings.php" onsubmit="return confirm('Are you sure you want to delete this viewer account?');">
                                            <input type="hidden" name="action" value="delete_viewer">
                                            <input type="hidden" name="user_id" value="<?= (int)$viewer['user_id'] ?>">
                                            <button type="submit" class="btn-custom btn-danger-custom" style="padding: 0.3rem 0.5rem; font-size: 0.8rem;" title="Delete Viewer">🗑️ Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php include 'includes/footer.php'; ?>