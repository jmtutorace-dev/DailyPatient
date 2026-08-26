<?php
/**
 * YAKAP GAMOT SYSTEM - Consultation Records (consultation-records.php)
 *
 * Read-only ledger of EXISTING consultation records
 * (daily_records rows where meds_types.is_consultation = 1).
 *
 * This page reflects what was actually recorded for each visit — it is NOT a
 * data-entry surface. Editing/logging new visits happens on
 * patient-consultation.php. Here you can:
 *   - filter by physician + date range,
 *   - click a patient's name to view their full history on patient-consultation.php,
 *   - delete a consultation record (server-side re-verifies it is a
 *     consultation-type row before deleting — FPE/intake rows are never touched).
 */

require_once 'config.php';
require_once 'includes/auth.php';

// --- Read navigation parameters (GET, or preserved from POST redirects) ---
$filter_physician = isset($_GET['filter_physician']) ? (int)$_GET['filter_physician'] : (isset($_POST['filter_physician']) ? (int)$_POST['filter_physician'] : 0);
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : (isset($_POST['date_from']) ? trim($_POST['date_from']) : '');
$date_to   = isset($_GET['date_to']) ? trim($_GET['date_to']) : (isset($_POST['date_to']) ? trim($_POST['date_to']) : '');
$name_search = isset($_GET['name_search']) ? trim($_GET['name_search']) : (isset($_POST['name_search']) ? trim($_POST['name_search']) : '');
$q = trim($_GET['q'] ?? $_POST['q'] ?? '');

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// --- Reference data ---
$physicians_list = $conn->query("SELECT physician_id, physician_name FROM physicians WHERE is_active = 1 ORDER BY physician_name")->fetch_all(MYSQLI_ASSOC);

// --- 2nd Tranche encoding status ---
// IMPORTANT: This is an ADMINISTRATIVE ENCODING FLAG ONLY.
// It is NOT a visit, FPE, consultation, medicine, laboratory service,
// and it NEVER writes to daily_records.
//
// Status is stored in a small JSON file so this feature does not depend on
// any extra MySQL table and cannot affect visit counts.
$tranche_status_file = __DIR__ . DIRECTORY_SEPARATOR . 'yakap_2nd_tranche.json';

function load_second_tranche_status($file) {
    if (!is_file($file)) {
        return [];
    }

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    return $data;
}

function save_second_tranche_status($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return @file_put_contents($file, $json, LOCK_EX) !== false;
}

$second_tranche_status = load_second_tranche_status($tranche_status_file);

// Keep 2nd Tranche status tied to the normalized patient name so differences
// in capitalization/extra spaces do not break the status on index.php.
$normalized_second_tranche_status = [];
foreach ($second_tranche_status as $stored_name => $stored_value) {
    $normalized_key = mb_strtolower(normalize_patient_name((string)$stored_name));
    if ($normalized_key !== '') {
        $normalized_second_tranche_status[$normalized_key] = $stored_value;
    }
}
$second_tranche_status = $normalized_second_tranche_status;

