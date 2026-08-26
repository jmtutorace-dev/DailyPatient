<?php
/**
 * YAKAP GAMOT SYSTEM - Physicians & Rates (physicians.php)
 * 
 * Redesigned for a minimal, professional healthcare aesthetic while preserving 
 * all backend logic, database operations, form handling, and exports.
 */

require_once 'config.php';
require_once 'includes/auth.php';

// --- 1. CSV EXPORT HANDLER ---
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

// --- 2. POST ACTIONS HANDLER ---
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
                redirect_with_msg('physicians.php', 'Physician added successfully.');
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
                redirect_with_msg('physicians.php', 'Physician updated successfully.');
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
                redirect_with_msg('physicians.php', 'Medication type added successfully.');
            } else {
                set_flash('Error adding medication type: ' . $stmt->error, 'error');
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
                redirect_with_msg('physicians.php', 'Staff member added successfully.');
            } else {
                set_flash('Error adding staff member: ' . $stmt->error, 'error');
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
                redirect_with_msg('physicians.php', 'Medication type updated successfully.');
            } else {
                set_flash('Error updating medication type: ' . $stmt->error, 'error');
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
                redirect_with_msg('physicians.php', 'Staff member updated successfully.');
            } else {
                set_flash('Error updating staff member: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }

    } elseif ($action === 'delete_meds_type') {
        $meds_type_id = (int)($_POST['meds_type_id'] ?? 0);
        if ($meds_type_id > 0) {
            $stmt = $conn->prepare("DELETE FROM meds_types WHERE meds_type_id = ?");
            $stmt->bind_param("i", $meds_type_id);
            if ($stmt->execute()) {
                redirect_with_msg('physicians.php', 'Medication type deleted successfully.');
            } else {
                set_flash('Cannot delete medication type. It may already be used by patient records.', 'error');
            }
            $stmt->close();
        }

    } elseif ($action === 'delete_staff') {
        $staff_id = (int)($_POST['staff_id'] ?? 0);
        if ($staff_id > 0) {
            $stmt = $conn->prepare("DELETE FROM staff WHERE staff_id = ?");
            $stmt->bind_param("i", $staff_id);
            if ($stmt->execute()) {
                redirect_with_msg('physicians.php', 'Staff member deleted successfully.');
            } else {
                set_flash('Cannot delete staff member. It may already be referenced by records.', 'error');
            }
            $stmt->close();
        }
    }
}

// --- 3. FETCH DATA & STATS FOR VIEW ---
$physicians_res = $conn->query("SELECT * FROM physicians ORDER BY physician_name");
$physicians_list = $physicians_res ? $physicians_res->fetch_all(MYSQLI_ASSOC) : [];

$meds_types_res = $conn->query("SELECT * FROM meds_types ORDER BY meds_type_name");
$meds_types_list = $meds_types_res ? $meds_types_res->fetch_all(MYSQLI_ASSOC) : [];

$staff_res = $conn->query("SELECT * FROM staff ORDER BY staff_name");
$staff_list = $staff_res ? $staff_res->fetch_all(MYSQLI_ASSOC) : [];

// Summary Metrics
$total_physicians  = count($physicians_list);
$active_physicians = count(array_filter($physicians_list, fn($p) => (int)$p['is_active'] === 1));
$total_meds_types  = count($meds_types_list);
$total_staff       = count($staff_list);

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

.header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
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

/* KPI Summary Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.kpi-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--border-subtle);
}

.kpi-card.accent-primary::before { background: var(--primary); }
.kpi-card.accent-info::before { background: var(--info); }
.kpi-card.accent-success::before { background: var(--success); }

.kpi-card .kpi-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 0.04em;
}

.kpi-card .kpi-value {
    font-size: 1.625rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 0.35rem;
    line-height: 1;
}

/* Card Sections */
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

.add-new-row td {
    background: #f8fafc;
    border-top: 1px solid var(--border-subtle);
}

/* Inputs & Forms */
.modern-input {
    width: 100%;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    color: var(--text-primary);
    background-color: #ffffff;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.modern-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.1);
}

.checkbox-custom {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--primary);
}

.check-label {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--text-primary);
}

/* Row Actions & Status Badges */
.row-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.5rem;
}

