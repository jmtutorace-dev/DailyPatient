<?php
/**
 * YAKAP GAMOT SYSTEM - Consultation Summary (consultation.php)
 *
 * FULL-PAGE REVIEW VERSION
 * Structure: 1) config/auth  2) helpers  3) CSV export handler
 *            4) input validation  5) database queries  6) calculations
 *            7) HTML/CSS/JS
 *
 * See the accompanying audit notes for a full list of what changed and why.
 */

require_once 'config.php';
require_once 'includes/auth.php';

// =====================================================================
// 1. CONFIGURATION
// =====================================================================
$fpe_gross_rate = 680.00;

// Set by safe_query() if any database call fails, so the page can show
// one clear banner instead of crashing or silently displaying wrong
// financial totals. Never holds raw SQL or driver error text — those go
// to error_log() only.
$db_error = null;

// =====================================================================
// 2. HELPERS
// =====================================================================

/**
 * Validate and normalize a start/end date pair.
 * - Falls back to the current month if either date is missing/unparseable.
 * - Swaps start/end if start_date is after end_date (Case 8).
 * - Never throws; always returns two valid 'Y-m-d' strings.
 */
function normalize_date_range($start_raw, $end_raw) {
    $default_start = date('Y-m-01');
    $default_end   = date('Y-m-t');

    $start_ts = $start_raw ? strtotime($start_raw) : false;
    $end_ts   = $end_raw   ? strtotime($end_raw)   : false;

    if ($start_ts === false || $end_ts === false) {
        return [$default_start, $default_end];
    }

    $start = date('Y-m-d', $start_ts);
    $end   = date('Y-m-d', $end_ts);

    if ($start > $end) {
        [$start, $end] = [$end, $start]; // swap rather than discard
    }

    return [$start, $end];
}

/**
 * Exclusive end-of-day boundary for date-range WHERE clauses. Using
 * `record_date >= start AND record_date < end + 1 day` (instead of
 * BETWEEN start AND end) guarantees every record on the end date is
 * included even when record_date is a DATETIME/TIMESTAMP with a time
 * component (Case 7). For a plain DATE column this is equivalent to the
 * old BETWEEN behavior, so it is safe either way.
 */
function next_day($date_str) {
    return date('Y-m-d', strtotime($date_str . ' +1 day'));
}

/**
 * Prepare, bind, and execute a query, degrading gracefully on failure
 * instead of a fatal error or a misleading ₱0.00 with no explanation.
 * Technical details go to error_log(); the user only ever sees a generic
 * banner (set via the $db_error global).
 *
 * @return mysqli_stmt|null  null on failure — callers must treat that as
 *                            "no rows" and skip $stmt->close().
 */
function safe_query($conn, $sql, $types = '', $params = []) {
    global $db_error;

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log('consultation.php prepare() failed: ' . $conn->error);
        $db_error = 'A database error occurred while loading this report. Please try again in a moment.';
        return null;
    }

    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log('consultation.php execute() failed: ' . $stmt->error);
        $db_error = 'A database error occurred while loading this report. Please try again in a moment.';
        $stmt->close();
        return null;
    }

    return $stmt;
}

