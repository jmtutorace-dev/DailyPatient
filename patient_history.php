<?php
/**
 * YAKAP GAMOT SYSTEM - Patient History (patient_history_full.php)
 *
 * Full standalone page (search + KPIs + visit history table + CSV export + Directory list).
 * This is the preserved version of the original patient_history.php page.
 * The sidebar "Patient History" navigation points to this file.
 */

require_once 'config.php';
require_once 'includes/auth.php';

// --- AJAX AUTOCOMPLETE ENDPOINT ---
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $term = trim($_GET['term'] ?? '');
    
    if (strlen($term) >= 1) {
        $words = explode(' ', $term);
        $sql = "SELECT DISTINCT patient_name FROM daily_records WHERE patient_name IS NOT NULL AND patient_name != ''";
        
        $params = [];
        $types = '';
        $conditions = [];
        
        foreach ($words as $word) {
            if ($word !== '') {
                $conditions[] = "patient_name LIKE ?";
                $params[] = '%' . $word . '%';
                $types .= 's';
            }
        }
        
        if (!empty($conditions)) {
            $sql .= " AND (" . implode(" AND ", $conditions) . ")";
        }
        
        $sql .= " ORDER BY patient_name ASC LIMIT 12";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        
        $results = [];
        while ($row = $res->fetch_assoc()) {
            $results[] = $row['patient_name'];
        }
        $stmt->close();
        echo json_encode($results);
    } else {
        echo json_encode([]);
    }
    exit;
}

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
        WHERE LOWER(TRIM(dr.patient_name)) LIKE LOWER(TRIM(?)) 
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

// Fetch all unique patient names encoded in the system for the directory view, sorted newest first (latest visit date descending)
$all_patients_query = "SELECT DISTINCT patient_name, MAX(record_date) as last_visit, COUNT(*) as visit_count FROM daily_records WHERE patient_name IS NOT NULL AND patient_name != '' GROUP BY patient_name ORDER BY last_visit DESC, patient_name ASC";
$all_patients_result = $conn->query($all_patients_query);

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

/* Filter Card & Autocomplete */
.control-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.filter-form {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filter-form label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.autocomplete-wrapper {
    position: relative;
    display: inline-block;
    flex: 1;
    min-width: 280px;
}

.modern-input {
    width: 100%;
    padding: 0.5rem 0.875rem;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    color: var(--text-primary);
    background: #ffffff;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.modern-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.1);
}

/* Autocomplete Dropdown Menu */
.autocomplete-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    max-height: 250px;
    overflow-y: auto;
    display: none;
}

.autocomplete-item {
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-primary);
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.1s ease;
}

.autocomplete-item:last-child {
    border-bottom: none;
}

.autocomplete-item:hover, .autocomplete-item.selected {
    background-color: var(--primary-light);
    color: var(--primary);
}

.autocomplete-item strong {
    font-weight: 700;
    color: var(--primary);
}

.autocomplete-message {
    padding: 0.75rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-secondary);
    text-align: center;
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
.kpi-card.accent-teal::before { background: #0d9488; }
.kpi-card.accent-warning::before { background: var(--warning); }
.kpi-card.accent-danger::before { background: var(--danger); }

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

/* Overview Summary Box Grid */
.overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}

.overview-box {
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    padding: 1rem;
}