.row-actions form {
    margin: 0;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.status-pill.active { background: #d1fae5; color: #065f46; }
.status-pill.inactive { background: #fee2e2; color: #991b1b; }

@media print {
    .header-bar, .add-new-row, .row-actions button, .btn-save { display: none !important; }
    .card-panel { border: none; box-shadow: none; padding: 0; }
}

@media (max-width: 700px) {
    .dashboard-container { padding: 0.5rem; }
    .header-actions { width: 100%; }
    .header-actions .btn-custom { flex: 1; justify-content: center; }
    .section-heading { flex-direction: column; }
    .row-actions { justify-content: flex-start; }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <header class="header-bar">
        <div class="header-title-wrapper">
            <h2>Physicians, Rates & System Settings</h2>
            <p>Manage clinical profiles, consultation rates, medication categories, and registered operational staff.</p>
        </div>
        <div class="header-actions">
            <a href="?export=physicians_csv" class="btn-custom"><span>📥</span> Physicians CSV</a>
            <a href="?export=meds_types_csv" class="btn-custom"><span>📥</span> Meds Types CSV</a>
            <a href="?export=staff_csv" class="btn-custom"><span>📥</span> Staff CSV</a>
        </div>
    </header>

    <!-- KPI Summary Grid -->
    <section class="kpi-grid">
        <div class="kpi-card accent-primary">
            <div class="kpi-title">Active Physicians</div>
            <div class="kpi-value"><?= $active_physicians ?> <span style="font-size: 1rem; color: var(--text-secondary); font-weight: 500;">/ <?= $total_physicians ?></span></div>
        </div>
        <div class="kpi-card accent-info">
            <div class="kpi-title">Medication Types</div>
            <div class="kpi-value"><?= $total_meds_types ?></div>
        </div>
        <div class="kpi-card accent-success">
            <div class="kpi-title">Registered Staff</div>
            <div class="kpi-value"><?= $total_staff ?></div>
        </div>
    </section>

    <!-- SECTION 1: PHYSICIANS TABLE -->
    <section class="card-panel">
        <div class="section-heading">
            <div>
                <h3>Physicians & Consultation Rates</h3>
                <p class="section-help">Configure medical practitioners and their corresponding consultation service rates.</p>
            </div>
            <span class="section-count"><?= $total_physicians ?> registered</span>
        </div>

        <div class="table-wrap">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width: 38%;">Physician Name</th>
                        <th style="width: 25%;">Consultation Rate (₱)</th>
                        <th style="width: 17%; text-align: center;">Active Status</th>
                        <th style="width: 20%; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($physicians_list as $p): ?>
                        <tr>
                            <td>
                                <input type="text" name="physician_name" class="modern-input" value="<?= h($p['physician_name']) ?>" form="update-physician-<?= (int)$p['physician_id'] ?>" required>
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="consultation_rate" class="modern-input" value="<?= h($p['consultation_rate']) ?>" form="update-physician-<?= (int)$p['physician_id'] ?>" style="max-width: 160px;">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="is_active" value="1" class="checkbox-custom" form="update-physician-<?= (int)$p['physician_id'] ?>" <?= $p['is_active'] ? 'checked' : '' ?>>
                            </td>
                            <td style="text-align: right;">
                                <button type="submit" class="btn-custom btn-primary-custom btn-save" style="padding: 0.3rem 0.7rem; font-size: 0.8rem;" form="update-physician-<?= (int)$p['physician_id'] ?>">Save</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Add New Physician Row -->
                    <tr class="add-new-row">
                        <td>
                            <input type="text" name="physician_name" class="modern-input" placeholder="Enter new physician name" form="add-physician" required>
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" name="consultation_rate" class="modern-input" value="0.00" form="add-physician" style="max-width: 160px;">
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox" name="is_active" value="1" class="checkbox-custom" form="add-physician" checked>
                        </td>
                        <td style="text-align: right;">
                            <button type="submit" class="btn-custom btn-primary-custom" style="padding: 0.3rem 0.7rem; font-size: 0.8rem;" form="add-physician">➕ Add Physician</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- SECTION 2: MEDS TYPES TABLE -->
    <section class="card-panel">
        <div class="section-heading">
            <div>
                <h3>Medication / Visit Types</h3>
                <p class="section-help">Categories used by the Daily Log to classify clinical visits and consultation requirements.</p>
            </div>
            <span class="section-count"><?= $total_meds_types ?> type(s)</span>
        </div>

        <div class="table-wrap">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Medication / Visit Type</th>
                        <th style="width: 35%;">Counts as Consultation</th>
                        <th style="width: 20%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($meds_types_list as $m): ?>
                    <tr>
                        <form method="post" action="physicians.php" id="meds-edit-<?= (int)$m['meds_type_id'] ?>">
                            <input type="hidden" name="action" value="update_meds_type">
                            <input type="hidden" name="meds_type_id" value="<?= (int)$m['meds_type_id'] ?>">
                        </form>
                        <td>
                            <input type="text" name="meds_type_name" class="modern-input"
                                   value="<?= h($m['meds_type_name']) ?>"
                                   form="meds-edit-<?= (int)$m['meds_type_id'] ?>" required>
                        </td>
                        <td>
                            <label class="check-label">
                                <input type="checkbox" name="is_consultation" value="1" class="checkbox-custom"
                                       form="meds-edit-<?= (int)$m['meds_type_id'] ?>"
                                       <?= (int)$m['is_consultation'] === 1 ? 'checked' : '' ?>>
                                <span>Counts as consultation</span>
                            </label>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="submit" class="btn-custom btn-primary-custom" style="padding: 0.3rem 0.7rem; font-size: 0.8rem;" form="meds-edit-<?= (int)$m['meds_type_id'] ?>">Save</button>
                                <form method="post" action="physicians.php" onsubmit="return confirm('Delete this medication/visit type? This may fail if existing records use it.');">
                                    <input type="hidden" name="action" value="delete_meds_type">
                                    <input type="hidden" name="meds_type_id" value="<?= (int)$m['meds_type_id'] ?>">
                                    <button type="submit" class="btn-custom btn-danger-custom" style="padding: 0.3rem 0.5rem; font-size: 0.8rem;" title="Delete">🗑️</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                    <tr class="add-new-row">
                        <form method="post" action="physicians.php" id="add-meds-type">
                            <input type="hidden" name="action" value="add_meds_type">
                        </form>
                        <td>
                            <input type="text" name="meds_type_name" class="modern-input"
                                   placeholder="Enter new medication / visit type"
                                   form="add-meds-type" required>
                        </td>
                        <td>
                            <label class="check-label">
                                <input type="checkbox" name="is_consultation" value="1" class="checkbox-custom"
                                       form="add-meds-type" checked>
                                <span>Counts as consultation</span>
                            </label>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="submit" class="btn-custom btn-primary-custom" style="padding: 0.3rem 0.7rem; font-size: 0.8rem;" form="add-meds-type">➕ Add Type</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- SECTION 3: STAFF TABLE -->
    <section class="card-panel">
        <div class="section-heading">
            <div>
                <h3>Staff Members</h3>
                <p class="section-help">Manage personnel names used for transmittal tracking and operational workflows.</p>
            </div>
            <span class="section-count"><?= $total_staff ?> staff</span>
        </div>

        <div class="table-wrap">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Staff Name</th>
                        <th style="width: 35%;">Status</th>
                        <th style="width: 20%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staff_list as $s): ?>
                    <tr>
                        <form method="post" action="physicians.php" id="staff-edit-<?= (int)$s['staff_id'] ?>">
                            <input type="hidden" name="action" value="update_staff">
                            <input type="hidden" name="staff_id" value="<?= (int)$s['staff_id'] ?>">
                        </form>
                        <td>
                            <input type="text" name="staff_name" class="modern-input"
                                   value="<?= h($s['staff_name']) ?>"
                                   form="staff-edit-<?= (int)$s['staff_id'] ?>" required>
                        </td>
                        <td>
                            <label class="check-label">
                                <input type="checkbox" name="is_active" value="1" class="checkbox-custom"
                                       form="staff-edit-<?= (int)$s['staff_id'] ?>"
                                       <?= (int)$s['is_active'] === 1 ? 'checked' : '' ?>>
                                <span class="status-pill <?= (int)$s['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                    <?= (int)$s['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                            </label>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="submit" class="btn-custom btn-primary-custom" style="padding: 0.3rem 0.7rem; font-size: 0.8rem;" form="staff-edit-<?= (int)$s['staff_id'] ?>">Save</button>
                                <form method="post" action="physicians.php" onsubmit="return confirm('Delete this staff member?');">
                                    <input type="hidden" name="action" value="delete_staff">
                                    <input type="hidden" name="staff_id" value="<?= (int)$s['staff_id'] ?>">
                                    <button type="submit" class="btn-custom btn-danger-custom" style="padding: 0.3rem 0.5rem; font-size: 0.8rem;" title="Delete">🗑️</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                    <tr class="add-new-row">
                        <form method="post" action="physicians.php" id="add-staff">
                            <input type="hidden" name="action" value="add_staff">
                        </form>
                        <td>
                            <input type="text" name="staff_name" class="modern-input"
                                   placeholder="Enter new staff name" form="add-staff" required>
                        </td>
                        <td>
                            <label class="check-label">
                                <input type="checkbox" name="is_active" value="1" class="checkbox-custom"
                                       form="add-staff" checked>
                                <span>Active Member</span>
                            </label>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="submit" class="btn-custom btn-primary-custom" style="padding: 0.3rem 0.7rem; font-size: 0.8rem;" form="add-staff">➕ Add Staff</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
// Hidden forms for adding and updating records outside tables
?>
<form id="add-physician" method="post" action="physicians.php" style="display:none;">
    <input type="hidden" name="action" value="add_physician">
</form>
<?php foreach ($physicians_list as $p): ?>
    <form id="update-physician-<?= (int)$p['physician_id'] ?>" method="post" action="physicians.php" style="display:none;">
        <input type="hidden" name="action" value="update_physician">
        <input type="hidden" name="physician_id" value="<?= (int)$p['physician_id'] ?>">
    </form>
<?php endforeach; ?>

<?php include 'includes/footer.php'; ?>