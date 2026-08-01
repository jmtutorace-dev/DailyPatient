<?php
/**
 * YAKAP GAMOT SYSTEM - Dashboard (dashboard.php)
 */

require_once 'config.php';
require_once 'includes/auth.php';

$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');
$current_year = (int)date('Y');

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM daily_records WHERE record_date = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$patients_today = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM daily_records WHERE record_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();
$patients_month = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM daily_records dr LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id WHERE dr.record_date BETWEEN ? AND ? AND (dr.physician_id IS NULL OR mt.is_consultation = 0)");
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

$recent = $conn->query("SELECT dr.*, p.physician_name, mt.meds_type_name FROM daily_records dr LEFT JOIN physicians p ON dr.physician_id = p.physician_id LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id ORDER BY dr.created_at DESC LIMIT 10");

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

<div class="stats-grid">
    <div class="stat-card green">
        <div class="stat-icon">📋</div>
        <div class="stat-number"><?= $patients_today ?></div>
        <div class="stat-label">Patients Today</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-icon">📅</div>
        <div class="stat-number"><?= $patients_month ?></div>
        <div class="stat-label">Patients This Month</div>
    </div>
    <div class="stat-card amber">
        <div class="stat-icon">📄</div>
        <div class="stat-number"><?= $fpe_count ?></div>
        <div class="stat-label">FPE / No Consultation</div>
    </div>
    <div class="stat-card red">
        <div class="stat-icon">⏳</div>
        <div class="stat-number"><?= $pending_transmits ?></div>
        <div class="stat-label">Pending Transmits</div>
    </div>
</div>

<div class="grid-2col">
    <div class="card">
        <h2>Last 7 Days</h2>
        <div class="chart-container">
            <svg viewBox="0 0 500 160" preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto;">
                <?php
                $bar_width = 60;
                $bar_gap = 10;
                $start_x = 20;
                $chart_height = 120;
                $base_y = 140;
                foreach ($chart_data as $i => $cd):
                    $bar_h = ($cd['count'] / $chart_max) * $chart_height;
                    $x = $start_x + $i * ($bar_width + $bar_gap);
                    $y = $base_y - $bar_h;
                ?>
                <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $bar_width ?>" height="<?= max($bar_h, 2) ?>" rx="3" fill="#1B4332" opacity="0.85">
                    <title><?= h(date('M j', strtotime($cd['date']))) ?>: <?= $cd['count'] ?> patient(s)</title>
                </rect>
                <text x="<?= $x + $bar_width/2 ?>" y="<?= $base_y + 12 ?>" text-anchor="middle" font-size="9" fill="#5A5A7A" font-family="'JetBrains Mono', monospace"><?= h(date('D', strtotime($cd['date']))) ?></text>
                <text x="<?= $x + $bar_width/2 ?>" y="<?= $y - 4 ?>" text-anchor="middle" font-size="10" fill="#1A1A2E" font-weight="700" font-family="'JetBrains Mono', monospace"><?= $cd['count'] ?></text>
                <?php endforeach; ?>
            </svg>
        </div>
    </div>

    <div class="card">
        <h2>Recent Activity</h2>
        <?php if ($recent->num_rows === 0): ?>
            <p class="empty-state">No recent records found.</p>
        <?php else: ?>
            <div class="timeline">
                <?php while ($r = $recent->fetch_assoc()): ?>
                <div class="timeline-item">
                    <div class="tl-date"><?= h(date('M j, Y g:i A', strtotime($r['created_at']))) ?></div>
                    <div class="tl-title"><?= h($r['patient_name']) ?></div>
                    <div class="tl-sub">
                        <?= h($r['physician_name'] ?? '&mdash;') ?>
                        <?php if ($r['meds_type_name']): ?> &middot; <span class="meds-type-label"><?= h($r['meds_type_name']) ?></span><?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2col">
    <div class="card">
        <h2>This Month by Physician</h2>
        <?php if ($md_result->num_rows === 0): ?>
            <p class="empty-state">No data for this month.</p>
        <?php else: ?>
            <div class="bar-chart">
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
                <div class="bar-item">
                    <span class="bar-label"><?= h($bar['physician_name']) ?></span>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= $width ?>%;"><?= (int)$bar['cnt'] ?></div>
                    </div>
                    <span style="font-family:'JetBrains Mono',monospace;font-size:0.75rem;color:var(--text-secondary);min-width:70px;text-align:right;">₱<?= number_format($total, 0) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Transmit Status &mdash; <?= h(date('F')) ?></h2>
        <div style="display:flex;flex-direction:column;gap:1rem;padding:0.5rem 0;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;background:var(--bg-app);border-radius:var(--radius-sm);">
                <span style="font-weight:600;">PCSF</span>
                <span class="status-pill <?= $pcsf_done ? 'done' : 'pending' ?>"><?= $pcsf_done ? '&#10003; Done' : '&#9675; Pending' ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;background:var(--bg-app);border-radius:var(--radius-sm);">
                <span style="font-weight:600;">SAP</span>
                <span class="status-pill <?= $sap_done ? 'done' : 'pending' ?>"><?= $sap_done ? '&#10003; Done' : '&#9675; Pending' ?></span>
            </div>
            <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                <a href="index.php?date=<?= h($today) ?>" class="btn btn-primary btn-sm">&#128203; Today's Log</a>
                <a href="consultation.php" class="btn btn-outline btn-sm">&#128202; Full Summary</a>
                <a href="transmit.php" class="btn btn-accent btn-sm">&#128229; Transmit Tracker</a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>