.overview-box strong {
    display: block;
    font-size: 0.775rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.overview-list {
    font-size: 0.875rem;
    color: var(--text-primary);
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
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

/* Badges & Tags */
.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.status-pill.yes { background: #d1fae5; color: #065f46; }
.status-pill.no { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

.meds-type-tag {
    display: inline-block;
    background: var(--primary-light);
    color: var(--primary);
    font-weight: 600;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
}

.item-pills-list {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-top: 0.35rem;
    align-items: flex-start;
}

.item-sub-pill {
    font-size: 0.75rem;
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    color: var(--text-secondary);
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    white-space: normal;
    text-align: left;
}

.empty-state {
    padding: 2.5rem 1rem;
    text-align: center;
    color: var(--text-secondary);
    font-size: 0.875rem;
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
    padding: 0.35rem 0.65rem;
    background-color: #ffffff;
    border: 1px solid var(--border-subtle);
    color: var(--primary);
    border-radius: var(--radius-sm);
    font-weight: 500;
    font-size: 0.8rem;
    text-decoration: none;
    transition: all 0.15s ease;
}

.visit-badge:hover {
    background-color: var(--primary-light);
    border-color: var(--primary);
}

/* Patient Directory Grid */
.patient-directory-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 0.75rem;
    max-height: 320px;
    overflow-y: auto;
    padding: 0.25rem;
}

.patient-directory-item {
    display: flex;
    flex-direction: column;
    padding: 0.75rem;
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: var(--text-primary);
    transition: all 0.15s ease;
}

.patient-directory-item:hover {
    border-color: var(--primary);
    background: var(--primary-light);
}

.patient-directory-item .name {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
}

.patient-directory-item .meta {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.2rem;
    display: flex;
    justify-content: space-between;
}

@media print {
    .header-bar, .control-card, .visit-calendar-card, .directory-card { display: none !important; }
    .card-panel { border: none; box-shadow: none; padding: 0; }
}
</style>

<div class="dashboard-container">
    <!-- Header -->
    <header class="header-bar">
        <div class="header-title-wrapper">
            <h2>Patient History Tracker</h2>
            <p>Comprehensive clinical encounter lookup and longitudinal patient record auditing.</p>
        </div>
        <?php if (!empty($_GET['name']) || !empty($_GET['patient'])): ?>
            <a href="patient_history_full.php" class="btn-custom">⬅️ Back to Directory</a>
        <?php endif; ?>
    </header>

    <!-- Search Card with AJAX Autocomplete -->
    <section class="control-card">
        <form class="filter-form" method="get" action="patient_history_full.php" id="searchForm" autocomplete="off">
            <label for="patient">Patient Name</label>
            <div class="autocomplete-wrapper">
                <input type="text" name="patient" id="patient" class="modern-input" value="<?= h($patient_name) ?>" placeholder="Type patient name to search records..." required>
                <div id="autocompleteDropdown" class="autocomplete-dropdown"></div>
            </div>
            <button type="submit" class="btn-custom btn-primary-custom">Search Records</button>
            <?php if ($patient_name): ?>
                <a href="patient_history_full.php" class="btn-custom">Clear</a>
            <?php endif; ?>
        </form>
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
            WHERE LOWER(TRIM(dr.patient_name)) LIKE LOWER(TRIM(?)) 
            ORDER BY dr.record_date DESC, dr.created_at DESC
        ");
        $search = '%' . $patient_name . '%';
        $stmt->bind_param("s", $search);
        $stmt->execute();
        $records = $stmt->get_result();

        $total_visits    = $records->num_rows;
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
            <div class="kpi-card accent-primary">
                <div class="kpi-title">Total Visits</div>
                <div class="kpi-value"><?= $total_visits ?></div>
            </div>
            <div class="kpi-card accent-teal">
                <div class="kpi-title">Unique Visit Dates</div>
                <div class="kpi-value"><?= $distinct_dates ?></div>
            </div>
            <div class="kpi-card accent-warning">
                <div class="kpi-title">Visits with Meds</div>
                <div class="kpi-value"><?= $has_meds_count ?></div>
            </div>
            <div class="kpi-card accent-danger">
                <div class="kpi-title">Visits with Gamot Meds</div>
                <div class="kpi-value"><?= $has_gamot_count ?></div>
            </div>
        </section>

        <!-- Cumulative Summary Panel -->
        <section class="card-panel">
            <h3>Comprehensive Overview of Availed Items</h3>
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
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom: 1rem;">
                <h3 style="margin:0;">Visit History for <span style="color: var(--primary);"><?= h($patient_name) ?></span></h3>
                <a href="?patient=<?= h(urlencode($patient_name)) ?>&export=csv" class="btn-custom"><span>📥</span> Export CSV</a>
            </div>

            <?php if ($total_visits === 0): ?>
                <div class="empty-state">No medical visit records found matching "<strong><?= h($patient_name) ?></strong>". Try checking the directory below or refining your search.</div>
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
            <h3>Rapid Visit Timeline</h3>
            <?php if ($distinct_dates === 0): ?>
                <div class="empty-state" style="padding: 0.5rem;">No registered visit dates available.</div>
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
                Select a patient from the directory below or type a name above to generate their complete visit history, metrics, and records.
            </div>
        </section>
    <?php endif; ?>

    <!-- Patient Directory Section (Showing all patients encoded in the system sorted newest first) -->
    <section class="card-panel directory-card">
        <h3>Patient Directory (Latest Encounters First)</h3>
        <?php if ($all_patients_result && $all_patients_result->num_rows > 0): ?>
            <div class="patient-directory-grid">
                <?php while ($pat = $all_patients_result->fetch_assoc()): ?>
                    <a href="patient_history_full.php?patient=<?= h(urlencode($pat['patient_name'])) ?>" class="patient-directory-item">
                        <span class="name"><?= h($pat['patient_name']) ?></span>
                        <span class="meta">
                            <span>Visits: <strong><?= (int)$pat['visit_count'] ?></strong></span>
                            <span>Last: <?= h(date('M j, Y', strtotime($pat['last_visit']))) ?></span>
                        </span>
                    </a>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No patients currently encoded in the database records.</div>
        <?php endif; ?>
    </section>
</div>

<!-- AJAX Autocomplete & Keyboard Navigation Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('patient');
    const dropdown = document.getElementById('autocompleteDropdown');
    const form = document.getElementById('searchForm');
    
    let debounceTimer = null;
    let currentFocus = -1;

    function closeDropdown() {
        dropdown.style.display = 'none';
        dropdown.innerHTML = '';
        currentFocus = -1;
    }

    function addActive(items) {
        if (!items) return;
        removeActive(items);
        if (currentFocus >= items.length) currentFocus = 0;
        if (currentFocus < 0) currentFocus = items.length - 1;
        items[currentFocus].classList.add('selected');
        items[currentFocus].scrollIntoView({ block: 'nearest' });
    }

    function removeActive(items) {
        for (let item of items) {
            item.classList.remove('selected');
        }
    }

    input.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(debounceTimer);

        if (query.length < 1) {
            closeDropdown();
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch(`patient_history_full.php?ajax_search=1&term=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    dropdown.innerHTML = '';
                    currentFocus = -1;

                    if (data.length === 0) {
                        dropdown.innerHTML = '<div class="autocomplete-message">No patients found.</div>';
                        dropdown.style.display = 'block';
                        return;
                    }

                    data.forEach(name => {
                        const div = document.createElement('div');
                        div.className = 'autocomplete-item';
                        
                        const regex = new RegExp(`(${query.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&')})`, 'gi');
                        div.innerHTML = name.replace(regex, '<strong>$1</strong>');

                        div.addEventListener('click', function() {
                            input.value = name;
                            closeDropdown();
                            form.submit();
                        });

                        dropdown.appendChild(div);
                    });

                    dropdown.style.display = 'block';
                })
                .catch(err => {
                    console.error('Autocomplete error:', err);
                    closeDropdown();
                });
        }, 280);
    });

    input.addEventListener('keydown', function(e) {
        const items = dropdown.getElementsByClassName('autocomplete-item');
        
        if (e.key === 'ArrowDown') {
            currentFocus++;
            addActive(items);
            e.preventDefault();
        } else if (e.key === 'ArrowUp') {
            currentFocus--;
            addActive(items);
            e.preventDefault();
        } else if (e.key === 'Enter') {
            if (currentFocus > -1 && items[currentFocus]) {
                e.preventDefault();
                items[currentFocus].click();
            }
        } else if (e.key === 'Escape') {
            closeDropdown();
        }
    });

    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            closeDropdown();
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>