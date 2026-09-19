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



// --- PHYSICIANS / RATES / MEDICATION TYPES / STAFF ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_physician') {
        $physician_name = normalize_patient_name($_POST['physician_name'] ?? '');
        $consultation_rate = sanitize_decimal($_POST['consultation_rate'] ?? 0, 0.0, 1000000.0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($physician_name === '') {
            set_flash('Physician name is required.', 'error');
        } elseif (record_exists($conn, 'physicians', 'physician_name', $physician_name)) {
            set_flash('A physician with that name already exists.', 'error');
        } else {
            $stmt = $conn->prepare("INSERT INTO physicians (physician_name, consultation_rate, is_active) VALUES (?, ?, ?)");
            $stmt->bind_param("sdi", $physician_name, $consultation_rate, $is_active);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Physician added successfully.');
            } else {
                set_flash('Error adding physician: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    } elseif ($action === 'update_physician') {
        $physician_id = (int)($_POST['physician_id'] ?? 0);
        $physician_name = normalize_patient_name($_POST['physician_name'] ?? '');
        $consultation_rate = sanitize_decimal($_POST['consultation_rate'] ?? 0, 0.0, 1000000.0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($physician_id <= 0 || $physician_name === '') {
            set_flash('Invalid physician update request.', 'error');
        } elseif (record_exists($conn, 'physicians', 'physician_name', $physician_name, 'physician_id', $physician_id)) {
            set_flash('Another physician already uses that name.', 'error');
        } else {
            $stmt = $conn->prepare("UPDATE physicians SET physician_name = ?, consultation_rate = ?, is_active = ? WHERE physician_id = ?");
            $stmt->bind_param("sdii", $physician_name, $consultation_rate, $is_active, $physician_id);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Physician updated successfully.');
            } else {
                set_flash('Error updating physician: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    } elseif ($action === 'add_meds_type') {
        $meds_type_name = trim($_POST['meds_type_name'] ?? '');
        $is_consultation = isset($_POST['is_consultation']) ? 1 : 0;

        if ($meds_type_name === '') {
            set_flash('Meds type name is required.', 'error');
        } elseif (record_exists($conn, 'meds_types', 'meds_type_name', $meds_type_name)) {
            set_flash('A medication type with that name already exists.', 'error');
        } else {
            $stmt = $conn->prepare("INSERT INTO meds_types (meds_type_name, is_consultation) VALUES (?, ?)");
            $stmt->bind_param("si", $meds_type_name, $is_consultation);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Medication type added successfully.');
            } else {
                set_flash('Error adding medication type: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    } elseif ($action === 'update_meds_type') {
        $meds_type_id = (int)($_POST['meds_type_id'] ?? 0);
        $meds_type_name = trim($_POST['meds_type_name'] ?? '');
        $is_consultation = isset($_POST['is_consultation']) ? 1 : 0;

        if ($meds_type_id <= 0 || $meds_type_name === '') {
            set_flash('Invalid medication type update.', 'error');
        } elseif (record_exists($conn, 'meds_types', 'meds_type_name', $meds_type_name, 'meds_type_id', $meds_type_id)) {
            set_flash('Another medication type already has that name.', 'error');
        } else {
            $stmt = $conn->prepare("UPDATE meds_types SET meds_type_name = ?, is_consultation = ? WHERE meds_type_id = ?");
            $stmt->bind_param("sii", $meds_type_name, $is_consultation, $meds_type_id);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Medication type updated successfully.');
            } else {
                set_flash('Error updating medication type: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    } elseif ($action === 'delete_meds_type') {
        $meds_type_id = (int)($_POST['meds_type_id'] ?? 0);
        if ($meds_type_id > 0) {
            $stmt = $conn->prepare("DELETE FROM meds_types WHERE meds_type_id = ?");
            $stmt->bind_param("i", $meds_type_id);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Medication type deleted successfully.');
            } else {
                set_flash('Cannot delete medication type. It may already be used by patient records.', 'error');
            }
            $stmt->close();
        }
    } elseif ($action === 'add_staff') {
        $staff_name = normalize_patient_name($_POST['staff_name'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($staff_name === '') {
            set_flash('Staff name is required.', 'error');
        } elseif (record_exists($conn, 'staff', 'staff_name', $staff_name)) {
            set_flash('A staff member with that name already exists.', 'error');
        } else {
            $stmt = $conn->prepare("INSERT INTO staff (staff_name, is_active) VALUES (?, ?)");
            $stmt->bind_param("si", $staff_name, $is_active);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Staff member added successfully.');
            } else {
                set_flash('Error adding staff member: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    } elseif ($action === 'update_staff') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        $staff_name = normalize_patient_name($_POST['staff_name'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($staff_id <= 0 || $staff_name === '') {
            set_flash('Invalid staff update.', 'error');
        } elseif (record_exists($conn, 'staff', 'staff_name', $staff_name, 'staff_id', $staff_id)) {
            set_flash('Another staff member already has that name.', 'error');
        } else {
            $stmt = $conn->prepare("UPDATE staff SET staff_name = ?, is_active = ? WHERE staff_id = ?");
            $stmt->bind_param("sii", $staff_name, $is_active, $staff_id);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Staff member updated successfully.');
            } else {
                set_flash('Error updating staff member: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    } elseif ($action === 'delete_staff') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        if ($staff_id > 0) {
            $stmt = $conn->prepare("DELETE FROM staff WHERE staff_id = ?");
            $stmt->bind_param("i", $staff_id);
            if ($stmt->execute()) {
                redirect_with_msg('settings.php', 'Staff member deleted successfully.');
            } else {
                set_flash('Cannot delete staff member. It may already be referenced by records.', 'error');
            }
            $stmt->close();
        }
    }
}

// --- CSV EXPORTS ---
if (isset($_GET['export'])) {
    if ($_GET['export'] === 'physicians_csv') {
        $result = $conn->query("SELECT physician_name, consultation_rate, is_active, created_at FROM physicians ORDER BY physician_name");
        export_csv('physicians.csv', ['Physician Name', 'Consultation Rate', 'Active', 'Created At'], $result);
        exit;
    } elseif ($_GET['export'] === 'meds_types_csv') {
        $result = $conn->query("SELECT meds_type_name, is_consultation FROM meds_types ORDER BY meds_type_name");
        export_csv('meds_types.csv', ['Meds Type Name', 'Is Consultation'], $result);
        exit;
    } elseif ($_GET['export'] === 'staff_csv') {
        $result = $conn->query("SELECT staff_name, is_active FROM staff ORDER BY staff_name");
        export_csv('staff.csv', ['Staff Name', 'Active'], $result);
        exit;
    }
}

// --- LOAD PHYSICIANS / MEDICATION TYPES / STAFF ---
$physicians_res = $conn->query("SELECT * FROM physicians ORDER BY physician_name");
$physicians_list = $physicians_res ? $physicians_res->fetch_all(MYSQLI_ASSOC) : [];
$meds_types_res = $conn->query("SELECT * FROM meds_types ORDER BY meds_type_name");
$meds_types_list = $meds_types_res ? $meds_types_res->fetch_all(MYSQLI_ASSOC) : [];
$staff_res = $conn->query("SELECT * FROM staff ORDER BY staff_name");
$staff_list = $staff_res ? $staff_res->fetch_all(MYSQLI_ASSOC) : [];


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
<style>
/* Integrated Settings sections - intentionally kept simple to match the existing Settings page. */
.settings-section { margin-top: 1.5rem; }
.settings-toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.settings-toolbar h3 { margin:0; }
.settings-toolbar .toolbar-actions { display:flex; gap:6px; flex-wrap:wrap; }
.settings-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.settings-table th { background:#f8fafc; padding:9px 10px; text-align:left; border-bottom:1px solid #e2e8f0; }
.settings-table td { padding:9px 10px; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
.settings-table tr:last-child td { border-bottom:0; }
.settings-table .actions-cell { text-align:right; white-space:nowrap; }
.settings-inline-form { display:inline; margin:0; }
.settings-input { width:100%; box-sizing:border-box; padding:6px 8px; border:1px solid #ccc; border-radius:4px; }
.settings-add-row td { background:#fafafa; }
.settings-check { width:16px; height:16px; vertical-align:middle; }
.settings-button { padding:5px 9px; border:1px solid #bbb; border-radius:4px; background:#fff; cursor:pointer; }
.settings-button:hover { background:#f2f2f2; }
.settings-button-danger { color:#b00020; }
.settings-empty { padding:16px; text-align:center; color:#777; }
@media (max-width:768px){ .settings-table{font-size:.8rem;} .settings-table th,.settings-table td{padding:7px;} }
</style>
<style>
/* Settings-only refinements: preserve the existing YAKAP GAMOT colors while improving structure and interaction. */
.settings-nav {
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    margin:-0.25rem 0 1.25rem;
    padding:0.35rem;
    background:rgba(255,255,255,.9);
    border:1px solid var(--border-subtle);
    border-radius:var(--radius-sm);
    box-shadow:var(--shadow-sm);
    position:sticky;
    top:8px;
    z-index:20;
}
.settings-nav a {
    color:var(--text-secondary);
    text-decoration:none;
    font-size:.8rem;
    font-weight:600;
    padding:.45rem .7rem;
    border-radius:4px;
    transition:background-color .15s ease,color .15s ease,transform .15s ease;
}
.settings-nav a:hover, .settings-nav a:focus-visible, .settings-nav a.active {
    color:var(--primary);
    background:var(--primary-light);
    transform:translateY(-1px);
    outline:none;
}
.settings-nav a.active {
    box-shadow:inset 0 -2px 0 var(--primary);
}
.settings-intro { margin-bottom:1rem; }
.settings-intro h2 { margin-bottom:.2rem; }
.settings-intro p { margin:0; }
.settings-section { scroll-margin-top:72px; display:none; }
.settings-section.active { display:block; }
.settings-section + .settings-section { margin-top:1rem; }
.settings-toolbar { padding-bottom:.75rem; border-bottom:1px solid var(--border-subtle); }
.settings-toolbar h3 { font-size:1rem; }
.settings-add-panel {
    margin-bottom:1rem;
    padding:.85rem;
    background:#fafafa;
    border:1px solid var(--border-subtle);
    border-radius:var(--radius-sm);
}
.settings-add-title {
    margin:0 0 .65rem;
    font-size:.82rem;
    font-weight:700;
    color:var(--text-primary);
}
.settings-add-grid {
    display:grid;
    grid-template-columns:minmax(180px,1fr) minmax(120px,auto) auto;
    gap:.65rem;
    align-items:end;
}
.settings-add-grid.viewer-grid {
    grid-template-columns:repeat(4,minmax(150px,1fr)) auto;
}
.settings-add-grid .form-group-custom { min-width:0; }
.settings-table tbody tr { transition:background-color .12s ease; }
.settings-table tbody tr:not(.settings-add-row):hover { background:#f8fafc; }
.settings-table .actions-cell { width:1%; }
.settings-table .action-group {
    display:flex;
    justify-content:flex-end;
    align-items:center;
    gap:4px;
    min-height:30px;
}
.settings-table .action-group .settings-button,
.settings-table .action-group .btn-custom {
    opacity:0;
    transform:translateX(4px);
    transition:opacity .14s ease,transform .14s ease,background-color .14s ease,border-color .14s ease;
}
.settings-table tbody tr:hover .action-group .settings-button,
.settings-table tbody tr:hover .action-group .btn-custom,
.settings-table tbody tr:focus-within .action-group .settings-button,
.settings-table tbody tr:focus-within .action-group .btn-custom {
    opacity:1;
    transform:translateX(0);
}
.settings-table .settings-add-row .action-group .settings-button { opacity:1; transform:none; }
.settings-button { transition:background-color .14s ease,border-color .14s ease,color .14s ease,transform .14s ease; }
.settings-button:hover { transform:translateY(-1px); }
.settings-button:active { transform:translateY(0); }
.settings-button-danger:hover { background:#fef2f2; border-color:#fecaca; }
.settings-table td:first-child input { min-width:160px; }
.settings-check-label { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; font-size:.82rem; color:var(--text-secondary); }
.settings-status {
    display:inline-flex;
    align-items:center;
    gap:5px;
    font-size:.75rem;
    color:var(--text-secondary);
}
.settings-status-dot { width:7px; height:7px; border-radius:50%; background:#cbd5e1; }
.settings-status-dot.active { background:var(--success); }
.settings-export { font-size:.78rem; padding:.4rem .65rem; }
@media (max-width:900px) {
    .settings-add-grid.viewer-grid { grid-template-columns:repeat(2,minmax(150px,1fr)); }
    .settings-add-grid.viewer-grid > div:last-child { grid-column:1/-1; }
}
@media (max-width:768px) {
    .settings-nav { position:static; overflow-x:auto; flex-wrap:nowrap; }
    .settings-nav a { white-space:nowrap; }
    .settings-add-grid, .settings-add-grid.viewer-grid { grid-template-columns:1fr; }
    .settings-add-grid > div:last-child, .settings-add-grid.viewer-grid > div:last-child { grid-column:auto; }
    .settings-table .action-group .settings-button,
    .settings-table .action-group .btn-custom { opacity:1; transform:none; }
}
@media (prefers-reduced-motion:reduce) {
    .settings-nav a, .settings-table tbody tr, .settings-table .action-group .settings-button, .settings-table .action-group .btn-custom, .settings-button { transition:none; }
}
</style>


<div class="dashboard-container">
    <header class="header-bar settings-intro">
        <div class="header-title-wrapper">
            <h2>⚙️ System Settings</h2>
            <p>Manage application configurations and restricted user roles.</p>
        </div>
    </header>

    <nav class="settings-nav" aria-label="Settings sections">
        <a href="#user-access" data-settings-tab="user-access">Viewer Access</a>
        <a href="#physicians" data-settings-tab="physicians">Physicians</a>
        <a href="#medication-types" data-settings-tab="medication-types">Medication / Visit Types</a>
        <a href="#staff" data-settings-tab="staff">Staff &amp; Operations</a>
    </nav>

    <!-- USER ACCESS -->
    <section id="user-access" class="card-panel settings-section">
        <div class="section-heading">
            <div>
                <h3>Viewer Access Management</h3>
                <p class="section-help">Create and manage accounts with restricted read-only viewer privileges.</p>
            </div>
            <span class="section-count"><?= count($viewer_users) ?> viewer(s)</span>
        </div>

        <div class="settings-add-panel">
            <h4 class="settings-add-title">Add New Viewer</h4>
            <form method="post" action="settings.php">
                <input type="hidden" name="action" value="add_viewer">
                <div class="settings-add-grid viewer-grid">
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
                        <button type="submit" class="btn-custom btn-primary-custom" style="width:100%; justify-content:center;">➕ Add Viewer</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="settings-toolbar">
            <div><h3>Existing Viewer Accounts</h3></div>
        </div>
        <?php if (empty($viewer_users)): ?>
            <div class="empty-state">No viewer accounts created yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="modern-table settings-table">
                    <thead><tr><th>Full Name</th><th>Username</th><th>Role</th><th>Created</th><th style="text-align:right;">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($viewer_users as $viewer): ?>
                        <tr>
                            <td><?= h($viewer['full_name']) ?></td>
                            <td><?= h($viewer['username']) ?></td>
                            <td><span class="badge-viewer"><?= h(ucfirst($viewer['role'])) ?></span></td>
                            <td><?= h(date('M j, Y', strtotime($viewer['created_at']))) ?></td>
                            <td class="actions-cell">
                                <div class="action-group">
                                    <form method="post" action="settings.php" onsubmit="return confirm('Are you sure you want to delete this viewer account?');">
                                        <input type="hidden" name="action" value="delete_viewer">
                                        <input type="hidden" name="user_id" value="<?= (int)$viewer['user_id'] ?>">
                                        <button type="submit" class="btn-custom btn-danger-custom" style="padding:.3rem .5rem;font-size:.8rem;" title="Delete Viewer">🗑️ Delete</button>
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

    <!-- CLINICAL SETTINGS -->
    <section id="physicians" class="card-panel settings-section">
        <div class="settings-toolbar">
            <div>
                <h3>Physicians &amp; Consultation Rates</h3>
                <p class="section-help">Manage physicians and their consultation rates.</p>
            </div>
            <div class="toolbar-actions"><a href="?export=physicians_csv" class="btn-custom settings-export">📥 Physicians CSV</a></div>
        </div>

        <div class="settings-add-panel">
            <h4 class="settings-add-title">Add New Physician</h4>
            <div class="settings-add-grid">
                <div class="form-group-custom">
                    <label for="add_physician_name">Physician Name</label>
                    <input id="add_physician_name" class="modern-input" type="text" name="physician_name" placeholder="Enter physician name" form="add-physician" required>
                </div>
                <div class="form-group-custom">
                    <label for="add_physician_rate">Consultation Rate (₱)</label>
                    <input id="add_physician_rate" class="modern-input" type="number" step="0.01" min="0" name="consultation_rate" value="0.00" form="add-physician">
                </div>
                <div>
                    <label class="settings-check-label"><input class="settings-check" type="checkbox" name="is_active" value="1" form="add-physician" checked> Active</label>
                </div>
                <div><button class="settings-button" type="submit" form="add-physician">Add Physician</button></div>
            </div>
        </div>

        <div class="settings-toolbar"><div><h3>Existing Physicians</h3></div></div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>Physician Name</th><th>Consultation Rate (₱)</th><th style="text-align:center;">Status</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($physicians_list as $p): ?>
                    <tr>
                        <td><input class="settings-input" type="text" name="physician_name" value="<?= h($p['physician_name']) ?>" form="update-physician-<?= (int)$p['physician_id'] ?>" required></td>
                        <td><input class="settings-input" type="number" step="0.01" min="0" name="consultation_rate" value="<?= h($p['consultation_rate']) ?>" form="update-physician-<?= (int)$p['physician_id'] ?>"></td>
                        <td style="text-align:center;"><label class="settings-check-label"><input class="settings-check" type="checkbox" name="is_active" value="1" form="update-physician-<?= (int)$p['physician_id'] ?>" <?= (int)$p['is_active'] ? 'checked' : '' ?>> Active</label></td>
                        <td class="actions-cell"><div class="action-group"><button class="settings-button" type="submit" form="update-physician-<?= (int)$p['physician_id'] ?>">Save</button></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($physicians_list)): ?><tr><td colspan="4" class="settings-empty">No physicians found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- MEDICATION / VISIT CONFIGURATION -->
    <section id="medication-types" class="card-panel settings-section">
        <div class="settings-toolbar">
            <div>
                <h3>Medication / Visit Types</h3>
                <p class="section-help">Manage medication and visit categories used by the system.</p>
            </div>
            <div class="toolbar-actions"><a href="?export=meds_types_csv" class="btn-custom settings-export">📥 Meds Types CSV</a></div>
        </div>

        <div class="settings-add-panel">
            <h4 class="settings-add-title">Add New Medication / Visit Type</h4>
            <div class="settings-add-grid">
                <div class="form-group-custom">
                    <label for="add_meds_type_name">Medication / Visit Type</label>
                    <input id="add_meds_type_name" class="modern-input" type="text" name="meds_type_name" placeholder="Enter medication / visit type" form="add-meds-type" required>
                </div>
                <div><label class="settings-check-label"><input class="settings-check" type="checkbox" name="is_consultation" value="1" form="add-meds-type" checked> Counts as consultation</label></div>
                <div><button class="settings-button" type="submit" form="add-meds-type">Add Type</button></div>
            </div>
        </div>

        <div class="settings-toolbar"><div><h3>Existing Medication / Visit Types</h3></div></div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>Medication / Visit Type</th><th>Counts as Consultation</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($meds_types_list as $m): ?>
                    <tr>
                        <td><input class="settings-input" type="text" name="meds_type_name" value="<?= h($m['meds_type_name']) ?>" form="meds-edit-<?= (int)$m['meds_type_id'] ?>" required></td>
                        <td><label class="settings-check-label"><input class="settings-check" type="checkbox" name="is_consultation" value="1" form="meds-edit-<?= (int)$m['meds_type_id'] ?>" <?= (int)$m['is_consultation'] === 1 ? 'checked' : '' ?>> Counts as consultation</label></td>
                        <td class="actions-cell">
                            <div class="action-group">
                                <button class="settings-button" type="submit" form="meds-edit-<?= (int)$m['meds_type_id'] ?>">Save</button>
                                <form class="settings-inline-form" method="post" action="settings.php" onsubmit="return confirm('Delete this medication/visit type? This may fail if existing records use it.');">
                                    <input type="hidden" name="action" value="delete_meds_type"><input type="hidden" name="meds_type_id" value="<?= (int)$m['meds_type_id'] ?>">
                                    <button class="settings-button settings-button-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($meds_types_list)): ?><tr><td colspan="3" class="settings-empty">No medication / visit types found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- STAFF / OPERATIONS -->
    <section id="staff" class="card-panel settings-section">
        <div class="settings-toolbar">
            <div>
                <h3>Staff Members</h3>
                <p class="section-help">Manage staff names used by the system.</p>
            </div>
            <div class="toolbar-actions"><a href="?export=staff_csv" class="btn-custom settings-export">📥 Staff CSV</a></div>
        </div>

        <div class="settings-add-panel">
            <h4 class="settings-add-title">Add New Staff Member</h4>
            <div class="settings-add-grid">
                <div class="form-group-custom">
                    <label for="add_staff_name">Staff Name</label>
                    <input id="add_staff_name" class="modern-input" type="text" name="staff_name" placeholder="Enter staff name" form="add-staff" required>
                </div>
                <div><label class="settings-check-label"><input class="settings-check" type="checkbox" name="is_active" value="1" form="add-staff" checked> Active</label></div>
                <div><button class="settings-button" type="submit" form="add-staff">Add Staff</button></div>
            </div>
        </div>

        <div class="settings-toolbar"><div><h3>Existing Staff Members</h3></div></div>
        <div class="table-wrap">
            <table class="settings-table">
                <thead><tr><th>Staff Name</th><th>Status</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($staff_list as $s): ?>
                    <tr>
                        <td><input class="settings-input" type="text" name="staff_name" value="<?= h($s['staff_name']) ?>" form="staff-edit-<?= (int)$s['staff_id'] ?>" required></td>
                        <td><label class="settings-check-label"><input class="settings-check" type="checkbox" name="is_active" value="1" form="staff-edit-<?= (int)$s['staff_id'] ?>" <?= (int)$s['is_active'] === 1 ? 'checked' : '' ?>> Active</label></td>
                        <td class="actions-cell">
                            <div class="action-group">
                                <button class="settings-button" type="submit" form="staff-edit-<?= (int)$s['staff_id'] ?>">Save</button>
                                <form class="settings-inline-form" method="post" action="settings.php" onsubmit="return confirm('Delete this staff member?');">
                                    <input type="hidden" name="action" value="delete_staff"><input type="hidden" name="staff_id" value="<?= (int)$s['staff_id'] ?>">
                                    <button class="settings-button settings-button-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($staff_list)): ?><tr><td colspan="3" class="settings-empty">No staff members found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <script>
    (function () {
        const tabs = document.querySelectorAll('[data-settings-tab]');
        const sections = document.querySelectorAll('.settings-section');

        function showSettingsSection(id, updateHash) {
            const target = document.getElementById(id);
            if (!target) return;

            sections.forEach(function (section) {
                section.classList.toggle('active', section.id === id);
            });

            tabs.forEach(function (tab) {
                const active = tab.getAttribute('data-settings-tab') === id;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-current', active ? 'page' : 'false');
            });

            if (updateHash && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + id);
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                showSettingsSection(tab.getAttribute('data-settings-tab'), true);
            });
        });

        const initialId = window.location.hash.replace('#', '');
        showSettingsSection(
            document.getElementById(initialId) ? initialId : 'user-access',
            false
        );
    })();
    </script>

    <!-- Forms kept outside tables so the existing rows remain valid HTML. -->
    <form id="add-physician" method="post" action="settings.php" style="display:none;"><input type="hidden" name="action" value="add_physician"></form>
    <?php foreach ($physicians_list as $p): ?>
    <form id="update-physician-<?= (int)$p['physician_id'] ?>" method="post" action="settings.php" style="display:none;"><input type="hidden" name="action" value="update_physician"><input type="hidden" name="physician_id" value="<?= (int)$p['physician_id'] ?>"></form>
    <?php endforeach; ?>
    <?php foreach ($meds_types_list as $m): ?>
    <form id="meds-edit-<?= (int)$m['meds_type_id'] ?>" method="post" action="settings.php" style="display:none;"><input type="hidden" name="action" value="update_meds_type"><input type="hidden" name="meds_type_id" value="<?= (int)$m['meds_type_id'] ?>"></form>
    <?php endforeach; ?>
    <form id="add-meds-type" method="post" action="settings.php" style="display:none;"><input type="hidden" name="action" value="add_meds_type"></form>
    <?php foreach ($staff_list as $s): ?>
    <form id="staff-edit-<?= (int)$s['staff_id'] ?>" method="post" action="settings.php" style="display:none;"><input type="hidden" name="action" value="update_staff"><input type="hidden" name="staff_id" value="<?= (int)$s['staff_id'] ?>"></form>
    <?php endforeach; ?>
    <form id="add-staff" method="post" action="settings.php" style="display:none;"><input type="hidden" name="action" value="add_staff"></form>
</div>

<?php include 'includes/footer.php'; ?>
