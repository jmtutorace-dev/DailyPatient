<?php
/**
 * YAKAP GAMOT SYSTEM - Transmit Tracker (transmit.php)
 * 
 * Redesigned for a minimal, professional healthcare aesthetic while preserving 
 * all backend logic, database operations, form handling, and batch actions.
 */

require_once 'config.php';
require_once 'includes/auth.php';

// --- 1. YEAR & DATE CALCULATIONS ---
$current_year  = (int)date('Y');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : $current_year;
$min_year      = $current_year - 2;
$max_year      = $current_year + 2;

if ($selected_year < $min_year) $selected_year = $min_year;
if ($selected_year > $max_year) $selected_year = $max_year;

// --- 2. CSV EXPORT HANDLER ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exp_stmt = $conn->prepare("
        SELECT 
            CASE tl.transmit_month 
                WHEN 1 THEN 'January' WHEN 2 THEN 'February' WHEN 3 THEN 'March' 
                WHEN 4 THEN 'April' WHEN 5 THEN 'May' WHEN 6 THEN 'June' 
                WHEN 7 THEN 'July' WHEN 8 THEN 'August' WHEN 9 THEN 'September' 
                WHEN 10 THEN 'October' WHEN 11 THEN 'November' WHEN 12 THEN 'December' 
            END AS month, 
            tl.pcsf, 
            tl.sap, 
            tl.transmitted_by, 
            tl.transmitted_at 
        FROM transmit_log tl 
        WHERE tl.transmit_year = ? 
        ORDER BY tl.transmit_month ASC
    ");
    $exp_stmt->bind_param("i", $selected_year);
    $exp_stmt->execute();
    $exp_result = $exp_stmt->get_result();
    export_csv('transmit_tracker_' . $selected_year . '.csv', ['Month','PCSF','SAP','Transmitted By','Transmitted At'], $exp_result);
    $exp_stmt->close();
    exit;
}

// --- 3. POST ACTIONS HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_transmit') {
        $transmit_year  = (int)($_POST['transmit_year'] ?? $selected_year);
        $transmit_month = (int)($_POST['transmit_month'] ?? 1);
        $pcsf           = isset($_POST['pcsf']) ? 1 : 0;
        $sap            = isset($_POST['sap']) ? 1 : 0;
        $transmitted_by = trim($_POST['transmitted_by'] ?? '');
        $transmitted_at = ($pcsf || $sap) ? date('Y-m-d H:i:s') : null;

        $stmt = $conn->prepare("
            INSERT INTO transmit_log (transmit_year, transmit_month, pcsf, sap, transmitted_by, transmitted_at) 
            VALUES (?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                pcsf = VALUES(pcsf), 
                sap = VALUES(sap), 
                transmitted_by = VALUES(transmitted_by), 
                transmitted_at = VALUES(transmitted_at)
        ");
        $stmt->bind_param("iiiiss", $transmit_year, $transmit_month, $pcsf, $sap, $transmitted_by, $transmitted_at);
        
        if ($stmt->execute()) {
            redirect_with_msg('transmit.php?year=' . $transmit_year, 'Transmit record updated successfully.');
        } else {
            $msg = 'Error updating record: ' . $stmt->error;
        }
        $stmt->close();
        
    } elseif ($action === 'mark_all_pcsf') {
        $year = (int)($_POST['year'] ?? $selected_year);
        $stmt = $conn->prepare("UPDATE transmit_log SET pcsf = 1, transmitted_at = IF(transmitted_at IS NULL, NOW(), transmitted_at) WHERE transmit_year = ?");
        $stmt->bind_param("i", $year); 
        $stmt->execute();
        $stmt->close();
        redirect_with_msg('transmit.php?year=' . $year, 'All months marked as PCSF transmitted.');
        
    } elseif ($action === 'mark_all_sap') {
        $year = (int)($_POST['year'] ?? $selected_year);
        $stmt = $conn->prepare("UPDATE transmit_log SET sap = 1, transmitted_at = IF(transmitted_at IS NULL, NOW(), transmitted_at) WHERE transmit_year = ?");
        $stmt->bind_param("i", $year); 
        $stmt->execute();
        $stmt->close();
        redirect_with_msg('transmit.php?year=' . $year, 'All months marked as SAP transmitted.');
    }
}

// --- 4. SEED MONTHS IF NOT EXISTS ---
$check_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM transmit_log WHERE transmit_year = ?");
$check_stmt->bind_param("i", $selected_year); 
$check_stmt->execute();
$check_row = $check_stmt->get_result()->fetch_assoc(); 
$check_stmt->close();

if ((int)$check_row['cnt'] < 12) {
    $insert_stmt = $conn->prepare("INSERT IGNORE INTO transmit_log (transmit_year, transmit_month, pcsf, sap, transmitted_by, transmitted_at) VALUES (?, ?, 0, 0, NULL, NULL)");
    for ($m = 1; $m <= 12; $m++) { 
        $insert_stmt->bind_param("ii", $selected_year, $m); 
        $insert_stmt->execute(); 
    }
    $insert_stmt->close();
}

