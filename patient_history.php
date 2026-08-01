<?php
/**
 * YAKAP GAMOT SYSTEM - Patient History (patient_history.php)
 */

require_once 'config.php';
require_once 'includes/auth.php';

// Helper function to handle JSON, arrays, or text for checkbox items safely
function format_checkbox_items($data) {
    if (empty($data)) return [];
    
    if (is_string($data) && ($decoded = json_decode($data, true)) !== null) {
        $data = $decoded;
    }
    
    if (is_array($data)) {
        return array_unique(array_filter(array_map('trim', $data)));
    }
    
    if (is_string($data)) {
        return array_unique(array_filter(array_map('trim', explode(',', $data))));
    }
    
    return [trim((string)$data)];
}

// --- 1. CSV EXPORT HANDLER ---
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($_GET['patient'])) {
    $patient_name = trim($_GET['patient']);
    $stmt = $conn->prepare("
        SELECT 
            dr.record_date, 
            dr.patient_name, 
            COALESCE(p.physician_name, '') AS physician, 
            COALESCE(mt.meds_type_name, '') AS meds_type, 
            dr.has_meds, 
            dr.has_labs, 
            dr.has_gamot_meds, 
            COALESCE(dr.meds, dr.medications, '') AS meds, 
            COALESCE(dr.labs, dr.laboratory, '') AS labs, 
            COALESCE(dr.gamot, dr.gamot_meds, '') AS gamot, 
            dr.remarks,
            dr.created_at 
        FROM daily_records dr 
        LEFT JOIN physicians p ON dr.physician_id = p.physician_id 
        LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id 
        WHERE dr.patient_name LIKE ? 
        ORDER BY dr.record_date DESC
    ");
    $search = '%' . $patient_name . '%';
    $stmt->bind_param("s", $search);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $clean_filename = 'patient_history_' . preg_replace('/[^a-zA-Z0-9]/', '_', $patient_name) . '.csv';
    export_csv($clean_filename, ['Record Date', 'Patient Name', 'Physician', 'Meds Type', 'Has Meds', 'Has Labs', 'Has Gamot Meds', 'Meds', 'Labs', 'Gamot', 'Remarks', 'Created At'], $result);
    $stmt->close();
    exit;
}

// --- 2. GET SEARCH PARAMETER ---
$patient_name = trim($_GET['patient'] ?? $_GET['name'] ?? $_POST['patient_name'] ?? '');

include 'includes/header.php';
?>

<style>
/* Clean, Modern Dashboard Layout Styling */
:root {
    --primary: #2563eb;
    --primary-light: #eff6ff;
    --primary-hover: #1d4ed8;
    --bg-main: #f8fafc;
    --bg-card: #ffffff;
    --border-subtle: #e2e8f0;
    --text-main: #1e293b;
    --text-muted: #64748b;
    --success-bg: #ecfdf5;
    --success-text: #047857;
    --warning-bg: #fffbeb;
    --warning-text: #b45309;
    --danger-bg: #fef2f2;
    --danger-text: #b91c1c;
    --radius: 12px;
}

body {
    background-color: var(--bg-main);
}

.dashboard-container {
    max-width: 1320px;
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
}

.header-bar h2 {
    font-size: 1.375rem;
    font-weight: 700;
    color: var(--text-main);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Filter Card */
.control-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.filter-form {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filter-form label {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-muted);
}

.modern-input {
    padding: 0.5rem 0.875rem;
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    font-size: 0.875rem;
    min-width: 300px;
    color: var(--text-main);
    background: #ffffff;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.modern-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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
    border-radius: var(--radius);
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.kpi-card .kpi-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: 0.05em;
}

.kpi-card .kpi-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-main);
    margin-top: 0.35rem;
    line-height: 1;
}

/* Card Panels */
.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.card-panel h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-main);
    margin-top: 0;
    margin-bottom: 1.25rem;
}

/* Overview Summary Box Grid */
.overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
}

.overview-box {
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    padding: 1rem;
}

.overview-box strong {
    display: block;
    font-size: 0.8125rem;
    color: var(--text-main);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.overview-list {
    font-size: 0.875rem;
    color: var(--text-muted);
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
}

/* Modern Data Table Styling */
.table-wrapper {
    overflow-x: auto;
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
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
    padding: 0.875rem 1rem;
    color: var(--text-muted);
    font-weight: 600;
    border-bottom: 1px solid var(--border-subtle);
}

.modern-table td {
    padding: 1rem;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-main);
    vertical-align: middle;
}

.modern-table tbody tr:last-child td {
    border-bottom: none;
}

.modern-table tbody tr:hover {
    background-color: #fafbfc;
}

/* Badges & Tags */
.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.625rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.025em;
}

.status-pill.yes { background: var(--success-bg); color: var(--success-text); }
.status-pill.no { background: #f1f5f9; color: #94a3b8; }

.meds-type-tag {
    display: inline-block;
    background: var(--primary-light);
    color: var(--primary);
    font-weight: 600;
    padding: 0.25rem 0.625rem;
    border-radius: 6px;
    font-size: 0.8125rem;
}

.item-pills-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin-top: 0.5rem;
    align-items: flex-start;
}

