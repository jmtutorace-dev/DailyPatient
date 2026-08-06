<?php
/**
 * YAKAP GAMOT SYSTEM - Patient History Fragment (patient_history.php)
 *
 * Returns an HTML fragment (timeline of a patient's visits) intended to be
 * injected into a modal via fetch(). NOT a full page.
 *
 * Query param: ?patient_name=...
 */
require_once 'config.php';
require_once 'includes/auth.php';

// Capture and trim the patient name from the query string.
// We NEVER concatenate this into SQL; it is used only as a prepared-statement bound value.
$patient_name = trim($_GET['patient_name'] ?? '');

// --- Gather records for this patient across all dates ---
$records = [];

if ($patient_name !== '') {
    $stmt = $conn->prepare("
        SELECT
            dr.record_date,
            dr.patient_name,
            dr.has_meds,
            dr.has_labs,
            dr.has_gamot_meds,
            p.physician_name,
            mt.meds_type_name,
            mt.is_consultation
        FROM daily_records dr
        LEFT JOIN physicians p ON dr.physician_id = p.physician_id
        LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
        WHERE dr.patient_name = ?
        ORDER BY dr.record_date ASC, dr.created_at ASC
    ");
    $stmt->bind_param('s', $patient_name);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    $stmt->close();
}

// --- Render output (HTML fragment only) ---
if (empty($records)): ?>
    <div class="empty-state-block">
        <div class="empty-state-icon" aria-hidden="true">🗂️</div>
        <p class="empty-state-message">No visit records found for <strong><?= h($patient_name) ?></strong>.</p>
        <p class="empty-state-hint">This patient has no logged FPE or consultation entries.</p>
    </div>
<?php else: ?>
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
            <div class="tl-sub">
                🩺 <?= h($physician) ?>
            </div>
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
<?php endif; ?>
