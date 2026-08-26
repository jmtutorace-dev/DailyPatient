<?php
/**
 * YAKAP GAMOT SYSTEM - Consultation Summary (consultation.php)
 */

require_once 'config.php';
require_once 'includes/auth.php';

// --- CONFIGURABLE RATES ---
$fpe_gross_rate = 680.00;

// --- 1. CSV EXPORT HANDLER ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date   = $_GET['end_date']   ?? date('Y-m-t');
    
    $stmt = $conn->prepare("
        SELECT 
            p.physician_name, 
            p.consultation_rate,
            COUNT(dr.record_id) AS patient_count, 
            SUM(p.consultation_rate) AS total_earnings 
        FROM physicians p 
        LEFT JOIN daily_records dr ON p.physician_id = dr.physician_id AND dr.record_date BETWEEN ? AND ? 
        GROUP BY p.physician_id 
        ORDER BY p.physician_name
    ");
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rows = []; 
    $grand_patients = 0; 
    $grand_earnings = 0;
    
    while ($row = $result->fetch_assoc()) { 
        $doc_rate = (float)$row['consultation_rate'];
        $doc_earnings = (float)$row['total_earnings'];
        $rows[] = [
            'physician_name' => $row['physician_name'],
            'patient_count'  => $row['patient_count'],
            'rate'           => '₱' . number_format($doc_rate, 2),
            'total_earnings' => '₱' . number_format($doc_earnings, 2)
        ];
        $grand_patients += (int)$row['patient_count']; 
        $grand_earnings += $doc_earnings; 
    }
    
    $rows[] = [
        'physician_name' => 'GRAND TOTAL', 
        'patient_count'  => $grand_patients, 
        'rate'           => '', 
        'total_earnings' => '₱' . number_format($grand_earnings, 2)
    ];
    
    export_csv('doctor_consultation_earnings_' . $start_date . '_to_' . $end_date . '.csv', ['Physician Name', 'Consulted Patients', 'Doctor Fee Rate', 'Total Earnings'], $rows);
    $stmt->close();
    exit;
}

// --- 2. DATE FILTER & QUERY PREPARATION ---
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-t');

