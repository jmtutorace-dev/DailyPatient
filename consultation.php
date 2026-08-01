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

// Fetch Overall Counts (Total FPE / Overall Records)
$overall_stmt = $conn->prepare("SELECT COUNT(*) AS overall_count FROM daily_records WHERE record_date BETWEEN ? AND ?");
$overall_stmt->bind_param("ss", $start_date, $end_date);
$overall_stmt->execute();
$overall_count = (int)$overall_stmt->get_result()->fetch_assoc()['overall_count'];
$overall_stmt->close();

// Compute Total Doctor's Fees dynamically based on each physician's custom rate assigned in physicians.php
$total_doctors_fees = 0.00;
$consulted_count = 0;
foreach ($physicians_result as $doc) {
    $p_count = (int)$doc['patient_count'];
    $p_rate  = (float)$doc['consultation_rate'];
    $consulted_count += $p_count;
    $total_doctors_fees += ($p_count * $p_rate);
}

// Financial Calculations per Business Logic
$gross_fpe_value = $overall_count * $fpe_gross_rate;
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
/* Modern Dashboard UI Overrides */
:root {
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --bg-card: #ffffff;
    --border-subtle: #e2e8f0;
    --text-primary: #0f172a;
    --text-secondary: #64748b;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
}

.dashboard-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0.5rem;
}

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

.filter-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}

.filter-form-grid {
    display: flex;
    align-items: center;
    gap: 1rem;
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
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-subtle);
    border-radius: 6px;
    font-size: 0.875rem;
}

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
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 0.25rem;
}

.kpi-card .kpi-subtext {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.2rem;
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

@media (max-width: 900px) {
    .content-grid { grid-template-columns: 1fr; }
}

.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    margin-bottom: 1.5rem;
}

.card-panel h3 {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-top: 0;
    margin-bottom: 1rem;
}

.modern-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.modern-table th {
    background: #f8fafc;
    padding: 0.65rem 0.75rem;
    text-align: left;
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 1px solid var(--border-subtle);
}

.modern-table td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
}

.modern-table tfoot td {
    background: #f8fafc;
    font-weight: 700;
    border-top: 2px solid var(--border-subtle);
}

.badge-tag {
    display: inline-block;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    margin-left: 0.3rem;
}

.badge-success { background: #d1fae5; color: #065f46; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-inactive { background: #f1f5f9; color: #64748b; }

.progress-track {
    background: #f1f5f9;
    height: 6px;
    width: 100%;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 3px;
}

.progress-bar {
    background: var(--primary);
    height: 100%;
}

@media print {
    .header-actions, .filter-card { display: none !important; }
    .content-grid { grid-template-columns: 1fr; }
}
</style>

<div class="dashboard-container">
    <!-- Header Controls -->
    <header class="header-bar">
        <h2>📊 Financial &amp; Consultation Summary</h2>
        <div class="header-actions">
            <a href="?start_date=<?= h($start_date) ?>&end_date=<?= h($end_date) ?>&export=csv" class="btn btn-blue btn-sm">📥 Export CSV</a>
            <button onclick="window.print()" class="btn btn-sm btn-outline">🖨️ Print Report</button>
        </div>
    </header>

    <!-- Date Range Filter -->
    <section class="filter-card">
        <form class="filter-form-grid" method="get" action="consultation.php">
            <div class="filter-group">
                <label for="start_date">From:</label>
                <input type="date" id="start_date" name="start_date" value="<?= h($start_date) ?>">
            </div>

            <div class="filter-group">
                <label for="end_date">To:</label>
                <input type="date" id="end_date" name="end_date" value="<?= h($end_date) ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            
            <div style="margin-left: auto;">
                <a href="consultation.php?start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-t') ?>" class="btn btn-sm btn-outline">This Month</a>
                <a href="consultation.php?start_date=<?= date('Y-m-01', strtotime('-1 month')) ?>&end_date=<?= date('Y-m-t', strtotime('-1 month')) ?>" class="btn btn-sm btn-outline">Last Month</a>
            </div>
        </form>
    </section>

    <!-- KPI Summary Metrics (Per Required Breakdown) -->
    <section class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-title">Gross FPE Value</div>
            <div class="kpi-value" style="color: var(--primary);">₱<?= number_format($gross_fpe_value, 2) ?></div>
            <div class="kpi-subtext"><?= number_format($overall_count) ?> Patients × ₱<?= number_format($fpe_gross_rate, 0) ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Total Doctor's Fees</div>
            <div class="kpi-value" style="color: var(--warning);">₱<?= number_format($total_doctors_fees, 2) ?></div>
            <div class="kpi-subtext">Aggregated via individual physician rates</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Net FPE Fund</div>
            <div class="kpi-value" style="color: var(--success);">₱<?= number_format($net_fpe_fund, 2) ?></div>
            <div class="kpi-subtext">Gross FPE − Total Doctor's Fees</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-title">Patient Records Count</div>
            <div class="kpi-value"><?= number_format($overall_count) ?></div>
            <div class="kpi-subtext">Total FPE Encounters</div>
        </div>
    </section>

    <!-- Main Section Grid -->
    <div class="content-grid">
        <!-- Left Panel: Doctor Summary Breakdown -->
        <section class="card-panel">
            <h3>Doctor Consultation Summary (Configurable Rates)</h3>
            <div style="overflow-x: auto;">
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
                            <tr><td colspan="4" style="text-align:center; color: var(--text-secondary);">No records found.</td></tr>
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
                            <td style="text-align: right;"><strong><?= number_format($overall_count) ?> × ₱<?= number_format($fpe_gross_rate, 2) ?> = ₱<?= number_format($gross_fpe_value, 2) ?></strong></td>
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
            </section>

            <!-- Meds Type Breakdown -->
            <section class="card-panel">
                <h3>Breakdown by Meds Type</h3>
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
                                <div><?= $pct ?>%</div>
                                <div class="progress-track">
                                    <div class="progress-bar" style="width: <?= $pct ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody> 
                </table>
            </section>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>