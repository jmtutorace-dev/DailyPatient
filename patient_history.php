<?php
/**
 * YAKAP GAMOT SYSTEM - Patient History
 *
 * Supports two modes:
 * 1) full page search + reporting when accessed directly
 * 2) lightweight fragment response when fetched by the modal in index.php
 */
require_once 'config.php';
require_once 'includes/auth.php';

function format_checkbox_items($data) {
    if (empty($data)) return [];

    if (is_string($data) && ($decoded = json_decode($data, true)) !== null) {
        $data = $decoded;
    }

    if (is_array($data)) {
        return array_values(array_unique(array_filter(array_map('trim', $data))));
    }

    if (is_string($data)) {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $data)))));
    }

    return [trim((string)$data)];
}

$is_fragment_request = isset($_GET['patient_name']) && !isset($_GET['patient']) && !isset($_GET['full_page']) && !isset($_GET['ajax_search']) && !isset($_GET['export']);

if ($is_fragment_request) {
    $patient_name = trim($_GET['patient_name'] ?? '');
    $records = [];

    if ($patient_name !== '') {
        $stmt = $conn->prepare(
            "SELECT dr.record_date, dr.patient_name, dr.has_meds, dr.has_labs, dr.has_gamot_meds,
                    p.physician_name, mt.meds_type_name, mt.is_consultation
             FROM daily_records dr
             LEFT JOIN physicians p ON dr.physician_id = p.physician_id
             LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
             WHERE dr.patient_name = ?
             ORDER BY dr.record_date ASC, dr.created_at ASC"
        );
        $stmt->bind_param('s', $patient_name);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();
    }

    if (empty($records)):
        ?>
        <div class="empty-state-block">
            <div class="empty-state-icon" aria-hidden="true">🗂️</div>
            <p class="empty-state-message">No visit records found for <strong><?= h($patient_name) ?></strong>.</p>
            <p class="empty-state-hint">This patient has no logged FPE or consultation entries.</p>
        </div>
        <?php
    else:
        ?>
        <div class="timeline">
            <?php foreach ($records as $row):
                $is_consultation = !empty($row['is_consultation']);
                $badge_class = $is_consultation ? 'badge-success' : 'badge-warning';
                $badge_label = $row['meds_type_name'] ?? '—';
                $physician = $row['physician_name'] ?? '— No physician —';
            ?>
            <div class="timeline-item">
                <div class="tl-date"><?= h(date('M j, Y', strtotime($row['record_date']))) ?></div>
                <div class="tl-title">
                    <span class="badge <?= $badge_class ?>"><?= h($badge_label) ?></span>
                </div>
                <div class="tl-sub">🩺 <?= h($physician) ?></div>
                <div class="tl-sub tl-flags">
                    <span class="ph-flag <?= $row['has_meds'] ? 'ph-flag-yes' : 'ph-flag-no' ?>">
                        <?= $row['has_meds'] ? '✓' : '✗' ?> Meds
                    </span>
                    <span class="ph-flag <?= $row['has_labs'] ? 'ph-flag-yes' : 'ph-flag-no' ?>">
                        <?= $row['has_labs'] ? '✓' : '✗' ?> Labs
                    </span>
                    <span class="ph-flag <?= $row['has_gamot_meds'] ? 'ph-flag-yes' : 'ph-flag-no' ?>">
                        <?= $row['has_gamot_meds'] ? '✓' : '✗' ?> Gamot
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    endif;
    exit;
}

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
            $sql .= ' AND (' . implode(' AND ', $conditions) . ')';
        }

        $sql .= ' ORDER BY patient_name ASC LIMIT 12';

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