.item-sub-pill {
    font-size: 0.75rem;
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    color: var(--text-muted);
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    white-space: normal;
    text-align: left;
}

.empty-state {
    padding: 3rem 1.5rem;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.9375rem;
}

/* Timeline Badges */
.timeline-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.visit-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.4rem 0.75rem;
    background-color: #ffffff;
    border: 1px solid var(--border-subtle);
    color: var(--primary);
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.8125rem;
    text-decoration: none;
    transition: all 0.15s ease;
}

.visit-badge:hover {
    background-color: var(--primary-light);
    border-color: var(--primary);
}

@media print {
    .header-bar, .control-card, .visit-calendar-card { display: none !important; }
    .card-panel { border: none; box-shadow: none; padding: 0; }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <header class="header-bar">
        <h2>📋 Patient History Tracker</h2>
        <?php if (!empty($_GET['name'])): ?>
            <a href="index.php" class="btn btn-sm btn-outline">⬅️ Back to Index</a>
        <?php endif; ?>
    </header>

    <!-- Search Card -->
    <section class="control-card">
        <form class="filter-form" method="get" action="patient_history.php">
            <label for="patient">Patient Name:</label>
            <input type="text" name="patient" id="patient" class="modern-input" value="<?= h($patient_name) ?>" placeholder="Type patient name to search..." required list="patient-list-history" autocomplete="off">
            <button type="submit" class="btn btn-primary btn-sm">🔍 Search</button>
            <?php if ($patient_name): ?>
                <a href="patient_history.php" class="btn btn-sm btn-outline">Clear</a>
            <?php endif; ?>
        </form>
        <datalist id="patient-list-history">
            <?php 
                $all_patients = $conn->query("SELECT DISTINCT patient_name FROM daily_records ORDER BY patient_name"); 
                while ($ap = $all_patients->fetch_assoc()): 
            ?>
                <option value="<?= h($ap['patient_name']) ?>">
            <?php endwhile; ?>
        </datalist>
    </section>

    <?php if ($patient_name): 
        $stmt = $conn->prepare("
            SELECT 
                dr.*, 
                p.physician_name, 
                mt.meds_type_name, 
                mt.is_consultation 
            FROM daily_records dr 
            LEFT JOIN physicians p ON dr.physician_id = p.physician_id 
            LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id 
            WHERE dr.patient_name LIKE ? 
            ORDER BY dr.record_date DESC, dr.created_at DESC
        ");
        $search = '%' . $patient_name . '%';
        $stmt->bind_param("s", $search);
        $stmt->execute();
        $records = $stmt->get_result();

        $total_visits     = $records->num_rows;
        $has_meds_count   = 0;
        $has_labs_count   = 0;
        $has_gamot_count  = 0;
        $all_meds_list    = [];
        $all_labs_list    = [];
        $all_gamot_list   = [];
        $dates_visited    = [];

        while ($r = $records->fetch_assoc()) {
            $dates_visited[$r['record_date']] = true;
            
            $raw_meds = $r['meds'] ?? $r['medications'] ?? $r['record_medications'] ?? '';
            $raw_labs = $r['labs'] ?? $r['laboratory'] ?? $r['record_labs'] ?? '';
            $raw_gamot = $r['gamot'] ?? $r['gamot_meds'] ?? $r['record_gamot'] ?? '';

            $row_meds = format_checkbox_items($raw_meds);
            $row_labs = format_checkbox_items($raw_labs);
            $row_gamot = format_checkbox_items($raw_gamot);

            if (!empty($r['has_meds']) || !empty($row_meds)) {
                $has_meds_count++;
                foreach ($row_meds as $m) $all_meds_list[] = $m;
            }
            if (!empty($r['has_labs']) || !empty($row_labs)) {
                $has_labs_count++;
                foreach ($row_labs as $l) $all_labs_list[] = $l;
            }
            if (!empty($r['has_gamot_meds']) || !empty($row_gamot)) {
                $has_gamot_count++;
                foreach ($row_gamot as $g) $all_gamot_list[] = $g;
            }
        }
        $distinct_dates = count($dates_visited);
        $records->data_seek(0);
    ?>

        <!-- KPI Metrics Grid -->
        <section class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-title">Total Visits</div>
                <div class="kpi-value" style="color: var(--primary);"><?= $total_visits ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Unique Visit Dates</div>
                <div class="kpi-value" style="color: #0d9488;"><?= $distinct_dates ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Visits with Meds</div>
                <div class="kpi-value" style="color: var(--warning-text);"><?= $has_meds_count ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Visits with Gamot Meds</div>
                <div class="kpi-value" style="color: var(--danger-text);"><?= $has_gamot_count ?></div>
            </div>
        </section>

        <!-- Cumulative Summary Panel -->
        <section class="card-panel">
            <h3>📦 Comprehensive Overview of Availed Items</h3>
            <div class="overview-grid">
                <div class="overview-box">
                    <strong>Total Meds Availed</strong>
                    <p class="overview-list"><?php 
                        $unique_meds = array_unique($all_meds_list);
                        echo !empty($unique_meds) ? '• ' . h(implode("\n• ", $unique_meds)) : 'None recorded';
                    ?></p>
                </div>
                <div class="overview-box">
                    <strong>Total Labs Availed</strong>
                    <p class="overview-list"><?php 
                        $unique_labs = array_unique($all_labs_list);
                        echo !empty($unique_labs) ? '• ' . h(implode("\n• ", $unique_labs)) : 'None recorded';
                    ?></p>
                </div>
                <div class="overview-box">
                    <strong>Total Gamot Meds Availed</strong>
                    <p class="overview-list"><?php 
                        $unique_gamot = array_unique($all_gamot_list);
                        echo !empty($unique_gamot) ? '• ' . h(implode("\n• ", $unique_gamot)) : 'None recorded';
                    ?></p>
                </div>
            </div>
        </section>

        <!-- Detailed Visit History Table Panel -->
        <section class="card-panel">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom: 1.25rem;">
                <h3 style="margin:0;">Visit History for <span style="color: var(--primary);"><?= h($patient_name) ?></span></h3>
                <a href="?patient=<?= h(urlencode($patient_name)) ?>&export=csv" class="btn btn-blue btn-sm">📥 Export CSV</a>
            </div>

            <?php if ($total_visits === 0): ?>
                <div class="empty-state">No medical visit records found matching "<strong><?= h($patient_name) ?></strong>".</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Record Date</th>
                                <th>Attending Physician</th>
                                <th>Meds Type</th>
                                <th style="text-align: center;">Meds Status & Input</th>
                                <th style="text-align: center;">Labs Status & Input</th>
                                <th style="text-align: center;">Gamot Status & Input</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($r = $records->fetch_assoc()): 
                                $raw_meds = $r['meds'] ?? $r['medications'] ?? $r['record_medications'] ?? '';
                                $raw_labs = $r['labs'] ?? $r['laboratory'] ?? $r['record_labs'] ?? '';
                                $raw_gamot = $r['gamot'] ?? $r['gamot_meds'] ?? $r['record_gamot'] ?? '';

                                $row_meds = format_checkbox_items($raw_meds);
                                $row_labs = format_checkbox_items($raw_labs);
                                $row_gamot = format_checkbox_items($raw_gamot);
                            ?>
                                <tr>
                                    <td>
                                        <a href="index.php?date=<?= h($r['record_date']) ?>" style="color: var(--primary); font-weight: 600; text-decoration: none;">
                                            📅 <?= h(date('M j, Y', strtotime($r['record_date']))) ?>
                                        </a>
                                    </td>
                                    <td><span style="font-weight: 600;"><?= h($r['physician_name'] ?? '—') ?></span></td>
                                    <td>
                                        <?= $r['meds_type_name'] 
                                            ? '<span class="meds-type-tag">' . h($r['meds_type_name']) . '</span>' 
                                            : '—' ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="status-pill <?= (!empty($r['has_meds']) || !empty($row_meds)) ? 'yes' : 'no' ?>">
                                            <?= (!empty($r['has_meds']) || !empty($row_meds)) ? 'YES' : 'NO' ?>
                                        </span>
                                        <?php if (!empty($row_meds)): ?>
                                            <div class="item-pills-list">
                                                <?php foreach ($row_meds as $m): ?>
                                                    <span class="item-sub-pill">• <?= h($m) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="status-pill <?= (!empty($r['has_labs']) || !empty($row_labs)) ? 'yes' : 'no' ?>">
                                            <?= (!empty($r['has_labs']) || !empty($row_labs)) ? 'YES' : 'NO' ?>
                                        </span>
                                        <?php if (!empty($row_labs)): ?>
                                            <div class="item-pills-list">
                                                <?php foreach ($row_labs as $l): ?>
                                                    <span class="item-sub-pill">• <?= h($l) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="status-pill <?= (!empty($r['has_gamot_meds']) || !empty($row_gamot)) ? 'yes' : 'no' ?>">
                                            <?= (!empty($r['has_gamot_meds']) || !empty($row_gamot)) ? 'YES' : 'NO' ?>
                                        </span>
                                        <?php if (!empty($row_gamot)): ?>
                                            <div class="item-pills-list">
                                                <?php foreach ($row_gamot as $g): ?>
                                                    <span class="item-sub-pill">• <?= h($g) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Rapid Visit Timeline Panel -->
        <section class="card-panel visit-calendar-card">
            <h3>🗓️ Rapid Visit Timeline</h3>
            <?php if ($distinct_dates === 0): ?>
                <div class="empty-state" style="padding: 1rem;">No registered visit dates available.</div>
            <?php else: ?>
                <div class="timeline-container">
                    <?php foreach (array_keys($dates_visited) as $dv): ?>
                        <a href="index.php?date=<?= h($dv) ?>" class="visit-badge">
                            📅 <?= h(date('M j, Y', strtotime($dv))) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php $stmt->close(); ?>

    <?php else: ?>
        <section class="card-panel">
            <div class="empty-state">
                👆 Enter a patient's name in the search bar above to generate their complete visit history, metrics, and records.
            </div>
        </section>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>