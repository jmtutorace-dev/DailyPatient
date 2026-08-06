<?php
/**
 * YAKAP GAMOT SYSTEM - Physicians & Rates (physicians.php)
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
        $physician_name    = trim($_POST['physician_name'] ?? '');
        $consultation_rate = (float)($_POST['consultation_rate'] ?? 0);
        $is_active         = isset($_POST['is_active']) ? 1 : 0;

        if (!empty($physician_name)) {
            $stmt = $conn->prepare("INSERT INTO physicians (physician_name, consultation_rate, is_active) VALUES (?, ?, ?)");
            $stmt->bind_param("sdi", $physician_name, $consultation_rate, $is_active);
            if ($stmt->execute()) {
                redirect_with_msg('physicians.php', 'Physician added successfully.');
            } else {
                $msg = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            set_flash('Physician name is required.', 'error');
        }

    } elseif ($action === 'update_physician') {
        $physician_id      = (int)($_POST['physician_id'] ?? 0);
        $physician_name    = trim($_POST['physician_name'] ?? '');
        $consultation_rate = (float)($_POST['consultation_rate'] ?? 0);
        $is_active         = isset($_POST['is_active']) ? 1 : 0;

        if ($physician_id > 0 && !empty($physician_name)) {
            $stmt = $conn->prepare("UPDATE physicians SET physician_name = ?, consultation_rate = ?, is_active = ? WHERE physician_id = ?");
            $stmt->bind_param("sdii", $physician_name, $consultation_rate, $is_active, $physician_id);
            if ($stmt->execute()) {
                redirect_with_msg('physicians.php', 'Physician updated successfully.');
            } else {
                $msg = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            set_flash('Invalid update request.', 'error');
        }

    } elseif ($action === 'add_meds_type') {
        $meds_type_name  = trim($_POST['meds_type_name'] ?? '');
        $is_consultation = isset($_POST['is_consultation']) ? 1 : 0;

        if (!empty($meds_type_name)) {
            $stmt = $conn->prepare("INSERT INTO meds_types (meds_type_name, is_consultation) VALUES (?, ?)");
            $stmt->bind_param("si", $meds_type_name, $is_consultation);
            if ($stmt->execute()) {
                redirect_with_msg('physicians.php', 'Meds type added successfully.');
            } else {
                $msg = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            set_flash('Meds type name is required.', 'error');
        }

    } elseif ($action === 'add_staff') {
        $staff_name = trim($_POST['staff_name'] ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (!empty($staff_name)) {
            $stmt = $conn->prepare("INSERT INTO staff (staff_name, is_active) VALUES (?, ?)");
            $stmt->bind_param("si", $staff_name, $is_active);
            if ($stmt->execute()) {
                redirect_with_msg('physicians.php', 'Staff member added successfully.');
            } else {
                $msg = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            set_flash('Staff name is required.', 'error');
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
/* Modern Dashboard Layout Styling */
:root {
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --bg-card: #ffffff;
    --border-subtle: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #64748b;
    --success: #10b981;
    --danger: #ef4444;
}

.dashboard-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0.5rem;
}

/* Header Section */
.header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.header-bar h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

/* KPI Summary Cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1.25rem;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}

.kpi-card .kpi-title {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 0.03em;
}

.kpi-card .kpi-value {
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 0.25rem;
}

/* Card Sections */
.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

.card-panel h3 {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 0;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Modern Tables */
.modern-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.modern-table th {
    background: #f8fafc;
    padding: 0.75rem;
    text-align: left;
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 1px solid var(--border-subtle);
}

.modern-table td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
    vertical-align: middle;
}

.add-new-row td {
    background: #f8fafc;
    border-top: 2px solid var(--border-subtle);
}

/* Inputs & Forms */
.modern-input {
    width: 100%;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-subtle);
    border-radius: 6px;
    font-size: 0.875rem;
}

.checkbox-custom {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--primary);
}