if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($_GET['patient'])) {
    $patient_name = trim($_GET['patient']);
    $stmt = $conn->prepare(
        "SELECT dr.record_date, dr.patient_name, COALESCE(p.physician_name, '') AS physician,
                COALESCE(mt.meds_type_name, '') AS meds_type, dr.has_meds, dr.has_labs,
                dr.has_gamot_meds, COALESCE(dr.meds, dr.medications, '') AS meds,
                COALESCE(dr.labs, dr.laboratory, '') AS labs,
                COALESCE(dr.gamot, dr.gamot_meds, '') AS gamot, dr.remarks, dr.created_at
         FROM daily_records dr
         LEFT JOIN physicians p ON dr.physician_id = p.physician_id
         LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
         WHERE LOWER(TRIM(dr.patient_name)) LIKE LOWER(TRIM(?))
         ORDER BY dr.record_date DESC"
    );
    $search = '%' . $patient_name . '%';
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();

    $clean_filename = 'patient_history_' . preg_replace('/[^a-zA-Z0-9]/', '_', $patient_name) . '.csv';
    export_csv($clean_filename, ['Record Date', 'Patient Name', 'Physician', 'Meds Type', 'Has Meds', 'Has Labs', 'Has Gamot Meds', 'Meds', 'Labs', 'Gamot', 'Remarks', 'Created At'], $result);
    $stmt->close();
    exit;
}

$patient_name = trim($_GET['patient'] ?? $_GET['name'] ?? $_POST['patient_name'] ?? '');

include 'includes/header.php';
?>

<style>
:root {
    --history-primary: #1b4332;
    --history-primary-light: #eaf7f1;
    --history-accent: #d4a017;
    --history-card: #ffffff;
    --history-bg: #f5f0eb;
    --history-border: #e6ddd5;
    --history-text: #1a1a2e;
    --history-muted: #5a5a7a;
    --history-success: #2d6a4f;
    --history-warning: #b45309;
    --history-danger: #c1292e;
    --history-pill: #f8fafc;
}

.dashboard-container {
    max-width: 1280px;
    margin: 0 auto;
    padding: 1.25rem 1rem 2rem;
}

.header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.header-bar h2 {
    margin: 0;
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--history-text);
}

.control-card,
.card-panel {
    background: var(--history-card);
    border: 1px solid var(--history-border);
    border-radius: 14px;
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.04);
}

.control-card {
    padding: 1.1rem 1.2rem;
    margin-bottom: 1.25rem;
}

.filter-form {
    display: flex;
    gap: 0.85rem;
    align-items: center;
    flex-wrap: wrap;
}

.filter-form label {
    font-weight: 700;
    color: var(--history-muted);
    font-size: 0.84rem;
    letter-spacing: 0.02em;
}

.autocomplete-wrapper {
    position: relative;
    min-width: min(100%, 360px);
}

.modern-input {
    width: 100%;
    min-width: 260px;
    padding: 0.72rem 0.9rem;
    border: 1px solid var(--history-border);
    background: #fff;
    border-radius: 10px;
    color: var(--history-text);
    font-size: 0.92rem;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.modern-input:focus {
    border-color: var(--history-primary);
    box-shadow: 0 0 0 4px rgba(27, 67, 50, 0.08);
    outline: none;
}

.autocomplete-dropdown {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 6px);
    background: #fff;
    border: 1px solid var(--history-border);
    border-radius: 10px;
    box-shadow: 0 18px 35px rgba(15, 23, 42, 0.12);
    z-index: 30;
    display: none;
    max-height: 250px;
    overflow-y: auto;
}

.autocomplete-item {
    padding: 0.72rem 0.8rem;
    font-size: 0.88rem;
    color: var(--history-text);
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s ease;
}

.autocomplete-item:hover,
.autocomplete-item.selected {
    background: var(--history-primary-light);
    color: var(--history-primary);
}