// --- Handle POST: delete a consultation (consultation-type rows only) ---
// Safety: this page must NEVER delete an FPE/intake row. We re-verify
// server-side that the target row maps to is_consultation = 1 before
// touching anything; if that check fails we abort and delete nothing.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_second_tranche') {
        // Only authenticated non-viewers may change tranche status.
        if (is_viewer()) {
            redirect_with_msg('consultation-records.php', 'View-only users cannot change 2nd Tranche status.', 'error');
        }

        $patient_name = trim($_POST['patient_name'] ?? '');
        $second_tranche = isset($_POST['second_tranche']) ? (int)$_POST['second_tranche'] : 0;

        if ($patient_name !== '') {
            $patient_key = mb_strtolower(normalize_patient_name($patient_name));

            if ($second_tranche === 1) {
                // ENCODING ONLY. No daily_records INSERT/UPDATE occurs here.
                // Store the normalized patient key so index.php can recognize
                // the same patient even if capitalization/spacing differs.
                $second_tranche_status[$patient_key] = [
                    'encoded' => true,
                    'encoded_at' => date('Y-m-d H:i:s')
                ];
                save_second_tranche_status($tranche_status_file, $second_tranche_status);
            } else {
                // Remove only the encoding marker.
                unset($second_tranche_status[$patient_key]);
                save_second_tranche_status($tranche_status_file, $second_tranche_status);
            }
        }

        // Preserve the current filters/page after marking the patient.
        $back_params = [];
        if (!empty($_POST['filter_physician'])) { $back_params[] = 'filter_physician=' . (int)$_POST['filter_physician']; }
        if (!empty($_POST['date_from'])) { $back_params[] = 'date_from=' . urlencode($_POST['date_from']); }
        if (!empty($_POST['date_to'])) { $back_params[] = 'date_to=' . urlencode($_POST['date_to']); }
        if (!empty($_POST['name_search'])) { $back_params[] = 'name_search=' . urlencode($_POST['name_search']); }
        if (!empty($_POST['page'])) { $back_params[] = 'page=' . (int)$_POST['page']; }

        $back_url = 'consultation-records.php' . ($back_params ? '?' . implode('&', $back_params) : '');
        redirect_with_msg(
            $back_url,
            $second_tranche ? "\"{$patient_name}\" marked as 2nd Tranche." : "\"{$patient_name}\" removed from 2nd Tranche."
        );
    }

    if ($action === 'delete_consultation') {
        $record_id = (int)($_POST['record_id'] ?? 0);

        if ($record_id > 0) {
            // Guard: only genuine consultation-type rows pass this query.
            $check = $conn->prepare("
                SELECT dr.record_id, dr.patient_name, dr.record_date
                FROM daily_records dr
                JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
                WHERE dr.record_id = ?
                  AND mt.is_consultation = 1
            ");
            $check->bind_param("i", $record_id);
            $check->execute();
            $found = $check->get_result()->fetch_assoc();
            $check->close();

if (!$found) {
                redirect_with_msg('consultation-records.php', "That record can't be deleted from the consultation records page.");
            }

            $del = $conn->prepare("DELETE FROM daily_records WHERE record_id = ?");
            $del->bind_param("i", $record_id);
            $del->execute();
            $del->close();

// Preserve filters + page in the redirect so the user stays where they were.
            $back_params = [];
            if (!empty($_POST['filter_physician'])) { $back_params[] = 'filter_physician=' . (int)$_POST['filter_physician']; }
            if (!empty($_POST['date_from'])) { $back_params[] = 'date_from=' . urlencode($_POST['date_from']); }
            if (!empty($_POST['date_to'])) { $back_params[] = 'date_to=' . urlencode($_POST['date_to']); }
            if (!empty($_POST['name_search'])) { $back_params[] = 'name_search=' . urlencode($_POST['name_search']); }
            if (!empty($_POST['page'])) { $back_params[] = 'page=' . (int)$_POST['page']; }
            $back_url = 'consultation-records.php' . ($back_params ? '?' . implode('&', $back_params) : '');

            redirect_with_msg($back_url, 'Consultation record deleted.');
        } else {
            redirect_with_msg('consultation-records.php', "That record can't be deleted from the consultation records page.");
        }
    }
}

// --- Fetch consultation records (browsable, read-only list) ---
$where = ["mt.is_consultation = 1"];
$params = [];
$types = '';

