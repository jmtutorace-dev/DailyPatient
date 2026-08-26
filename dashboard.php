<?php
/**
 * YAKAP GAMOT SYSTEM - Dashboard (dashboard.php)
 * 
 * Fully redesigned to a minimal, professional healthcare dashboard aesthetic 
 * while preserving all original backend PHP logic, database queries, and functionality.
 */

require_once 'config.php';
require_once 'includes/auth.php';

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$current_year = (int)date('Y');

// Count DISTINCT patients, not total daily_records rows
$stmt = $conn->prepare("SELECT COUNT(DISTINCT LOWER(TRIM(patient_name))) AS cnt FROM daily_records WHERE record_date = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$patients_today = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(DISTINCT LOWER(TRIM(patient_name))) AS cnt FROM daily_records WHERE record_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();
$patients_month = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Canonical FPE condition
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM daily_records dr LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id WHERE dr.record_date BETWEEN ? AND ? AND (dr.meds_type_id IS NULL OR mt.is_consultation = 0)");
$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();
$fpe_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM transmit_log WHERE transmit_year = ? AND pcsf = 0 AND sap = 0");
$stmt->bind_param("i", $current_year);
$stmt->execute();
$pending_transmits = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$chart_data = [];
$chart_max = 1;
$chart_stmt = $conn->prepare("SELECT record_date, COUNT(*) AS cnt FROM daily_records WHERE record_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY record_date ORDER BY record_date ASC");
$chart_stmt->execute();
$chart_result = $chart_stmt->get_result();
$chart_by_date = [];
while ($c = $chart_result->fetch_assoc()) {
    $chart_by_date[$c['record_date']] = (int)$c['cnt'];
}
$chart_stmt->close();

for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $cnt = $chart_by_date[$d] ?? 0;
    $chart_data[] = ['date' => $d, 'count' => $cnt];
    if ($cnt > $chart_max) $chart_max = $cnt;
}
$chart_max = max($chart_max, 1);

$recent = $conn->query("SELECT dr.*, p.physician_name, mt.meds_type_name FROM daily_records dr LEFT JOIN physicians p ON dr.physician_id = p.physician_id LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id ORDER BY dr.created_at DESC LIMIT 5");

$md_stmt = $conn->prepare("SELECT p.physician_name, COUNT(dr.record_id) AS cnt, p.consultation_rate FROM physicians p LEFT JOIN daily_records dr ON p.physician_id = dr.physician_id AND dr.record_date BETWEEN ? AND ? GROUP BY p.physician_id ORDER BY cnt DESC");
$md_stmt->bind_param("ss", $month_start, $month_end);
$md_stmt->execute();
$md_result = $md_stmt->get_result();
$md_stmt->close();

$current_month = (int)date('m');
$tx_stmt = $conn->prepare("SELECT pcsf, sap FROM transmit_log WHERE transmit_year = ? AND transmit_month = ?");
$tx_stmt->bind_param("ii", $current_year, $current_month);
$tx_stmt->execute();
$tx_row = $tx_stmt->get_result()->fetch_assoc();
$tx_stmt->close();
$pcsf_done = $tx_row && (int)$tx_row['pcsf'] === 1;
$sap_done  = $tx_row && (int)$tx_row['sap'] === 1;

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

/* Page Header */
.dashboard-header {
    margin-bottom: 1.5rem;
}

.dashboard-header h2 {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.25rem 0;
}

.dashboard-header p {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin: 0;
}