// --- 5. FETCH DATA FOR VIEW ---
$logs_stmt = $conn->prepare("SELECT * FROM transmit_log WHERE transmit_year = ? ORDER BY transmit_month ASC");
$logs_stmt->bind_param("i", $selected_year); 
$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();

$logs_by_month     = []; 
$total_pcsf       = 0; 
$total_sap        = 0; 
$total_transmitted = 0;

while ($log = $logs_result->fetch_assoc()) {
    $m = (int)$log['transmit_month'];
    $logs_by_month[$m] = $log;
    if ((int)$log['pcsf']) $total_pcsf++;
    if ((int)$log['sap'])  $total_sap++;
    if ((int)$log['pcsf'] && (int)$log['sap']) $total_transmitted++;
}
$logs_stmt->close();

// Fetch Active Staff List
$staff_result = $conn->query("SELECT staff_name FROM staff WHERE is_active = 1 ORDER BY staff_name");
$staff_members = $staff_result ? $staff_result->fetch_all(MYSQLI_ASSOC) : [];

$month_names = [
    1=>'January', 2=>'February', 3=>'March', 4=>'April', 
    5=>'May', 6=>'June', 7=>'July', 8=>'August', 
    9=>'September', 10=>'October', 11=>'November', 12=>'December'
];

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

/* Header Bar */
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

/* Control Card & Year Selector */
.control-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.year-selector {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.year-selector label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.year-selector select {
    padding: 0.5rem 0.875rem;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-primary);
    background-color: #ffffff;
    cursor: pointer;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.year-selector select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.1);
}

.batch-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
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

.kpi-card.accent-success::before { background: var(--success); }
.kpi-card.accent-primary::before { background: var(--primary); }
.kpi-card.accent-info::before { background: var(--info); }
.kpi-card.accent-warning::before { background: var(--warning); }

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

/* Card Panels */
.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.card-panel h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-top: 0;
    margin-bottom: 1rem;
}