.autocomplete-message {
    padding: 0.75rem 0.8rem;
    font-size: 0.84rem;
    color: var(--history-muted);
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.kpi-card {
    background: var(--history-card);
    border: 1px solid var(--history-border);
    border-radius: 14px;
    padding: 1rem 1.1rem;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
}

.kpi-card .kpi-title {
    color: var(--history-muted);
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.kpi-card .kpi-value {
    margin-top: 0.35rem;
    font-size: 1.8rem;
    font-weight: 800;
    line-height: 1.1;
}

.card-panel {
    padding: 1.2rem;
    margin-bottom: 1.25rem;
}

.card-panel h3 {
    margin: 0 0 0.9rem;
    font-size: 1.08rem;
    font-weight: 700;
    color: var(--history-text);
}

.overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
}

.overview-box {
    border: 1px solid var(--history-border);
    background: linear-gradient(180deg, #fff, #faf7f4);
    border-radius: 12px;
    padding: 1rem;
}

.overview-box strong {
    display: block;
    margin-bottom: 0.55rem;
    font-size: 0.9rem;
    color: var(--history-text);
}

.overview-list {
    white-space: pre-line;
    color: var(--history-muted);
    font-size: 0.83rem;
    line-height: 1.7;
    margin: 0;
}

.table-wrapper {
    overflow-x: auto;
    border: 1px solid var(--history-border);
    border-radius: 12px;
}

.modern-table {
    width: 100%;
    min-width: 920px;
    border-collapse: collapse;
    background: #fff;
}

.modern-table th {
    background: #f8fafc;
    color: var(--history-muted);
    padding: 0.8rem 0.75rem;
    text-align: left;
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid var(--history-border);
}

.modern-table td {
    padding: 0.85rem 0.75rem;
    border-bottom: 1px solid #eef2f7;
    color: var(--history-text);
    vertical-align: top;
}

.modern-table tbody tr:hover {
    background: #fafbff;
}

.meds-type-tag {
    display: inline-block;
    background: var(--history-primary-light);
    color: var(--history-primary);
    border: 1px solid rgba(27, 67, 50, 0.1);
    border-radius: 999px;
    padding: 0.28rem 0.7rem;
    font-size: 0.75rem;
    font-weight: 700;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.24rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    margin-bottom: 0.5rem;
}

.status-pill.yes {
    background: rgba(45, 106, 79, 0.1);
    color: var(--history-success);
}

.status-pill.no {
    background: rgba(193, 41, 46, 0.08);
    color: var(--history-danger);
}

.item-pills-list {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    align-items: flex-start;
}

.item-sub-pill {
    display: inline-block;
    font-size: 0.72rem;
    color: var(--history-muted);
    background: var(--history-pill);
    border: 1px solid var(--history-border);
    padding: 0.18rem 0.5rem;
    border-radius: 6px;
    line-height: 1.5;
}

.visit-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: #fff;
    border: 1px solid var(--history-border);
    padding: 0.42rem 0.8rem;
    color: var(--history-primary);
    font-weight: 700;
    font-size: 0.78rem;
    transition: all 0.15s ease;
}

.visit-badge:hover {
    background: var(--history-primary-light);
    border-color: rgba(27, 67, 50, 0.15);
}

.timeline-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.empty-state {
    padding: 2.4rem 1.15rem;
    text-align: center;
    color: var(--history-muted);
    font-size: 0.95rem;
}

.empty-state-block {
    display: grid;
    place-items: center;
    text-align: center;
    gap: 0.5rem;
    padding: 2rem 1rem;
    color: var(--history-muted);
}

.empty-state-icon {
    font-size: 2rem;
}

.empty-state-message {
    margin: 0;
    font-size: 1rem;
    color: var(--history-text);
}

.empty-state-hint {
    margin: 0;
    font-size: 0.82rem;
}

.timeline {
    position: relative;
    padding-left: 1rem;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 4px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--history-border);
}

.timeline-item {
    position: relative;
    padding: 0.8rem 0 0.8rem 1.3rem;
    border-left: 2px solid transparent;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -5px;
    top: 1rem;
    width: 10px;
    height: 10px;
    background: var(--history-primary);
    border: 2px solid #fff;
    border-radius: 50%;
}