// =====================================================================
// 3. CSV EXPORT HANDLER
// =====================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    [$start_date, $end_date] = normalize_date_range($_GET['start_date'] ?? null, $_GET['end_date'] ?? null);
    $end_date_exclusive = next_day($end_date);

    // NOTE (fix): the earnings column used to be SUM(p.consultation_rate).
    // A LEFT JOIN produces exactly one row for a physician with zero
    // matching daily_records (dr.* all NULL, but p.consultation_rate still
    // populated), so SUM(rate) returned that physician's full rate even at
    // 0 patients. COUNT(dr.record_id) * p.consultation_rate is correct in
    // every case and matches the dashboard/table formula (Case 5, Sec. 2/16).
    $stmt = safe_query($conn, "
        SELECT
            p.physician_name,
            p.consultation_rate,
            COUNT(dr.record_id) AS record_count,
            (COUNT(dr.record_id) * p.consultation_rate) AS total_earnings
        FROM physicians p
        LEFT JOIN daily_records dr
            ON p.physician_id = dr.physician_id
            AND dr.record_date >= ? AND dr.record_date < ?
        GROUP BY p.physician_id
        ORDER BY p.physician_name
    ", "ss", [$start_date, $end_date_exclusive]);

    if ($stmt === null) {
        // Do not export a spreadsheet full of misleading zeros if the
        // query itself failed.
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="export_error.txt"');
        echo "Export failed due to a database error. Please try again, or contact support if this continues.\n";
        exit;
    }

    $result = $stmt->get_result();
    $rows = [];
    $grand_records = 0;
    $grand_earnings = 0.00;

    while ($row = $result->fetch_assoc()) {
        $doc_rate = (float)$row['consultation_rate'];
        $doc_earnings = (float)$row['total_earnings'];
        $rows[] = [
            'physician_name' => $row['physician_name'],
            'record_count'   => $row['record_count'],
            'rate'           => '₱' . number_format($doc_rate, 2),
            'total_earnings' => '₱' . number_format($doc_earnings, 2)
        ];
        $grand_records  += (int)$row['record_count'];
        $grand_earnings += $doc_earnings;
    }

    $rows[] = [
        'physician_name' => 'GRAND TOTAL',
        'record_count'   => $grand_records,
        'rate'           => '',
        'total_earnings' => '₱' . number_format($grand_earnings, 2)
    ];

    // Column label matches what is actually counted — consultation
    // records, not verified-unique patients (Sec. 6).
    export_csv(
        'doctor_consultation_earnings_' . $start_date . '_to_' . $end_date . '.csv',
        ['Physician Name', 'Consultation Records', 'Doctor Fee Rate', 'Total Earnings'],
        $rows
    );
    $stmt->close();
    exit;
}

// =====================================================================
// 4. INPUT VALIDATION
// =====================================================================
[$start_date, $end_date] = normalize_date_range($_GET['start_date'] ?? null, $_GET['end_date'] ?? null);
$end_date_exclusive = next_day($end_date);
$date_range_was_adjusted = (
    ($_GET['start_date'] ?? '') !== $start_date ||
    ($_GET['end_date'] ?? '') !== $end_date
) && (isset($_GET['start_date']) || isset($_GET['end_date']));

// =====================================================================
// 5. DATABASE QUERIES
// =====================================================================

// Physician consultation records & individual rates.
$stmt = safe_query($conn, "
    SELECT
        p.physician_id,
        p.physician_name,
        p.consultation_rate,
        p.is_active,
        COUNT(dr.record_id) AS record_count
    FROM physicians p
    LEFT JOIN daily_records dr
        ON p.physician_id = dr.physician_id
        AND dr.record_date >= ? AND dr.record_date < ?
    GROUP BY p.physician_id
    ORDER BY p.is_active DESC, p.physician_name
", "ss", [$start_date, $end_date_exclusive]);
$physicians_result = $stmt ? $stmt->get_result()->fetch_all(MYSQLI_ASSOC) : [];
if ($stmt) $stmt->close();

// Overall consultation record count (all visit types in range).
$stmt = safe_query($conn,
    "SELECT COUNT(*) AS overall_count FROM daily_records WHERE record_date >= ? AND record_date < ?",
    "ss", [$start_date, $end_date_exclusive]
);
$overall_count = $stmt ? (int)$stmt->get_result()->fetch_assoc()['overall_count'] : 0;
if ($stmt) $stmt->close();

// Medicine type breakdown.
$stmt = safe_query($conn, "
    SELECT
        mt.meds_type_name,
        mt.is_consultation,
        COUNT(dr.record_id) AS cnt
    FROM meds_types mt
    LEFT JOIN daily_records dr
        ON mt.meds_type_id = dr.meds_type_id
        AND dr.record_date >= ? AND dr.record_date < ?
    GROUP BY mt.meds_type_id
    ORDER BY cnt DESC
", "ss", [$start_date, $end_date_exclusive]);
$meds_result = $stmt ? $stmt->get_result()->fetch_all(MYSQLI_ASSOC) : [];
if ($stmt) $stmt->close();

// =====================================================================
// 6. CALCULATIONS (single authoritative pass — reused everywhere below)
// =====================================================================

// -- Physician earnings --------------------------------------------------
// Computed once here and stored back onto each row, so the KPI card, the
// physician table, its grand total, and the CSV export can never disagree
// (Sec. 2, 8, 16, 28).
$total_doctors_fees     = 0.00;
$grand_consult_records  = 0;

foreach ($physicians_result as &$doc) {
    $p_count = (int)$doc['record_count'];
    $p_rate  = (float)$doc['consultation_rate'];

    // A configured rate <= 0 (including missing/NULL, which casts to 0.0)
    // yields ₱0 earnings rather than silently substituting another rate.
    // Whether a rate of exactly 0 is "valid" vs. "not yet configured" is a
    // business-rule question — see audit notes (Sec. 25).
    $effective_rate = ($p_rate > 0) ? $p_rate : 0.00;
    $doc_earnings   = $p_count * $effective_rate;

    $doc['earnings'] = $doc_earnings; // reused by the table markup below

    $grand_consult_records += $p_count;
    $total_doctors_fees    += $doc_earnings;
}
unset($doc);

// -- FPE (see "Business-rule assumptions" in the audit notes) ------------
// The original query joined meds_types but never filtered on it, so it
// counted exactly the same population as $overall_count while doing extra,
// pointless work. Rather than inventing an eligibility rule (e.g. "only a
// specific meds_type"), this preserves the previously-observed behavior —
// every daily_records row in range is treated as FPE-eligible — but
// removes the redundant duplicate query. If FPE eligibility should exclude
// certain meds types or record kinds, that filter needs to be added here
// once the rule is confirmed.
$fpe_patient_count = $overall_count;

$gross_fpe_value = $fpe_patient_count * $fpe_gross_rate;
$net_fpe_fund     = $gross_fpe_value - $total_doctors_fees;

// -- Medicine breakdown percentages ---------------------------------------
// Denominator is the sum of the rows actually shown in this table, not a
// separately-queried $overall_count — so the percentages always reconcile
// with what's on screen even if some daily_records rows have a meds_type_id
// that doesn't match any meds_types row (Sec. 6/13/15, Case 10).
$meds_breakdown_total = 0;
foreach ($meds_result as $mb) {
    $meds_breakdown_total += (int)$mb['cnt'];
}

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
    --info: #2563eb;
    --radius-sm: 6px;
    --radius-md: 10px;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
}

body {
    background-color: var(--bg-main);
    color: var(--text-primary);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.dashboard-container {
    max-width: 1300px;
    margin: 0 auto;
    padding: 1.5rem 1rem;
    position: relative;
}

/* Header & Actions */
.header-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
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
    gap: 0.75rem;
    align-items: center;
}

/* Buttons */
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
    font-family: inherit;
}

