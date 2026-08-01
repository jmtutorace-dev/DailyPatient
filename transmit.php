<?php
/**
 * YAKAP GAMOT SYSTEM - Transmit Tracker (transmit.php)
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
        $stmt->bind_param("iiiss", $transmit_year, $transmit_month, $pcsf, $sap, $transmitted_by, $transmitted_at);
        
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

$logs_by_month    = []; 
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
/* Modern Dashboard Layout Styling */
:root {
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --bg-card: #ffffff;
    --border-subtle: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #64748b;
    --success: #10b981;
    --warning: #f59e0b;
}

.dashboard-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0.5rem;
}

/* Page Header Controls */
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
}

/* Control Bar (Year Filter & Batch Buttons) */
.control-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.year-selector {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.year-selector label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.year-selector select {
    padding: 0.4rem 0.75rem;
    border: 1px solid var(--border-subtle);
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-primary);
    background-color: #fff;
    cursor: pointer;
}

.batch-actions {
    display: flex;
    gap: 0.5rem;
}

/* KPI Cards */
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

/* Data Table */
.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

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

.modern-table tfoot td {
    background: #f8fafc;
    font-weight: 700;
    border-top: 2px solid var(--border-subtle);
}

/* Status Badges */
.status-pill {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-pill.complete { background: #d1fae5; color: #065f46; }
.status-pill.partial { background: #fef3c7; color: #92400e; }
.status-pill.pending { background: #f1f5f9; color: #64748b; }

.modern-select {
    width: 100%;
    padding: 0.4rem 0.5rem;
    border: 1px solid var(--border-subtle);
    border-radius: 6px;
    font-size: 0.85rem;
}

.checkbox-custom {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--primary);
}

@media print {
    .header-actions, .control-card, .btn-save { display: none !important; }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <header class="header-bar">
        <h2>📡 Transmit Tracker (<?= $selected_year ?>)</h2>
        <div class="header-actions">
            <a href="?year=<?= $selected_year ?>&export=csv" class="btn btn-blue btn-sm">📥 Export CSV</a>
        </div>
    </header>

    <!-- Controls (Year Selector & Batch Updates) -->
    <section class="control-card">
        <form class="year-selector" method="get" action="transmit.php">
            <label for="year">Select Tracker Year:</label>
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
                <button type="submit" class="btn btn-primary btn-sm">✅ Mark All PCSF</button>
            </form>
            <form method="post" action="transmit.php" onsubmit="return confirm('Mark ALL months as SAP transmitted for <?= $selected_year ?>?');">
                <input type="hidden" name="action" value="mark_all_sap">
                <input type="hidden" name="year" value="<?= $selected_year ?>">
                <button type="submit" class="btn btn-blue btn-sm">✅ Mark All SAP</button>
            </form>
        </div>
    </section>

    <!-- KPI Summary Dashboard -->
    <section class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-title">Fully Completed Months</div>
            <div class="kpi-value" style="color: var(--success);"><?= $total_transmitted ?> / 12</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">PCSF Transmitted</div>
            <div class="kpi-value" style="color: var(--primary);"><?= $total_pcsf ?> / 12</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">SAP Transmitted</div>
            <div class="kpi-value" style="color: #0284c7;"><?= $total_sap ?> / 12</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Pending Completion</div>
            <div class="kpi-value" style="color: var(--warning);"><?= 12 - $total_transmitted ?> Months</div>
        </div>
    </section>

    <!-- Main Data Table -->
    <section class="card-panel">
        <div style="overflow-x: auto;">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">Month</th>
                        <th style="width: 10%; text-align: center;">PCSF</th>
                        <th style="width: 10%; text-align: center;">SAP</th>
                        <th style="width: 15%; text-align: center;">Status</th>
                        <th style="width: 20%;">Transmitted By</th>
                        <th style="width: 20%;">Transmitted At</th>
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
                            <form method="post" action="transmit.php">
                                <input type="hidden" name="action" value="save_transmit">
                                <input type="hidden" name="transmit_year" value="<?= $selected_year ?>">
                                <input type="hidden" name="transmit_month" value="<?= $m ?>">

                                <td><strong><?= $month_names[$m] ?></strong></td>
                                
                                <td style="text-align: center;">
                                    <input type="checkbox" name="pcsf" value="1" class="checkbox-custom" <?= $pcsf_checked ? 'checked' : '' ?>>
                                </td>
                                
                                <td style="text-align: center;">
                                    <input type="checkbox" name="sap" value="1" class="checkbox-custom" <?= $sap_checked ? 'checked' : '' ?>>
                                </td>

                                <td style="text-align: center;">
                                    <span class="status-pill <?= $status_class ?>"><?= $status_label ?></span>
                                </td>

                                <td>
                                    <select name="transmitted_by" class="modern-select">
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
                                    <button type="submit" class="btn btn-primary btn-sm btn-save">Save</button>
                                </td>
                            </form>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>YEAR TOTALS</td>
                        <td style="text-align: center; color: var(--primary);"><?= $total_pcsf ?> / 12</td>
                        <td style="text-align: center; color: #0284c7;"><?= $total_sap ?> / 12</td>
                        <td style="text-align: center; color: var(--success);"><?= $total_transmitted ?> Complete</td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>