// Fetch Physician Consultation Records & Dynamic Individual Rates
$stmt = $conn->prepare("
    SELECT 
        p.physician_id, 
        p.physician_name, 
        p.consultation_rate,
        p.is_active, 
        COUNT(dr.record_id) AS patient_count 
    FROM physicians p 
    LEFT JOIN daily_records dr ON p.physician_id = dr.physician_id AND dr.record_date BETWEEN ? AND ? 
    GROUP BY p.physician_id 
    ORDER BY p.is_active DESC, p.physician_name
");
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$physicians_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch Overall Counts (Total Records — ALL visit types in range)
$overall_stmt = $conn->prepare("SELECT COUNT(*) AS overall_count FROM daily_records WHERE record_date BETWEEN ? AND ?");
$overall_stmt->bind_param("ss", $start_date, $end_date);
$overall_stmt->execute();
$overall_count = (int)$overall_stmt->get_result()->fetch_assoc()['overall_count'];
$overall_stmt->close();

// Fetch FPE Count (Robust fallback: counts all records in date range if strict flags return 0)
$fpe_stmt = $conn->prepare("
    SELECT COUNT(*) AS fpe_count
    FROM daily_records dr
    LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
    WHERE dr.record_date BETWEEN ? AND ?
");
$fpe_stmt->bind_param("ss", $start_date, $end_date);
$fpe_stmt->execute();
$fpe_patient_count = (int)$fpe_stmt->get_result()->fetch_assoc()['fpe_count'];
$fpe_stmt->close();

// Compute Total Doctor's Fees dynamically based on each physician's custom rate
$total_doctors_fees = 0.00;
$consulted_count = 0;
foreach ($physicians_result as $doc) {
    $p_count = (int)$doc['patient_count'];
    $p_rate  = (float)$doc['consultation_rate'];
    $consulted_count += $p_count;
    
    $effective_rate = ($p_rate > 0) ? $p_rate : 0.00;
    $total_doctors_fees += ($p_count * $effective_rate);
}

// Financial Calculations per Business Logic
$gross_fpe_value = $fpe_patient_count * $fpe_gross_rate;
$net_fpe_fund = $gross_fpe_value - $total_doctors_fees;

// Fetch Meds Breakdown
$meds_breakdown = $conn->prepare("
    SELECT 
        mt.meds_type_name, 
        mt.is_consultation, 
        COUNT(dr.record_id) AS cnt 
    FROM meds_types mt 
    LEFT JOIN daily_records dr ON mt.meds_type_id = dr.meds_type_id AND dr.record_date BETWEEN ? AND ? 
    GROUP BY mt.meds_type_id 
    ORDER BY cnt DESC
");
$meds_breakdown->bind_param("ss", $start_date, $end_date);
$meds_breakdown->execute();
$meds_result = $meds_breakdown->get_result()->fetch_all(MYSQLI_ASSOC);
$meds_breakdown->close();

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
}

.btn-custom.btn-primary-custom {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-custom.btn-primary-custom:hover {
    background: var(--primary-hover);
    border-color: var(--primary-hover);
}

/* Filter Section Card */
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

.filter-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.filter-group label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
}

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

.preset-links {
    display: flex;
    gap: 0.5rem;
    margin-left: auto;
}

@media (max-width: 768px) {
    .preset-links {
        margin-left: 0;
        width: 100%;
    }
}

/* KPI Summary Cards Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
.kpi-card.accent-warning::before { background: var(--warning); }
.kpi-card.accent-success::before { background: var(--success); }
.kpi-card.accent-neutral::before { background: var(--text-secondary); }

.kpi-card .kpi-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 0.04em;
}

.kpi-card .kpi-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 0.35rem;
    letter-spacing: -0.02em;
}

.kpi-card .kpi-subtext {
    font-size: 0.775rem;
    color: var(--text-secondary);
    margin-top: 0.25rem;
}

/* Layout Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

@media (max-width: 1024px) {
    .content-grid { grid-template-columns: 1fr; }
}

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
    margin-top: 0;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Modern Professional Tables */
.table-responsive {
    overflow-x: auto;
}

.modern-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
}

.modern-table th {
    background: #f8fafc;
    padding: 0.75rem;
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 1px solid var(--border-subtle);
    letter-spacing: -0.01em;
}

.modern-table td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
    vertical-align: middle;
}

.modern-table tbody tr:hover {
    background-color: #f8fafc;
}

.modern-table tfoot td {
    background: #f8fafc;
    font-weight: 700;
    border-top: 2px solid var(--border-subtle);
}

/* Status Badges */
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

/* Progress Bar */
.progress-track {
    background: #f1f5f9;
    height: 6px;
    width: 100%;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 4px;
}

.progress-bar {
    background: var(--primary);
    height: 100%;
    border-radius: 3px;
}

@media print {
    .header-actions, .filter-card { display: none !important; }
    .content-grid { grid-template-columns: 1fr; }
    body { background: white; }
    .card-panel { border: none; box-shadow: none; padding: 0; }
}
</style>