.btn-custom:hover { background: #f1f5f9; border-color: #cbd5e1; }
.btn-custom:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}
.btn-custom:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-custom.btn-primary-custom {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}
.btn-custom.btn-primary-custom:hover { background: var(--primary-hover); border-color: var(--primary-hover); }

/* Alert banner (DB errors, adjusted-range notice) */
.alert-banner {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.75rem 1rem;
    border-radius: var(--radius-md);
    font-size: 0.875rem;
    margin-bottom: 1rem;
    border: 1px solid transparent;
}
.alert-banner.alert-danger { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.alert-banner.alert-info { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }

/* Filter Card */
.filter-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.filter-form-grid {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
}

.filter-group { display: flex; align-items: center; gap: 0.5rem; }
.filter-group label { font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); }

.filter-group input[type="date"] {
    padding: 0.45rem 0.65rem;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    color: var(--text-primary);
    background: #fff;
    outline: none;
    transition: border-color 0.15s;
}
.filter-group input[type="date"]:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.1);
}

.preset-links { display: flex; gap: 0.5rem; margin-left: auto; flex-wrap: wrap; }

@media (max-width: 768px) {
    .preset-links { margin-left: 0; width: 100%; }
    .filter-form-grid { gap: 0.75rem; }
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
    border-radius: var(--radius-md);
    padding: 1.25rem;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.kpi-card::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
    background: var(--border-subtle);
}
.kpi-card.accent-primary::before { background: var(--primary); }
.kpi-card.accent-warning::before { background: var(--warning); }
.kpi-card.accent-success::before { background: var(--success); }
.kpi-card.accent-neutral::before { background: var(--text-secondary); }
.kpi-card.accent-info::before { background: var(--info); }

.kpi-card .kpi-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 0.04em;
}
.kpi-card .kpi-value {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 0.35rem;
    letter-spacing: -0.02em;
    word-break: break-word;
}
.kpi-card .kpi-subtext { font-size: 0.775rem; color: var(--text-secondary); margin-top: 0.25rem; }

/* Layout */
.content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
@media (max-width: 1024px) { .content-grid { grid-template-columns: 1fr; } }

.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}
.card-panel h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 1rem 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.35rem;
}