if ($filter_physician > 0) {
    $where[] = "dr.physician_id = ?";
    $params[] = $filter_physician;
    $types .= 'i';
}
if ($date_from !== '') {
    $where[] = "dr.record_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to !== '') {
    $where[] = "dr.record_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}
if ($name_search !== '') {
    $where[] = "dr.patient_name LIKE ?";
    $params[] = '%' . $name_search . '%';
    $types .= 's';
}

$where_sql = implode(' AND ', $where);

// Count DISTINCT patients (one row per patient in the grouped ledger).
$count_sql = "
    SELECT COUNT(*) AS cnt
    FROM (
        SELECT dr.patient_name
        FROM daily_records dr
        LEFT JOIN physicians p ON dr.physician_id = p.physician_id
        LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
        WHERE $where_sql
        GROUP BY dr.patient_name
    ) grp
";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows = (int)$count_stmt->get_result()->fetch_assoc()['cnt'];
$count_stmt->close();

$total_pages = max(1, ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

// Grouped ledger: ONE row per patient, with a visit count, last visit date,
// the distinct consultation types they had, and "ever availed" indicators.
$data_sql = "
    SELECT
        dr.patient_name,
        COUNT(*) AS visit_count,
        MAX(dr.record_date) AS last_visit_date,
        GROUP_CONCAT(DISTINCT mt.meds_type_name ORDER BY mt.meds_type_name SEPARATOR ', ') AS types_list,
        COALESCE(MAX(p.physician_name), '') AS physician_name,
        MAX(dr.has_meds) AS has_meds,
        MAX(dr.has_labs) AS has_labs,
        MAX(dr.has_gamot_meds) AS has_gamot_meds
    FROM daily_records dr
    LEFT JOIN physicians p ON dr.physician_id = p.physician_id
    LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
    WHERE $where_sql
    GROUP BY dr.patient_name
    ORDER BY last_visit_date DESC, MAX(dr.created_at) DESC, dr.patient_name ASC
    LIMIT $per_page OFFSET $offset
";
$data_stmt = $conn->prepare($data_sql);
if (!empty($params)) {
    $data_stmt->bind_param($types, ...$params);
}
$data_stmt->execute();
$consult_rows = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$data_stmt->close();

// Apply the separate encoding marker after the consultation query.
// This cannot change COUNT(*) because it is not part of the SQL query.
foreach ($consult_rows as &$consult_row) {
    $patient_key = mb_strtolower(normalize_patient_name($consult_row['patient_name']));
    $consult_row['second_tranche'] = ($patient_key !== '' && isset($second_tranche_status[$patient_key])) ? 1 : 0;
}
unset($consult_row);

$has_filters = ($filter_physician > 0 || $date_from !== '' || $date_to !== '' || $name_search !== '');

include 'includes/header.php';
?>


<style>
/* 2nd Tranche visual status */
.cr-second-tranche-row td {
    background: #ecfdf5 !important;
    border-bottom-color: #a7f3d0 !important;
}
.cr-second-tranche-row:hover td {
    background: #d1fae5 !important;
}
.cr-second-tranche-name {
    color: #047857 !important;
    font-weight: 700;
}
.cr-second-tranche-btn {
    min-width: 76px;
}
.cr-second-tranche-btn.is-marked {
    background: #16a34a !important;
    border-color: #15803d !important;
    color: #fff !important;
    font-weight: 700;
}
</style>

<div class="page-header">
    <h2>🗂️ Consultation Records</h2>
    <div class="page-actions">
        <?php if (!is_viewer()): ?>
            <a href="patient-consultation.php" class="btn btn-primary btn-sm">+ Log New Consultation</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <span class="card-head-icon">
            <svg viewBox="0 0 20 20" width="20" height="20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><path d="M15.5 12.5a4 4 0 0 1-4 4"/><path d="M6.5 12.5a4 4 0 0 0 4 4"/><path d="M10 2v3"/><path d="M10 5h1.5a2 2 0 0 1 0 4H10"/><path d="M10 9h-1.5a2 2 0 0 0 0 4H10"/></svg>
        </span>
        <h3>Recent Consultations</h3>
    </div>

    <div class="toolbar-card">
        <form method="get" action="consultation-records.php" class="filter-form">
            <span style="font-weight:600; font-size:0.82rem; color:var(--text-secondary);">Filters:</span>
            <select name="filter_physician">
                <option value="0">All Physicians</option>
                <?php foreach ($physicians_list as $doc): ?>
                    <option value="<?= (int)$doc['physician_id'] ?>" <?= $filter_physician === (int)$doc['physician_id'] ? 'selected' : '' ?>><?= h($doc['physician_name']) ?></option>
                <?php endforeach; ?>
            </select>
<label for="cr-name-search">Patient:</label>
            <input type="text" name="name_search" id="cr-name-search" value="<?= h($name_search) ?>" placeholder="Search patient name...">
            <input type="date" name="date_from" value="<?= h($date_from) ?>">
            <input type="date" name="date_to" value="<?= h($date_to) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($has_filters): ?>
                <a href="consultation-records.php" class="btn btn-sm btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($consult_rows)): ?>
        <div class="empty-state"><?= $has_filters ? 'No consultations match your filters.' : 'No consultations logged yet.' ?>
            <?php if (!is_viewer()): ?>
                <a href="patient-consultation.php" class="btn btn-outline btn-sm" style="margin-left:0.5rem;">+ Log New Consultation</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
<thead>
<tr>
                        <th>Last Visit</th>
                        <th>Patient Name</th>
                        <th>Visits</th>
                        <th>Physician</th>
                        <th>Types</th>
                        <th>Ever Availed</th>
                        <th>Consultation</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($consult_rows as $row): ?>
                        <tr class="cr-clickable-row <?= !empty($row['second_tranche']) ? 'cr-second-tranche-row' : '' ?>" style="cursor:pointer;" onclick="openPatientDetail('<?= addslashes(h($row['patient_name'])) ?>')">
                            <td class="mono"><?= h(date('M j, Y', strtotime($row['last_visit_date']))) ?></td>
<td>
                                <span class="cr-patient-name <?= !empty($row['second_tranche']) ? 'cr-second-tranche-name' : '' ?>" title="View full history"><?= h($row['patient_name']) ?></span>
                            </td>
                            <td><span class="badge badge-success"><?= (int)$row['visit_count'] ?> visit(s)</span></td>
                            <td><?= h($row['physician_name'] ?: '— No physician —') ?></td>
                            <td><?= h($row['types_list'] ?: '—') ?></td>
                            <td>
                                <span class="pc-flag <?= $row['has_meds'] ? 'pc-flag-yes' : 'pc-flag-no' ?>"><?= $row['has_meds'] ? '✓' : '—' ?> Meds</span>
                                <span class="pc-flag <?= $row['has_labs'] ? 'pc-flag-yes' : 'pc-flag-no' ?>"><?= $row['has_labs'] ? '✓' : '—' ?> Labs</span>
                                <span class="pc-flag <?= $row['has_gamot_meds'] ? 'pc-flag-yes' : 'pc-flag-no' ?>"><?= $row['has_gamot_meds'] ? '✓' : '—' ?> Gamot</span>
                            </td>
<td onclick="event.stopPropagation()">
                                <div style="display:flex; gap:0.35rem; align-items:center; justify-content:flex-start; flex-wrap:wrap;">
                                    <?php if (is_viewer()): ?>
                                        <span class="badge badge-viewer" style="font-size:0.7rem;">Read Only</span>
                                    <?php else: ?>
                                        <form method="post" action="consultation-records.php" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_second_tranche">
                                            <input type="hidden" name="patient_name" value="<?= h($row['patient_name']) ?>">
                                            <input type="hidden" name="second_tranche" value="<?= !empty($row['second_tranche']) ? '0' : '1' ?>">
                                            <input type="hidden" name="filter_physician" value="<?= (int)$filter_physician ?>">
                                            <input type="hidden" name="name_search" value="<?= h($name_search) ?>">
                                            <input type="hidden" name="date_from" value="<?= h($date_from) ?>">
                                            <input type="hidden" name="date_to" value="<?= h($date_to) ?>">
                                            <input type="hidden" name="page" value="<?= (int)$page ?>">
                                            <button type="submit"
                                                    class="btn btn-sm cr-second-tranche-btn <?= !empty($row['second_tranche']) ? 'is-marked' : 'btn-outline' ?>"
                                                    title="<?= !empty($row['second_tranche']) ? 'Marked as 2nd Tranche — click to undo' : 'Mark this patient as 2nd Tranche' ?>">
                                                <?= !empty($row['second_tranche']) ? '✓ 2nd' : '2nd' ?>
                                            </button>
                                        </form>
                                        <a href="patient-consultation.php?q=<?= urlencode($row['patient_name']) ?>" class="btn btn-accent btn-sm">Consultation</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-row">
            <div>
<?php if ($page > 1): ?>
                    <a href="consultation-records.php?page=<?= $page-1 ?>&filter_physician=<?= $filter_physician ?>&name_search=<?= h(urlencode($name_search)) ?>&date_from=<?= h($date_from) ?>&date_to=<?= h($date_to) ?>" class="btn btn-sm btn-outline">← Prev</a>
                <?php endif; ?>
            </div>
            <span class="page-count">Page <?= $page ?> of <?= $total_pages ?></span>
            <div>
                <?php if ($page < $total_pages): ?>
                    <a href="consultation-records.php?page=<?= $page+1 ?>&filter_physician=<?= $filter_physician ?>&name_search=<?= h(urlencode($name_search)) ?>&date_from=<?= h($date_from) ?>&date_to=<?= h($date_to) ?>" class="btn btn-sm btn-outline">Next →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Patient Detail Modal (view full visit history) -->
<!-- Per-visit delete controls are rendered inside this modal's fetched fragment
     (see render_patient_timeline(..., $show_delete=true) in patient-consultation.php). -->
<div id="patient-detail-modal" class="ph-modal" hidden role="dialog" aria-modal="true" aria-labelledby="patient-detail-title">
    <div class="ph-backdrop" data-ph-close></div>
    <div class="ph-panel">
        <div class="ph-header">
            <span class="ph-title" id="patient-detail-title">Patient History</span>
            <button type="button" class="ph-close" id="patient-detail-close" aria-label="Close patient history">&times;</button>
        </div>
        <div class="ph-body" id="patient-detail-body">
            <div class="ph-loading">Loading patient history…</div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var modal = document.getElementById('patient-detail-modal');
    var body = document.getElementById('patient-detail-body');
    var title = document.getElementById('patient-detail-title');
    var closeBtn = document.getElementById('patient-detail-close');

    // Latest-request-wins guard: ignore stale responses from slower requests.
    var requestSeq = 0;

    function openPatientDetail(name) {
        if (!modal) return;

        // Set the title via textContent (never innerHTML — user-supplied string).
        title.textContent = name;

        // Loading state.
        body.innerHTML = '<div class="ph-loading">Loading patient history…</div>';

        // Show the modal.
        modal.hidden = false;

        // Move focus to the close button for accessibility.
        if (closeBtn) closeBtn.focus();

        var seq = ++requestSeq;

        fetch('patient-consultation.php?ajax=patient_detail&patient_name=' + encodeURIComponent(name))
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Request failed with status ' + response.status);
                }
                return response.text();
            })
            .then(function(html) {
                // Ignore stale responses.
                if (seq !== requestSeq) return;
                body.innerHTML = html;
            })
            .catch(function(err) {
                console.error('Patient detail error:', err);
                if (seq === requestSeq) {
                    body.innerHTML = '<div class="empty-state-block"><div class="empty-icon" aria-hidden="true">⚠️</div><p class="empty-state-message">Failed to load patient history.</p></div>';
                }
            });
    }

    function closePatientDetail() {
        if (modal) modal.hidden = true;
    }

    // Expose globally for the inline onclick handlers.
    window.openPatientDetail = openPatientDetail;

    // Close on backdrop click.
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal || e.target.classList.contains('ph-backdrop')) {
                closePatientDetail();
            }
        });
    }

    // Close on × button.
    if (closeBtn) {
        closeBtn.addEventListener('click', closePatientDetail);
    }

    // Close on Escape key.
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && !modal.hidden) {
            closePatientDetail();
        }
    });
})();
</script>
 
<?php include 'includes/footer.php'; ?>