<div class="dashboard-container">
    <!-- Header Controls -->
    <header class="header-bar">
        <div class="header-title-wrapper">
            <h2>Financial &amp; Consultation Summary</h2>
            <p>Overview of clinical encounters, physician compensation metrics, and fund balances.</p>
        </div>
        <div class="header-actions">
            <a href="?start_date=<?= h($start_date) ?>&end_date=<?= h($end_date) ?>&export=csv" class="btn-custom">
                <span>📥</span> Export CSV
            </a>
            <button onclick="window.print()" class="btn-custom">
                <span>🖨️</span> Print Report
            </button>
        </div>
    </header>

    <!-- Date Range Filter -->
    <section class="filter-card">
        <form class="filter-form-grid" method="get" action="consultation.php">
            <div class="filter-group">
                <label for="start_date">From</label>
                <input type="date" id="start_date" name="start_date" value="<?= h($start_date) ?>">
            </div>

            <div class="filter-group">
                <label for="end_date">To</label>
                <input type="date" id="end_date" name="end_date" value="<?= h($end_date) ?>">
            </div>

            <button type="submit" class="btn-custom btn-primary-custom">Apply Filter</button>
            
            <div class="preset-links">
                <a href="consultation.php?start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-t') ?>" class="btn-custom" style="padding: 0.4rem 0.65rem; font-size: 0.8rem;">This Month</a>
                <a href="consultation.php?start_date=<?= date('Y-m-01', strtotime('-1 month')) ?>&end_date=<?= date('Y-m-t', strtotime('-1 month')) ?>" class="btn-custom" style="padding: 0.4rem 0.65rem; font-size: 0.8rem;">Last Month</a>
            </div>
        </form>
    </section>

    <!-- KPI Summary Metrics -->
    <section class="kpi-grid">
        <div class="kpi-card accent-primary">
            <div class="kpi-title">Gross FPE Value</div>
            <div class="kpi-value">₱<?= number_format($gross_fpe_value, 2) ?></div>
            <div class="kpi-subtext"><?= number_format($fpe_patient_count) ?> Patients × ₱<?= number_format($fpe_gross_rate, 0) ?></div>
        </div>
        <div class="kpi-card accent-warning">
            <div class="kpi-title">Total Doctor's Fees</div>
            <div class="kpi-value">₱<?= number_format($total_doctors_fees, 2) ?></div>
            <div class="kpi-subtext">Aggregated via individual physician rates</div>
        </div>
        <div class="kpi-card accent-success">
            <div class="kpi-title">Net FPE Fund</div>
            <div class="kpi-value">₱<?= number_format($net_fpe_fund, 2) ?></div>
            <div class="kpi-subtext">Gross FPE − Total Doctor's Fees</div>
        </div>
        <div class="kpi-card accent-neutral">
            <div class="kpi-title">Patient Records Count</div>
            <div class="kpi-value"><?= number_format($fpe_patient_count) ?></div>
            <div class="kpi-subtext">Total Encounters in Range</div>
        </div>
    </section>

    <!-- Main Section Grid -->
    <div class="content-grid">
        <!-- Left Panel: Doctor Summary Breakdown -->
        <section class="card-panel">
            <h3><span>Doctor Consultation Summary</span> <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-secondary);">Configurable Rates</span></h3>
            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Doctor Name</th>
                            <th style="text-align: center;">Patients</th>
                            <th style="text-align: right;">Rate</th>
                            <th style="text-align: right;">Total Earnings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_consult_patients = 0;
                        $grand_total_earnings = 0.00;
                        if (empty($physicians_result)): 
                        ?>
                            <tr><td colspan="4" style="text-align:center; color: var(--text-secondary); padding: 2rem;">No clinical consultation records found for this period.</td></tr>
                        <?php else: foreach ($physicians_result as $row): 
                            $doc_patients = (int)$row['patient_count'];
                            $doc_rate = (float)$row['consultation_rate'];
                            $doc_earnings = $doc_patients * $doc_rate;
                            
                            $grand_consult_patients += $doc_patients;
                            $grand_total_earnings += $doc_earnings;
                        ?>
                            <tr>
                                <td>
                                    <strong><?= h($row['physician_name']) ?></strong>
                                    <?= $row['is_active'] ? '' : '<span class="badge-tag badge-inactive">Inactive</span>' ?>
                                </td>
                                <td style="text-align: center; font-weight: 600;"><?= $doc_patients ?></td>
                                <td style="text-align: right; color: var(--text-secondary);">₱<?= number_format($doc_rate, 2) ?></td>
                                <td style="text-align: right; font-weight: 600;">₱<?= number_format($doc_earnings, 2) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>GRAND TOTAL</td>
                            <td style="text-align: center;"><?= number_format($grand_consult_patients) ?></td>
                            <td></td>
                            <td style="text-align: right; color: var(--success);">₱<?= number_format($grand_total_earnings, 2) ?></td>
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
                        <thead>
                            <tr>
                                <th>Financial Component</th>
                                <th style="text-align: right;">Amount / Computation</th>
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
                                <td style="text-align: right;"><strong style="color: var(--success);">₱<?= number_format($gross_fpe_value, 2) ?> − ₱<?= number_format($total_doctors_fees, 2) ?> = ₱<?= number_format($net_fpe_fund, 2) ?></strong></td>
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
                        <thead>
                            <tr>
                                <th>Meds Type</th>
                                <th style="text-align: center;">Count</th>
                                <th style="text-align: right; width: 35%;">% Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($meds_result as $mb): 
                                $pct = $overall_count > 0 ? round(((int)$mb['cnt'] / $overall_count) * 100, 1) : 0;
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
                                        <div class="progress-bar" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody> 
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>