/* Tables */
.table-responsive { overflow-x: auto; }
.modern-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; text-align: left; min-width: 420px; }
.modern-table th {
    background: #f8fafc;
    padding: 0.75rem;
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 1px solid var(--border-subtle);
    letter-spacing: -0.01em;
}
.modern-table td { padding: 0.75rem; border-bottom: 1px solid var(--border-subtle); color: var(--text-primary); vertical-align: middle; }
.modern-table tbody tr:hover { background-color: #f8fafc; }
.modern-table tfoot td { background: #f8fafc; font-weight: 700; border-top: 2px solid var(--border-subtle); }

.empty-state { text-align: center; color: var(--text-secondary); padding: 2rem 1rem; }
.empty-state .empty-icon { font-size: 1.5rem; display: block; margin-bottom: 0.4rem; }

/* Badges */
.badge-tag {
    display: inline-flex;
    align-items: center;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    margin-left: 0.35rem;
    letter-spacing: 0.02em;
}
.badge-success { background: #d1fae5; color: #065f46; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

/* Progress bar */
.progress-track { background: #f1f5f9; height: 6px; width: 100%; border-radius: 3px; overflow: hidden; margin-top: 4px; }
.progress-bar { background: var(--primary); height: 100%; border-radius: 3px; }

/* Loading overlay (shown briefly during full-page navigations) */
.page-loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(248, 250, 252, 0.75);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.page-loading-overlay.is-visible { display: flex; }
.page-loading-spinner {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 0.75rem 1.1rem;
    box-shadow: var(--shadow-md);
    font-size: 0.875rem;
    color: var(--text-primary);
}
.spinner-ring {
    width: 16px; height: 16px;
    border: 2px solid var(--border-subtle);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.sr-only {
    position: absolute;
    width: 1px; height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

@media print {
    .header-actions, .filter-card, .page-loading-overlay { display: none !important; }
    .content-grid { grid-template-columns: 1fr; }
    body { background: white; }
    .card-panel { border: none; box-shadow: none; padding: 0; }
}
</style>

<div class="dashboard-container">

    <!-- Loading overlay for full-page navigations (filter apply / export / presets) -->
    <div class="page-loading-overlay" id="pageLoadingOverlay" role="status" aria-live="polite">
        <div class="page-loading-spinner">
            <span class="spinner-ring" aria-hidden="true"></span>
            <span>Loading report…</span>
        </div>
    </div>

    <!-- Header Controls -->
    <header class="header-bar">
        <div class="header-title-wrapper">
            <h2>Financial &amp; Consultation Summary</h2>
            <p>Overview of clinical encounters, physician compensation metrics, and fund balances.</p>
        </div>
        <div class="header-actions">
            <a href="?start_date=<?= h($start_date) ?>&end_date=<?= h($end_date) ?>&export=csv"
               class="btn-custom" id="exportCsvLink">
                <span aria-hidden="true">📥</span> Export CSV
            </a>
            <button type="button" onclick="window.print()" class="btn-custom">
                <span aria-hidden="true">🖨️</span> Print Report
            </button>
        </div>
    </header>

    <?php if ($db_error): ?>
        <div class="alert-banner alert-danger" role="alert">
            <span aria-hidden="true">⚠️</span>
            <span><?= h($db_error) ?></span>
        </div>
    <?php elseif ($date_range_was_adjusted): ?>
        <div class="alert-banner alert-info" role="status">
            <span aria-hidden="true">ℹ️</span>
            <span>The date range you provided wasn't valid, so it was adjusted to <?= h($start_date) ?> – <?= h($end_date) ?>.</span>
        </div>
    <?php endif; ?>

    <!-- Date Range Filter -->
    <section class="filter-card">
        <form class="filter-form-grid" method="get" action="consultation.php" id="filterForm">
            <div class="filter-group">
                <label for="start_date">From</label>
                <input type="date" id="start_date" name="start_date" value="<?= h($start_date) ?>">
            </div>

            <div class="filter-group">
                <label for="end_date">To</label>
                <input type="date" id="end_date" name="end_date" value="<?= h($end_date) ?>">
            </div>

            <button type="submit" class="btn-custom btn-primary-custom" id="applyFilterBtn">Apply Filter</button>

            <div class="preset-links">
                <a href="consultation.php?start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-t') ?>" class="btn-custom preset-link" style="padding: 0.4rem 0.65rem; font-size: 0.8rem;">This Month</a>
                <a href="consultation.php?start_date=<?= date('Y-m-01', strtotime('-1 month')) ?>&end_date=<?= date('Y-m-t', strtotime('-1 month')) ?>" class="btn-custom preset-link" style="padding: 0.4rem 0.65rem; font-size: 0.8rem;">Last Month</a>
                <a href="consultation.php?start_date=<?= date('Y-m-d') ?>&end_date=<?= date('Y-m-d') ?>" class="btn-custom preset-link" style="padding: 0.4rem 0.65rem; font-size: 0.8rem;">Today</a>
            </div>
        </form>
    </section>

    <!-- KPI Summary Metrics (Overview) -->
    <section class="kpi-grid" aria-label="Overview">
        <div class="kpi-card accent-neutral">
            <div class="kpi-title">Total Consultation Records</div>
            <div class="kpi-value"><?= number_format($overall_count) ?></div>
            <div class="kpi-subtext">All daily records in range (not verified-unique patients)</div>
        </div>
        <div class="kpi-card accent-info">
            <div class="kpi-title">FPE Records</div>
            <div class="kpi-value"><?= number_format($fpe_patient_count) ?></div>
            <div class="kpi-subtext">Currently equal to total records — see note below</div>
        </div>
        <div class="kpi-card accent-primary">
            <div class="kpi-title">Gross FPE Value</div>
            <div class="kpi-value">₱<?= number_format($gross_fpe_value, 2) ?></div>
            <div class="kpi-subtext"><?= number_format($fpe_patient_count) ?> × ₱<?= number_format($fpe_gross_rate, 0) ?></div>
        </div>
        <div class="kpi-card accent-warning">
            <div class="kpi-title">Total Doctor's Fees</div>
            <div class="kpi-value">₱<?= number_format($total_doctors_fees, 2) ?></div>
            <div class="kpi-subtext">Sum of each physician's own rate × their records</div>
        </div>
        <div class="kpi-card accent-success">
            <div class="kpi-title">Net FPE Fund</div>
            <div class="kpi-value">₱<?= number_format($net_fpe_fund, 2) ?></div>
            <div class="kpi-subtext">Gross FPE − Total Doctor's Fees</div>
        </div>
    </section>

    <!-- Main Section Grid -->
    <div class="content-grid">
        <!-- Left Panel: Doctor Summary Breakdown -->
        <section class="card-panel">
            <h3><span>Doctor Consultation Summary</span> <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-secondary);">Configurable Rates</span></h3>
            <div class="table-responsive">
                <table class="modern-table">
                    <caption class="sr-only">Physician consultation counts, rates, and earnings for the selected date range</caption>
                    <thead>
                        <tr>
                            <th scope="col">Doctor Name</th>
                            <th scope="col" style="text-align: center;">Consultations</th>
                            <th scope="col" style="text-align: right;">Rate</th>
                            <th scope="col" style="text-align: right;">Total Earnings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($physicians_result)): ?>
                            <tr>
                                <td colspan="4" class="empty-state">
                                    <span class="empty-icon" aria-hidden="true">🗒️</span>
                                    No consultation records found for the selected date range.
                                </td>
                            </tr>
                        <?php else: foreach ($physicians_result as $row):
                            // $row['earnings'] was computed once above (authoritative
                            // calculation), so this table and the KPI cards can never
                            // show two different totals for the same period.
                            $doc_records  = (int)$row['record_count'];
                            $doc_rate     = (float)$row['consultation_rate'];
                            $doc_earnings = $row['earnings'];
                        ?>
                            <tr>
                                <td>
                                    <strong><?= h($row['physician_name']) ?></strong>
                                    <?= $row['is_active'] ? '' : '<span class="badge-tag badge-inactive">Inactive</span>' ?>
                                </td>
                                <td style="text-align: center; font-weight: 600;"><?= $doc_records ?></td>
                                <td style="text-align: right; color: var(--text-secondary);">₱<?= number_format($doc_rate, 2) ?></td>
                                <td style="text-align: right; font-weight: 600;">₱<?= number_format($doc_earnings, 2) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>GRAND TOTAL</td>
                            <td style="text-align: center;"><?= number_format($grand_consult_records) ?></td>
                            <td></td>
                            <td style="text-align: right; color: var(--success);">₱<?= number_format($total_doctors_fees, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- Right Column Stack -->
        <div>
            <!-- Financial Breakdown Accounting Table -->
            <section class="card-panel">
                <h3>Accounting Financial Breakdown</h3>
                <div class="table-responsive">
                    <table class="modern-table">
                        <caption class="sr-only">Gross FPE value, doctor's fees, and net fund computation</caption>
                        <thead>
                            <tr>
                                <th scope="col">Financial Component</th>
                                <th scope="col" style="text-align: right;">Amount / Computation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Gross FPE Value</td>
                                <td style="text-align: right;"><strong><?= number_format($fpe_patient_count) ?> × ₱<?= number_format($fpe_gross_rate, 2) ?> = ₱<?= number_format($gross_fpe_value, 2) ?></strong></td>
                            </tr>
                            <tr>
                                <td>Total Doctor's Fees</td>
                                <td style="text-align: right; color: var(--danger);"><strong>− ₱<?= number_format($total_doctors_fees, 2) ?></strong></td>
                            </tr>
                            <tr>
                                <td>Net FPE Fund</td>
                                <td style="text-align: right;">
                                    <strong style="color: <?= $net_fpe_fund < 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                                        ₱<?= number_format($gross_fpe_value, 2) ?> − ₱<?= number_format($total_doctors_fees, 2) ?> = ₱<?= number_format($net_fpe_fund, 2) ?>
                                        <?= $net_fpe_fund < 0 ? ' (deficit)' : '' ?>
                                    </strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Meds Type Breakdown -->
            <section class="card-panel">
                <h3>Breakdown by Meds Type</h3>
                <div class="table-responsive">
                    <table class="modern-table">
                        <caption class="sr-only">Record counts and percentage share by medicine type</caption>
                        <thead>
                            <tr>
                                <th scope="col">Meds Type</th>
                                <th scope="col" style="text-align: center;">Count</th>
                                <th scope="col" style="text-align: right; width: 35%;">% Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($meds_result)): ?>
                                <tr>
                                    <td colspan="3" class="empty-state">
                                        <span class="empty-icon" aria-hidden="true">💊</span>
                                        No medicine type records configured.
                                    </td>
                                </tr>
                            <?php else: foreach ($meds_result as $mb):
                                $pct = $meds_breakdown_total > 0
                                    ? round(((int)$mb['cnt'] / $meds_breakdown_total) * 100, 1)
                                    : 0;
                                // Clamp defensively so a display glitch can never push the
                                // progress bar or label outside a valid 0-100% range.
                                $pct = max(0, min(100, $pct));
                            ?>
                            <tr>
                                <td>
                                    <?= h($mb['meds_type_name']) ?>
                                    <span class="badge-tag <?= $mb['is_consultation'] ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $mb['is_consultation'] ? 'Consult' : 'Non-Consult' ?>
                                    </span>
                                </td>
                                <td style="text-align: center; font-weight: 600;"><?= (int)$mb['cnt'] ?></td>
                                <td style="text-align: right;">
                                    <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-secondary);"><?= $pct ?>%</div>
                                    <div class="progress-track">
                                        <div class="progress-bar" style="width: <?= $pct ?>%;" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
(function () {
    "use strict";

    var overlay = document.getElementById('pageLoadingOverlay');
    var filterForm = document.getElementById('filterForm');
    var applyBtn = document.getElementById('applyFilterBtn');
    var exportLink = document.getElementById('exportCsvLink');
    var presetLinks = document.querySelectorAll('.preset-link');

    function showLoading(disableEl) {
        if (overlay) overlay.classList.add('is-visible');
        if (disableEl) disableEl.setAttribute('disabled', 'disabled');
    }

    // Basic client-side guard: don't let start_date be after end_date
    // before it even reaches the server (server still re-validates it).
    if (filterForm) {
        filterForm.addEventListener('submit', function () {
            var startInput = document.getElementById('start_date');
            var endInput = document.getElementById('end_date');
            if (startInput && endInput && startInput.value && endInput.value && startInput.value > endInput.value) {
                var tmp = startInput.value;
                startInput.value = endInput.value;
                endInput.value = tmp;
            }
            showLoading(applyBtn);
        });
    }

    if (exportLink) {
        exportLink.addEventListener('click', function () {
            showLoading(null); // exports don't navigate away, just download
            window.setTimeout(function () {
                if (overlay) overlay.classList.remove('is-visible');
            }, 1500);
        });
    }

    presetLinks.forEach(function (link) {
        link.addEventListener('click', function () { showLoading(null); });
    });

    // If the user navigates back (bfcache), make sure a stale overlay
    // from a previous click never gets stuck on screen.
    window.addEventListener('pageshow', function () {
        if (overlay) overlay.classList.remove('is-visible');
        if (applyBtn) applyBtn.removeAttribute('disabled');
    });
})();
</script>

<?php include 'includes/footer.php'; ?>