/* Modern Data Table Styling */
.table-wrapper {
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

.modern-table tfoot td {
    background: #f8fafc;
    font-weight: 600;
    border-top: 1px solid var(--border-subtle);
    color: var(--text-primary);
}

/* Status Badges */
.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.status-pill.complete { background: #d1fae5; color: #065f46; }
.status-pill.partial { background: #fef3c7; color: #92400e; }
.status-pill.pending { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

.modern-select {
    width: 100%;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    color: var(--text-primary);
    background-color: #ffffff;
    outline: none;
    transition: border-color 0.15s ease;
}

.modern-select:focus {
    border-color: var(--primary);
}

.checkbox-custom {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--primary);
}

@media print {
    .header-bar, .control-card, .btn-save { display: none !important; }
    .card-panel { border: none; box-shadow: none; padding: 0; }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <header class="header-bar">
        <div class="header-title-wrapper">
            <h2>Transmit Tracker</h2>
            <p>Monitor and manage annual PCSF and SAP report transmittal schedules for <?= $selected_year ?>.</p>
        </div>
        <div class="header-actions">
            <a href="?year=<?= $selected_year ?>&export=csv" class="btn-custom"><span>📥</span> Export CSV</a>
        </div>
    </header>

    <!-- Controls (Year Selector & Batch Updates) -->
    <section class="control-card">
        <form class="year-selector" method="get" action="transmit.php">
            <label for="year">Tracker Year</label>
            <select name="year" id="year" onchange="this.form.submit()">
                <?php for ($y = $min_year; $y <= $max_year; $y++): ?>
                    <option value="<?= $y ?>" <?= $y === $selected_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>

        <div class="batch-actions">
            <form method="post" action="transmit.php" onsubmit="return confirm('Mark ALL months as PCSF transmitted for <?= $selected_year ?>?');">
                <input type="hidden" name="action" value="mark_all_pcsf">
                <input type="hidden" name="year" value="<?= $selected_year ?>">
                <button type="submit" class="btn-custom btn-primary-custom">✅ Mark All PCSF</button>
            </form>
            <form method="post" action="transmit.php" onsubmit="return confirm('Mark ALL months as SAP transmitted for <?= $selected_year ?>?');">
                <input type="hidden" name="action" value="mark_all_sap">
                <input type="hidden" name="year" value="<?= $selected_year ?>">
                <button type="submit" class="btn-custom">✅ Mark All SAP</button>
            </form>
        </div>
    </section>

    <!-- KPI Summary Dashboard -->
    <section class="kpi-grid">
        <div class="kpi-card accent-success">
            <div class="kpi-title">Fully Completed Months</div>
            <div class="kpi-value"><?= $total_transmitted ?> <span style="font-size: 1rem; color: var(--text-secondary); font-weight: 500;">/ 12</span></div>
        </div>
        <div class="kpi-card accent-primary">
            <div class="kpi-title">PCSF Transmitted</div>
            <div class="kpi-value"><?= $total_pcsf ?> <span style="font-size: 1rem; color: var(--text-secondary); font-weight: 500;">/ 12</span></div>
        </div>
        <div class="kpi-card accent-info">
            <div class="kpi-title">SAP Transmitted</div>
            <div class="kpi-value"><?= $total_sap ?> <span style="font-size: 1rem; color: var(--text-secondary); font-weight: 500;">/ 12</span></div>
        </div>
        <div class="kpi-card accent-warning">
            <div class="kpi-title">Pending Completion</div>
            <div class="kpi-value"><?= 12 - $total_transmitted ?> <span style="font-size: 1rem; color: var(--text-secondary); font-weight: 500;">Months</span></div>
        </div>
    </section>

    <!-- Main Data Table Panel -->
    <section class="card-panel">
        <h3>Transmittal Records for <?= $selected_year ?></h3>
        <div class="table-wrapper">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">Month</th>
                        <th style="width: 10%; text-align: center;">PCSF</th>
                        <th style="width: 10%; text-align: center;">SAP</th>
                        <th style="width: 15%; text-align: center;">Status</th>
                        <th style="width: 22%;">Transmitted By</th>
                        <th style="width: 18%;">Transmitted At</th>
                        <th style="width: 10%; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($m = 1; $m <= 12; $m++):
                        $log_data           = $logs_by_month[$m] ?? null;
                        $pcsf_checked       = ($log_data && (int)$log_data['pcsf'] === 1);
                        $sap_checked        = ($log_data && (int)$log_data['sap'] === 1);
                        $transmitted_by_val = $log_data['transmitted_by'] ?? '';
                        $transmitted_at_val = $log_data['transmitted_at'] ?? '';
                        $row_form_id        = 'transmit-form-' . $m;

                        // Determine row status
                        if ($pcsf_checked && $sap_checked) {
                            $status_class = 'complete';
                            $status_label = 'Complete';
                        } elseif ($pcsf_checked || $sap_checked) {
                            $status_class = 'partial';
                            $status_label = 'Partial';
                        } else {
                            $status_class = 'pending';
                            $status_label = 'Pending';
                        }
                    ?>
                        <tr>
                            <td><strong><?= $month_names[$m] ?></strong></td>

                            <td style="text-align: center;">
                                <input type="checkbox" name="pcsf" value="1" class="checkbox-custom" form="<?= $row_form_id ?>" <?= $pcsf_checked ? 'checked' : '' ?>>
                            </td>

                            <td style="text-align: center;">
                                <input type="checkbox" name="sap" value="1" class="checkbox-custom" form="<?= $row_form_id ?>" <?= $sap_checked ? 'checked' : '' ?>>
                            </td>

                            <td style="text-align: center;">
                                <span class="status-pill <?= $status_class ?>"><?= $status_label ?></span>
                            </td>

                            <td>
                                <select name="transmitted_by" class="modern-select" form="<?= $row_form_id ?>">
                                    <option value="">-- Select Staff --</option>
                                    <?php foreach ($staff_members as $s): ?>
                                        <option value="<?= h($s['staff_name']) ?>" <?= ($transmitted_by_val === $s['staff_name']) ? 'selected' : '' ?>>
                                            <?= h($s['staff_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td style="color: var(--text-secondary); font-size: 0.8rem;">
                                <?= $transmitted_at_val ? h(date('M j, Y g:i A', strtotime($transmitted_at_val))) : '—' ?>
                            </td>

                            <td style="text-align: right;">
                                <button type="submit" class="btn-custom btn-primary-custom btn-save" style="padding: 0.3rem 0.7rem; font-size: 0.8rem;" form="<?= $row_form_id ?>">Save</button>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>YEAR TOTALS</td>
                        <td style="text-align: center; color: var(--primary);"><?= $total_pcsf ?> / 12</td>
                        <td style="text-align: center; color: var(--info);"><?= $total_sap ?> / 12</td>
                        <td style="text-align: center; color: var(--success);"><?= $total_transmitted ?> Complete</td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

<?php
// Hidden per-row forms (OUTSIDE the table — required so browsers don't drop them).
// Each month's inputs reference these via the form="..." attribute.
for ($m = 1; $m <= 12; $m++): ?>
    <form id="transmit-form-<?= $m ?>" method="post" action="transmit.php" style="display:none;">
        <input type="hidden" name="action" value="save_transmit">
        <input type="hidden" name="transmit_year" value="<?= $selected_year ?>">
        <input type="hidden" name="transmit_month" value="<?= $m ?>">
    </form>
<?php endfor; ?>

<?php include 'includes/footer.php'; ?>