.tl-date {
    font-size: 0.72rem;
    color: var(--history-muted);
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.tl-title {
    margin-top: 0.25rem;
}

.tl-sub {
    margin-top: 0.25rem;
    color: var(--history-muted);
    font-size: 0.83rem;
}

.tl-flags {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.4rem;
}

.ph-flag {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.2rem 0.55rem;
    font-size: 0.72rem;
    font-weight: 700;
}

.ph-flag-yes {
    background: rgba(45, 106, 79, 0.1);
    color: var(--history-success);
}

.ph-flag-no {
    background: rgba(193, 41, 46, 0.08);
    color: var(--history-danger);
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    font-size: 0.72rem;
    padding: 0.23rem 0.62rem;
    font-weight: 800;
}

.badge-success {
    background: rgba(45, 106, 79, 0.1);
    color: var(--history-success);
}

.badge-warning {
    background: rgba(180, 83, 9, 0.08);
    color: var(--history-warning);
}

@media (max-width: 700px) {
    .filter-form {
        align-items: stretch;
    }

    .autocomplete-wrapper,
    .modern-input {
        min-width: 100%;
        width: 100%;
    }

    .header-bar {
        align-items: flex-start;
    }
}
</style>

<div class="dashboard-container">
    <header class="header-bar">
        <h2>📋 Patient History Tracker</h2>
        <?php if (!empty($patient_name)): ?>
            <a href="index.php" class="btn btn-sm btn-outline">⬅️ Back to Daily Log</a>
        <?php endif; ?>
    </header>

    <section class="control-card">
        <form class="filter-form" method="get" action="patient_history.php" id="searchForm" autocomplete="off">
            <label for="patient">Patient Name:</label>
            <div class="autocomplete-wrapper">
                <input type="text" name="patient" id="patient" class="modern-input" value="<?= h($patient_name) ?>" placeholder="Type patient name..." required>
                <div id="autocompleteDropdown" class="autocomplete-dropdown"></div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">🔍 Search</button>
            <?php if ($patient_name): ?>
                <a href="patient_history.php" class="btn btn-sm btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </section>

    <?php if ($patient_name):
        $stmt = $conn->prepare(
            "SELECT dr.*, p.physician_name, mt.meds_type_name, mt.is_consultation
             FROM daily_records dr
             LEFT JOIN physicians p ON dr.physician_id = p.physician_id
             LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
             WHERE LOWER(TRIM(dr.patient_name)) LIKE LOWER(TRIM(?))
             ORDER BY dr.record_date DESC, dr.created_at DESC"
        );
        $search = '%' . $patient_name . '%';
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $records = $stmt->get_result();

        $total_visits = $records->num_rows;
        $has_meds_count = 0;
        $has_labs_count = 0;
        $has_gamot_count = 0;
        $all_meds_list = [];
        $all_labs_list = [];
        $all_gamot_list = [];
        $dates_visited = [];

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
                foreach ($row_meds as $m) {
                    $all_meds_list[] = $m;
                }
            }
            if (!empty($r['has_labs']) || !empty($row_labs)) {
                $has_labs_count++;
                foreach ($row_labs as $l) {
                    $all_labs_list[] = $l;
                }
            }
            if (!empty($r['has_gamot_meds']) || !empty($row_gamot)) {
                $has_gamot_count++;
                foreach ($row_gamot as $g) {
                    $all_gamot_list[] = $g;
                }
            }
        }

        $distinct_dates = count($dates_visited);
        $records->data_seek(0);
    ?>

        <section class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-title">Total Visits</div>
                <div class="kpi-value" style="color: var(--history-primary);"><?= $total_visits ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Unique Visit Dates</div>
                <div class="kpi-value" style="color: #0d9488;"><?= $distinct_dates ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Visits with Meds</div>
                <div class="kpi-value" style="color: var(--history-warning);"><?= $has_meds_count ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-title">Visits with Gamot</div>
                <div class="kpi-value" style="color: var(--history-danger);"><?= $has_gamot_count ?></div>
            </div>
        </section>

        <section class="card-panel">
            <h3>📦 Comprehensive Overview</h3>
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

        <section class="card-panel">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom: 1rem;">
                <h3 style="margin:0;">Visit History for <span style="color: var(--history-primary);"><?= h($patient_name) ?></span></h3>
                <a href="?patient=<?= h(urlencode($patient_name)) ?>&export=csv" class="btn btn-blue btn-sm">📥 Export CSV</a>
            </div>

            <?php if ($total_visits === 0): ?>
                <div class="empty-state">No medical visit records found matching <strong><?= h($patient_name) ?></strong>.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Record Date</th>
                                <th>Attending Physician</th>
                                <th>Meds Type</th>
                                <th style="text-align:center;">Meds Status & Input</th>
                                <th style="text-align:center;">Labs Status & Input</th>
                                <th style="text-align:center;">Gamot Status & Input</th>
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
                                        <a href="index.php?date=<?= h($r['record_date']) ?>" style="color: var(--history-primary); font-weight: 700; text-decoration: none;">
                                            📅 <?= h(date('M j, Y', strtotime($r['record_date']))) ?>
                                        </a>
                                    </td>
                                    <td><span style="font-weight:700;"><?= h($r['physician_name'] ?? '—') ?></span></td>
                                    <td>
                                        <?= !empty($r['meds_type_name']) ? '<span class="meds-type-tag">' . h($r['meds_type_name']) . '</span>' : '—' ?>
                                    </td>
                                    <td style="text-align:center;">
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
                                    <td style="text-align:center;">
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
                                    <td style="text-align:center;">
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

        <section class="card-panel">
            <h3>🗓️ Rapid Visit Timeline</h3>
            <?php if ($distinct_dates === 0): ?>
                <div class="empty-state" style="padding: 1rem;">No registered visit dates available.</div>
            <?php else: ?>
                <div class="timeline-container">
                    <?php foreach (array_keys($dates_visited) as $dv): ?>
                        <a href="index.php?date=<?= h($dv) ?>" class="visit-badge">📅 <?= h(date('M j, Y', strtotime($dv))) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php $stmt->close(); ?>
    <?php else: ?>
        <section class="card-panel">
            <div class="empty-state">👆 Enter a patient name above to view complete visit history, treatment records, and item summaries.</div>
        </section>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
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
        if (!items || !items.length) return;
        removeActive(items);
        if (currentFocus >= items.length) currentFocus = 0;
        if (currentFocus < 0) currentFocus = items.length - 1;
        items[currentFocus].classList.add('selected');
        items[currentFocus].scrollIntoView({ block: 'nearest' });
    }

    function removeActive(items) {
        Array.from(items).forEach(item => item.classList.remove('selected'));
    }

    input.addEventListener('input', function () {
        const query = this.value.trim();
        clearTimeout(debounceTimer);

        if (query.length < 1) {
            closeDropdown();
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch('patient_history.php?ajax_search=1&term=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    dropdown.innerHTML = '';
                    currentFocus = -1;

                    if (!data.length) {
                        dropdown.innerHTML = '<div class="autocomplete-message">No patients found.</div>';
                        dropdown.style.display = 'block';
                        return;
                    }

                    data.forEach(name => {
                        const div = document.createElement('div');
                        div.className = 'autocomplete-item';
                        const regex = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                        div.innerHTML = name.replace(regex, '<strong>$1</strong>');

                        div.addEventListener('click', function () {
                            input.value = name;
                            closeDropdown();
                            form.submit();
                        });

                        dropdown.appendChild(div);
                    });

                    dropdown.style.display = 'block';
                })
                .catch(() => closeDropdown());
        }, 250);
    });

    input.addEventListener('keydown', function (e) {
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

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            closeDropdown();
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