/* Status Badges */
.status-pill {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-pill.active { background: #d1fae5; color: #065f46; }
.status-pill.inactive { background: #fee2e2; color: #991b1b; }

@media print {
    .header-actions, .add-new-row, .btn-save { display: none !important; }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <header class="header-bar">
        <h2>🩺 Physicians, Rates &amp; System Settings</h2>
        <div class="header-actions">
            <a href="?export=physicians_csv" class="btn btn-blue btn-sm">📥 Physicians CSV</a>
            <a href="?export=meds_types_csv" class="btn btn-sm" style="background:#e2e8f0; color:#334155;">📥 Meds Types CSV</a>
            <a href="?export=staff_csv" class="btn btn-sm btn-outline">📥 Staff CSV</a>
        </div>
    </header>

    <!-- KPI Summary Grid -->
    <section class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-title">Active Physicians</div>
            <div class="kpi-value" style="color: var(--primary);"><?= $active_physicians ?> / <?= $total_physicians ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Medication Types</div>
            <div class="kpi-value"><?= $total_meds_types ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Registered Staff</div>
            <div class="kpi-value"><?= $total_staff ?></div>
        </div>
    </section>

    <!-- SECTION 1: PHYSICIANS TABLE -->
    <section class="card-panel">
        <h3>👨‍⚕️ Physicians &amp; Consultation Rates</h3>
        <div style="overflow-x: auto;">
            <modern-table-container>
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Physician Name</th>
                            <th style="width: 25%;">Consultation Rate (₱)</th>
                            <th style="width: 15%; text-align: center;">Active Status</th>
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
                                    <input type="number" step="0.01" min="0" name="consultation_rate" class="modern-input" value="<?= h($p['consultation_rate']) ?>" form="update-physician-<?= (int)$p['physician_id'] ?>" style="max-width: 150px;">
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="is_active" value="1" class="checkbox-custom" form="update-physician-<?= (int)$p['physician_id'] ?>" <?= $p['is_active'] ? 'checked' : '' ?>>
                                </td>
                                <td style="text-align: right;">
                                    <button type="submit" class="btn btn-primary btn-sm btn-save" form="update-physician-<?= (int)$p['physician_id'] ?>">Save Changes</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Add New Physician Row -->
                        <tr class="add-new-row">
                            <td>
                                <input type="text" name="physician_name" class="modern-input" placeholder="+ Add new physician name" form="add-physician" required>
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="consultation_rate" class="modern-input" value="0.00" form="add-physician" style="max-width: 150px;">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="is_active" value="1" class="checkbox-custom" form="add-physician" checked>
                            </td>
                            <td style="text-align: right;">
                                <button type="submit" class="btn btn-accent btn-sm" form="add-physician">➕ Add Physician</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </modern-table-container>
        </div>
    </section>

    <!-- SECTION 2: MEDS TYPES TABLE -->
    <section class="card-panel">
        <h3>💊 Medication Types</h3>
        <div style="overflow-x: auto;">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">Meds Type Name</th>
                        <th style="width: 30%;">Counts as Consultation</th>
                        <th style="width: 20%; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($meds_types_list as $m): ?>
                        <tr>
                            <td><strong><?= h($m['meds_type_name']) ?></strong></td>
                            <td>
                                <?= $m['is_consultation'] 
                                    ? '<span class="status-pill active">YES</span>' 
                                    : '<span class="status-pill inactive">NO</span>' ?>
                            </td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>

<!-- Add New Meds Type Row -->
                    <tr class="add-new-row">
                        <td>
                            <input type="text" name="meds_type_name" class="modern-input" placeholder="+ Add new meds type" form="add-meds-type" required>
                        </td>
                        <td>
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size: 0.85rem;">
                                <input type="checkbox" name="is_consultation" value="1" class="checkbox-custom" form="add-meds-type" checked> 
                                Counts as consultation
                            </label>
                        </td>
                        <td style="text-align: right;">
                            <button type="submit" class="btn btn-accent btn-sm" form="add-meds-type">➕ Add Meds Type</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- SECTION 3: STAFF TABLE -->
    <section class="card-panel">
        <h3>👥 Staff Members (for Transmitted By)</h3>
        <div style="overflow-x: auto;">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">Staff Name</th>
                        <th style="width: 30%;">Active Status</th>
                        <th style="width: 20%; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_list as $s): ?>
                        <tr>
                            <td><strong><?= h($s['staff_name']) ?></strong></td>
                            <td>
                                <?= $s['is_active'] 
                                    ? '<span class="status-pill active">Active</span>' 
                                    : '<span class="status-pill inactive">Inactive</span>' ?>
                            </td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>

<!-- Add New Staff Row -->
                    <tr class="add-new-row">
                        <td>
                            <input type="text" name="staff_name" class="modern-input" placeholder="+ Add new staff name" form="add-staff" required>
                        </td>
                        <td>
                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-size: 0.85rem;">
                                <input type="checkbox" name="is_active" value="1" class="checkbox-custom" form="add-staff" checked> 
                                Active Member
                            </label>
                        </td>
                        <td style="text-align: right;">
                            <button type="submit" class="btn btn-accent btn-sm" form="add-staff">➕ Add Staff</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
// Hidden per-row forms (OUTSIDE the tables — required so browsers don't drop them).
// Table inputs reference these via the form="..." attribute.
?>
<form id="add-physician" method="post" action="physicians.php" style="display:none;">
    <input type="hidden" name="action" value="add_physician">
</form>
<form id="add-meds-type" method="post" action="physicians.php" style="display:none;">
    <input type="hidden" name="action" value="add_meds_type">
</form>
<form id="add-staff" method="post" action="physicians.php" style="display:none;">
    <input type="hidden" name="action" value="add_staff">
</form>
<?php foreach ($physicians_list as $p): ?>
    <form id="update-physician-<?= (int)$p['physician_id'] ?>" method="post" action="physicians.php" style="display:none;">
        <input type="hidden" name="action" value="update_physician">
        <input type="hidden" name="physician_id" value="<?= (int)$p['physician_id'] ?>">
    </form>
<?php endforeach; ?>

<?php include 'includes/footer.php'; ?>