/* Stats Metric Grid */
.stats-grid-custom {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card-custom {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: var(--shadow-sm);
    transition: border-color 0.15s ease;
}

.stat-card-custom:hover {
    border-color: #cbd5e1;
}

.stat-icon-wrapper {
    width: 42px;
    height: 42px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.stat-icon-wrapper.green { background: #ecfdf5; color: var(--success); }
.stat-icon-wrapper.blue { background: #f0f9ff; color: var(--info); }
.stat-icon-wrapper.amber { background: #fffbeb; color: var(--warning); }
.stat-icon-wrapper.red { background: #fef2f2; color: var(--danger); }

.stat-content {
    display: flex;
    flex-direction: column;
}

.stat-number-val {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
}

.stat-label-val {
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text-secondary);
    margin-top: 0.15rem;
}

/* Layout Grid */
.grid-2col-custom {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .grid-2col-custom {
        grid-template-columns: 1fr;
    }
}

.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    box-shadow: var(--shadow-sm);
}

.section-heading-custom {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.section-heading-custom h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 0.2rem 0;
}

.section-caption-custom {
    margin: 0;
    color: var(--text-secondary);
    font-size: 0.8rem;
}

/* Minimalist Buttons */
.btn-custom {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-subtle);
    background: var(--bg-card);
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    white-space: nowrap;
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

.btn-custom.btn-accent-custom {
    background: #0284c7;
    color: white;
    border-color: #0284c7;
}

.btn-custom.btn-accent-custom:hover {
    background: #0369a1;
    border-color: #0369a1;
    color: white;
}

/* Recent Activity List */
.recent-list-custom {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.recent-item-custom {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0;
    border-bottom: 1px solid var(--border-subtle);
}

.recent-item-custom:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.recent-icon-custom {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #ecfdf5;
    color: var(--success);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
}

.recent-main-custom {
    flex: 1;
    min-width: 0;
}

.recent-topline-custom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
}

.recent-patient-name {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.recent-date-val {
    font-size: 0.75rem;
    color: var(--text-secondary);
    white-space: nowrap;
}

.recent-meta-custom {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.1rem;
}

.recent-type-badge {
    color: var(--info);
    font-weight: 500;
}

/* Physician Bar Chart */
.bar-chart-custom {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.bar-item-custom {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.bar-label-custom {
    width: 140px;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.bar-track-custom {
    flex: 1;
    background: #f1f5f9;
    border-radius: 9999px;
    height: 18px;
    overflow: hidden;
    display: flex;
}

.bar-fill-custom {
    background: var(--primary);
    height: 100%;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    padding-left: 8px;
    font-size: 0.7rem;
    color: white;
    font-weight: 600;
}

.bar-amount-custom {
    font-size: 0.75rem;
    color: var(--text-secondary);
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    width: 70px;
    text-align: right;
}

/* Status Badges & Pills */
.status-pill-custom {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-pill-custom.done {
    background: #ecfdf5;
    color: var(--success);
}

.status-pill-custom.pending {
    background: #fffbeb;
    color: var(--warning);
}

.empty-state-custom {
    text-align: center;
    padding: 1.5rem;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

@media (max-width: 600px) {
    .dashboard-container { padding: 0.5rem; }
    .bar-label-custom { width: 100px; }
}
</style>

<div class="dashboard-container">
    <!-- Header / Introduction -->
    <header class="dashboard-header">
        <h2>Dashboard Overview</h2>
        <p>Real-time clinical metrics and daily operational summary.</p>
    </header>

    <!-- Top Metric Stat Cards -->
    <div class="stats-grid-custom">
        <div class="stat-card-custom">
            <div class="stat-icon-wrapper green">📋</div>
            <div class="stat-content">
                <span class="stat-number-val"><?= $patients_today ?></span>
                <span class="stat-label-val">Patients Today</span>
            </div>
        </div>
        <div class="stat-card-custom">
            <div class="stat-icon-wrapper blue">📅</div>
            <div class="stat-content">
                <span class="stat-number-val"><?= $patients_month ?></span>
                <span class="stat-label-val">Patients This Month</span>
            </div>
        </div>
        <div class="stat-card-custom">
            <div class="stat-icon-wrapper amber">📄</div>
            <div class="stat-content">
                <span class="stat-number-val"><?= $fpe_count ?></span>
                <span class="stat-label-val">FPE / No Consultation</span>
            </div>
        </div>
        <div class="stat-card-custom">
            <div class="stat-icon-wrapper red">⏳</div>
            <div class="stat-content">
                <span class="stat-number-val"><?= $pending_transmits ?></span>
                <span class="stat-label-val">Pending Transmits</span>
            </div>
        </div>
    </div>

    <!-- Main Content Section: Chart & Recent Activity -->
    <div class="grid-2col-custom">
        <!-- Last 7 Days Chart Card -->
        <div class="card-panel">
            <div class="section-heading-custom">
                <div>
                    <h3>Patient Volume Trend</h3>
                    <p class="section-caption-custom">Consultations over the last 7 days</p>
                </div>
            </div>
            <div style="margin-top: 1rem;">
                <svg viewBox="0 0 500 160" preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto;">
                    <?php
                    $bar_width = 50;
                    $bar_gap = 16;
                    $start_x = 25;
                    $chart_height = 110;
                    $base_y = 135;
                    foreach ($chart_data as $i => $cd):
                        $bar_h = ($cd['count'] / $chart_max) * $chart_height;
                        $x = $start_x + $i * ($bar_width + $bar_gap);
                        $y = $base_y - $bar_h;
                    ?>
                    <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $bar_width ?>" height="<?= max($bar_h, 2) ?>" rx="4" fill="#0f766e" opacity="0.9">
                        <title><?= h(date('M j', strtotime($cd['date']))) ?>: <?= $cd['count'] ?> patient(s)</title>
                    </rect>
                    <text x="<?= $x + $bar_width/2 ?>" y="<?= $base_y + 14 ?>" text-anchor="middle" font-size="10" fill="#64748b" font-weight="500"><?= h(date('D', strtotime($cd['date']))) ?></text>
                    <text x="<?= $x + $bar_width/2 ?>" y="<?= $y - 6 ?>" text-anchor="middle" font-size="10" fill="#1e293b" font-weight="700"><?= $cd['count'] ?></text>
                    <?php endforeach; ?>
                </svg>
            </div>
        </div>

        <!-- Recent Activity Card -->
        <div class="card-panel">
            <div class="section-heading-custom">
                <div>
                    <h3>Recent Activity</h3>
                    <p class="section-caption-custom">Latest 5 patient records</p>
                </div>
                <a href="index.php?date=<?= h($today) ?>" class="btn-custom">View All</a>
            </div>

            <?php if ($recent->num_rows === 0): ?>
                <div class="empty-state-custom">No recent records found.</div>
            <?php else: ?>
                <div class="recent-list-custom">
                    <?php while ($r = $recent->fetch_assoc()): ?>
                    <div class="recent-item-custom">
                        <div class="recent-icon-custom" aria-hidden="true">✓</div>
                        <div class="recent-main-custom">
                            <div class="recent-topline-custom">
                                <span class="recent-patient-name"><?= h($r['patient_name']) ?></span>
                                <span class="recent-date-val"><?= h(date('M j, g:i A', strtotime($r['created_at']))) ?></span>
                            </div>
                            <div class="recent-meta-custom">
                                <span><?= h($r['physician_name'] ?? '— No physician —') ?></span>
                                <?php if ($r['meds_type_name']): ?>
                                    <span>•</span>
                                    <span class="recent-type-badge"><?= h($r['meds_type_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Secondary Section: Physicians & Transmit Status -->
    <div class="grid-2col-custom">
        <!-- This Month by Physician Card -->
        <div class="card-panel">
            <div class="section-heading-custom">
                <div>
                    <h3>This Month by Physician</h3>
                    <p class="section-caption-custom">Patient distribution across medical staff</p>
                </div>
            </div>

            <?php if ($md_result->num_rows === 0): ?>
                <div class="empty-state-custom">No data for this month.</div>
            <?php else: ?>
                <div class="bar-chart-custom" style="margin-top: 1rem;">
                    <?php
                    $md_max = 1;
                    $md_bars = [];
                    while ($md = $md_result->fetch_assoc()) {
                        $md_bars[] = $md;
                        if ((int)$md['cnt'] > $md_max) $md_max = (int)$md['cnt'];
                    }
                    foreach ($md_bars as $bar):
                        $width = ((int)$bar['cnt'] / $md_max) * 100;
                        $total = (int)$bar['cnt'] * (float)$bar['consultation_rate'];
                    ?>
                    <div class="bar-item-custom">
                        <span class="bar-label-custom" title="<?= h($bar['physician_name']) ?>"><?= h($bar['physician_name']) ?></span>
                        <div class="bar-track-custom">
                            <div class="bar-fill-custom" style="width: max(<?= $width ?>%, 24px);"><?= (int)$bar['cnt'] ?></div>
                        </div>
                        <span class="bar-amount-custom">₱<?= number_format($total, 0) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Transmit Status Card -->
        <div class="card-panel">
            <div class="section-heading-custom">
                <div>
                    <h3>Transmit Status &mdash; <?= h(date('F')) ?></h3>
                    <p class="section-caption-custom">Monthly submission tracking status</p>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #f8fafc; border: 1px solid var(--border-subtle); border-radius: var(--radius-sm);">
                    <span style="font-weight: 600; font-size: 0.875rem;">PCSF</span>
                    <span class="status-pill-custom <?= $pcsf_done ? 'done' : 'pending' ?>"><?= $pcsf_done ? '&#10003; Completed' : '&#9675; Pending' ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #f8fafc; border: 1px solid var(--border-subtle); border-radius: var(--radius-sm);">
                    <span style="font-weight: 600; font-size: 0.875rem;">SAP</span>
                    <span class="status-pill-custom <?= $sap_done ? 'done' : 'pending' ?>"><?= $sap_done ? '&#10003; Completed' : '&#9675; Pending' ?></span>
                </div>

                <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <a href="index.php?date=<?= h($today) ?>" class="btn-custom btn-primary-custom">&#128203; Today's Log</a>
                    <a href="consultation.php" class="btn-custom">&#128202; Full Summary</a>
                    <a href="transmit.php" class="btn-custom btn-accent-custom">&#128229; Transmit Tracker</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>