<?php
/**
 * YAKAP GAMOT SYSTEM - Patient Consultation (patient-consultation.php)
 *
 * Dedicated page for CONSULTATION visits (is_consultation = 1), separate from
 * the general daily FPE/intake log (index.php).
 *
 * This page focuses on ONE job: find a patient and LOG a new consultation.
 * Browsing/managing existing consultations lives on consultation-records.php.
 *
 * CORE RULE: a patient is only "consultation-eligible" if they already have at
 * least one daily_records row where meds_type_id IS NULL OR meds_types.is_consultation = 0
 * (i.e. an FPE/intake row entered through index.php). This rule is enforced:
 *   (a) server-side before any insert (never trust the GET flow),
 *   (b) for the autocomplete/datalist source (only eligible names are listed).
 */

require_once 'config.php';
require_once 'includes/auth.php';

// ============================================================
// AJAX: patient autocomplete search (live-search dropdown)
// Reuses the same consultation-eligibility logic as the rest of
// the page: only patients with at least one FPE/intake row appear.
// Short-circuits BEFORE any HTML output so the response is pure JSON.
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_patients') {
    $term = trim($_GET['term'] ?? '');

    // Return REGISTERED patients (per patient_is_registered()) so the dropdown
    // only offers names that can have consultations logged. When the term is
    // empty or shorter than 2 characters, return the default alphabetical list
    // (capped) so the dropdown has useful choices immediately on focus.
    if (mb_strlen($term) < 2) {
        $stmt = $conn->prepare("
            SELECT DISTINCT patient_name
            FROM daily_records
            WHERE patient_name IS NOT NULL
              AND patient_name != ''
            ORDER BY patient_name ASC
            LIMIT 50
        ");
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = ['patient_name' => $row['patient_name']];
        }
        $stmt->close();

        header('Content-Type: application/json');
        echo json_encode($rows);
        exit;
    }

    $like = '%' . $term . '%';

    $stmt = $conn->prepare("
        SELECT DISTINCT patient_name
        FROM daily_records
        WHERE patient_name LIKE ?
          AND patient_name IS NOT NULL
          AND patient_name != ''
        ORDER BY patient_name ASC
        LIMIT 10
    ");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = ['patient_name' => $row['patient_name']];
    }
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

/**
 * Decode a record_medications / record_gamot / record_labs cell into a
 * flat, de-duplicated list of item names. Safely handles JSON arrays,
 * plain comma-separated strings, or empty/null values.
 */
function decode_checkbox_items($data) {
    if (empty($data)) return [];

    // Try JSON first.
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            $data = $decoded;
        } else {
            // Fall back to comma-separated plain text.
            $data = array_map('trim', explode(',', $data));
        }
    }

    if (!is_array($data)) {
        $data = [$data];
    }

$clean = [];
    foreach ($data as $item) {
        $item = trim((string)$item);
        // Skip legacy "0" placeholders (from the past bind-type bug that stored
        // integer 0 instead of the JSON array) so old visits degrade gracefully
        // to just the plain checkmark rather than showing a bogus "0".
        if ($item !== '' && $item !== '0') {
            $clean[] = $item;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Reusable full-visit timeline renderer.
 *
 * Queries ALL daily_records rows for a patient (LEFT JOIN physicians + meds_types),
 * ordered by record_date ASC, created_at ASC, and echoes the timeline HTML fragment.
 *
 * Used by BOTH:
 *   (a) Section 1b of this page (when a patient is searched via ?q=), and
 *   (b) the ?ajax=patient_detail&patient_name=... endpoint (popup in consultation-records.php).
 *
 * This keeps the timeline markup/query in ONE place — no duplicated copies.
 *
* @param mysqli $conn         Active DB connection.
 * @param string $patient_name Patient whose visits to render (already trimmed).
 * @param bool   $show_delete  When true, render per-visit delete controls for
 *                             consultation-type rows (used by the popup so users
 *                             can remove a wrongly-logged consultation).
 */
function render_patient_timeline($conn, $patient_name, $show_delete = false) {
    $stmt = $conn->prepare("
        SELECT
            dr.record_id,
            dr.record_date,
            dr.patient_name,
            dr.has_meds,
            dr.has_labs,
            dr.has_gamot_meds,
            dr.record_medications,
            dr.record_labs,
            dr.record_gamot,
            dr.created_at,
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
    $res = $stmt->get_result();

    $visits = [];
    while ($row = $res->fetch_assoc()) {
        $visits[] = $row;
    }
    $stmt->close();

    if (empty($visits)) {
        echo '<div class="empty-state-block">';
        echo '  <div class="empty-icon" aria-hidden="true">🗂️</div>';
        echo '  <p class="empty-state-message">No visit history found for &ldquo;<strong>' . h($patient_name) . '</strong>&rdquo;.</p>';
        echo '</div>';
        return;
    }

    echo '<div class="timeline">';
    foreach ($visits as $visit) {
        $badge = !empty($visit['is_consultation']) ? 'badge-success' : 'badge-warning';
        $physician = $visit['physician_name'] ?? '— No physician —';

        // Date + time (only if created_at has a meaningful time component).
        $date_label = date('M j, Y', strtotime($visit['record_date']));
        $time_label = '';
        if (!empty($visit['created_at'])) {
            $ts = strtotime($visit['created_at']);
            // Show time only when it's not midnight (00:00:00) — i.e. a real time was recorded.
            if (date('H:i:s', $ts) !== '00:00:00') {
                $time_label = ', ' . date('g:i A', $ts);
            }
        }

$med_items = decode_checkbox_items($visit['record_medications'] ?? '');
        $lab_items = decode_checkbox_items($visit['record_labs'] ?? '');
        $gamot_items = decode_checkbox_items($visit['record_gamot'] ?? '');

        echo '<div class="timeline-item">';
        echo '  <div class="tl-date">' . h($date_label . $time_label) . '</div>';
        echo '  <div class="tl-title">';
        echo '      <span class="badge ' . $badge . '">' . h($visit['meds_type_name'] ?? '—') . '</span>';
        echo '  </div>';
        echo '  <div class="tl-sub">🩺 ' . h($physician) . '</div>';

        echo '  <div class="tl-sub tl-flags">';
        echo '      <span class="pc-flag ' . ($visit['has_meds'] ? 'pc-flag-yes' : 'pc-flag-no') . '">' . ($visit['has_meds'] ? '✓' : '—') . ' Meds</span>';
        echo '      <span class="pc-flag ' . ($visit['has_labs'] ? 'pc-flag-yes' : 'pc-flag-no') . '">' . ($visit['has_labs'] ? '✓' : '—') . ' Labs</span>';
        echo '      <span class="pc-flag ' . ($visit['has_gamot_meds'] ? 'pc-flag-yes' : 'pc-flag-no') . '">' . ($visit['has_gamot_meds'] ? '✓' : '—') . ' Gamot</span>';
        echo '  </div>';

        if (!empty($med_items)) {
            echo '  <div class="tl-detail"><span class="tl-detail-label">💊 Meds:</span> ' . h(implode(', ', $med_items)) . '</div>';
        }
        if (!empty($lab_items)) {
            echo '  <div class="tl-detail"><span class="tl-detail-label">🧪 Labs:</span> ' . h(implode(', ', $lab_items)) . '</div>';
        }
if (!empty($gamot_items)) {
            echo '  <div class="tl-detail"><span class="tl-detail-label">💜 Gamot:</span> ' . h(implode(', ', $gamot_items)) . '</div>';
        }

        // Per-visit delete control (consultation-type rows only, popup context).
        // Uses the hidden-form + form="id" pattern so no <form> nests inside a
        // table row (browsers silently drop them).
        if ($show_delete && !empty($visit['is_consultation'])) {
            $rid = (int)$visit['record_id'];
            $confirm_msg = 'Delete this consultation visit for ' . addslashes($patient_name) . '?';
            echo '  <div class="tl-delete-row">';
            echo '      <button type="submit" class="btn btn-danger btn-xs" form="delete-consult-' . $rid . '" onclick="return confirm(\'' . $confirm_msg . '\');" aria-label="Delete this consultation visit" title="Delete this consultation visit">🗑️ Delete this visit</button>';
            echo '  </div>';
        }

        echo '</div>';
    }
    echo '</div>';

    // Hidden per-visit delete forms (rendered OUTSIDE the timeline items so the
    // browser keeps them; nested <form> inside a <tr>/<tbody> would be dropped).
    if ($show_delete) {
        foreach ($visits as $visit) {
            if (!empty($visit['is_consultation'])) {
                $rid = (int)$visit['record_id'];
                echo '<form id="delete-consult-' . $rid . '" method="post" action="consultation-records.php" style="display:none;">';
                echo '  <input type="hidden" name="action" value="delete_consultation">';
                echo '  <input type="hidden" name="record_id" value="' . $rid . '">';
                echo '</form>';
            }
        }
    }
}

// ============================================================
// AJAX: patient detail (full visit timeline HTML fragment)
// Consumed by the "view history" popup in consultation-records.php.
// Short-circuits BEFORE any HTML output so the response is a clean
// bare fragment (no page chrome). Reuses render_patient_timeline().
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_detail') {
    $patient_name = trim($_GET['patient_name'] ?? '');

    if ($patient_name === '') {
        echo '<div class="empty-state-block"><div class="empty-icon" aria-hidden="true">🗂️</div><p class="empty-state-message">No patient specified.</p></div>';
        exit;
    }

    render_patient_timeline($conn, $patient_name, is_admin());
    exit;
}

// --- Read navigation parameters ---
$q = trim($_GET['q'] ?? '');
$msg = '';

// --- Handle POST: add a new consultation ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_viewer()) {
        set_flash('Viewer accounts can only view patient information. Editing is not allowed.', 'error');
        header('Location: patient-consultation.php' . (!empty($q) ? '?q=' . urlencode($q) : ''));
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_consultation') {
$record_date  = $_POST['record_date'] ?? '';
        $patient_name = normalize_patient_name($_POST['patient_name'] ?? '');
        $physician_id = !empty($_POST['physician_id']) ? (int)$_POST['physician_id'] : null;
        $meds_type_id = !empty($_POST['meds_type_id']) ? (int)$_POST['meds_type_id'] : null;
        $has_meds     = isset($_POST['has_meds']) ? 1 : 0;
        $has_labs     = isset($_POST['has_labs']) ? 1 : 0;
        $has_gamot_meds = isset($_POST['has_gamot_meds']) ? 1 : 0;

        $selected_meds  = ($has_meds && isset($_POST['medications']) && is_array($_POST['medications'])) ? json_encode(array_values($_POST['medications'])) : null;
        $selected_gamot = ($has_gamot_meds && isset($_POST['gamot_meds']) && is_array($_POST['gamot_meds'])) ? json_encode(array_values($_POST['gamot_meds'])) : null;
        $selected_labs  = ($has_labs && isset($_POST['labs']) && is_array($_POST['labs'])) ? json_encode(array_values($_POST['labs'])) : null;

        // The add form only offers consultation-type meds_types, but re-verify
        // so no FPE/non-consultation type can slip through.
        if ($meds_type_id !== null) {
            $check = $conn->prepare("SELECT meds_type_id FROM meds_types WHERE meds_type_id = ? AND is_consultation = 1");
            $check->bind_param('i', $meds_type_id);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                $meds_type_id = null;
            }
            $check->close();
        }

// CORE RULE: server-side eligibility check — never trust the GET flow.
        // A patient is consultation-eligible if they are registered (i.e. they
        // already have at least one daily_records row). Uses the shared
        // patient_is_registered() exclusively — no separate eligibility query.
        $eligible = ($patient_name !== '') && (patient_is_registered($conn, $patient_name) !== false);

        if (!$eligible) {
            $msg = "No record found for \"{$patient_name}\". This patient must be added through the Daily Log first before a consultation can be logged.";
        } elseif (empty($record_date) || empty($patient_name)) {
            $msg = 'Date and patient name are required.';
        } elseif (empty($physician_id)) {
            // A consultation must have an attending physician. 
            $msg = 'Please select a physician for this consultation.';
        } else {
            $stmt = $conn->prepare("INSERT INTO daily_records (record_date, patient_name, physician_id, meds_type_id, has_meds, record_medications, has_labs, record_labs, has_gamot_meds, record_gamot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
// NOTE: the checkbox/JSON pairs MUST be bound as "i,s" (flag=int, JSON=string).
            // Previously the JSON payloads were bound as integers, which silently cast
            // each JSON array to 0 — so consultation visits lost their specific med/lab
            // selections even though the has_meds/has_labs/has_gamot_meds flags saved fine.
            $stmt->bind_param('ssiiisisis', $record_date, $patient_name, $physician_id, $meds_type_id, $has_meds, $selected_meds, $has_labs, $selected_labs, $has_gamot_meds, $selected_gamot);

            if ($stmt->execute()) {
                // Keep autocomplete list in sync (mirror index.php logic)
                $sync = $conn->prepare("INSERT IGNORE INTO patients (patient_name) VALUES (?)");
                $sync->bind_param('s', $patient_name);
                $sync->execute();
                $sync->close();

                redirect_with_msg('patient-consultation.php?q=' . urlencode($patient_name), 'Consultation logged successfully.');
            } else {
                $msg = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// --- Patient eligibility check when q is present ---
// Uses the shared patient_is_registered() exclusively (no separate query).
$patient_eligible = false;
if ($q !== '') {
    $patient_eligible = (patient_is_registered($conn, $q) !== false);
}

include 'includes/header.php';
?>

<div class="page-header">
    <h2>🩺 Patient Consultations</h2>
    <div class="page-actions">
        <a href="consultation-records.php" class="btn btn-outline btn-sm">View All Consultations</a>
    </div>
</div>

<!-- ============ SECTION 1: FIND / SEARCH A PATIENT ============ -->
<div class="card">
    <div class="card-head">
        <span class="card-head-icon accent">
            <svg viewBox="0 0 20 20" width="20" height="20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><circle cx="10" cy="6" r="2.5"/><path d="M10 8.5V12"/><path d="M6.5 12h7"/><path d="M10 12v3.5"/><path d="M7 15.5h6"/></svg>
        </span>
        <h3>Find / Search a Patient</h3>
    </div>

    <form method="get" action="patient-consultation.php" class="filter-form" id="pc-search-form">
        <div class="ph-autocomplete">
            <input type="text" name="q" id="pc-q-input" value="<?= h($q) ?>" placeholder="Type patient name..." autocomplete="off" style="min-width: 320px;">
            <ul class="ph-autocomplete-list" id="pc-autocomplete-list" hidden></ul>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">🔍 Find</button>
        <?php if ($q !== ''): ?>
            <a href="patient-consultation.php" class="btn btn-sm btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($q !== ''): ?>
    <!-- ============ SECTION 1b: VISIT HISTORY TIMELINE ============ -->
    <div class="card">
        <div class="card-head">
            <span class="card-head-icon">
                <svg viewBox="0 0 20 20" width="20" height="20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><path d="M3 4h14"/><path d="M3 10h14"/><path d="M3 16h14"/><circle cx="7" cy="4" r="1"/><circle cx="7" cy="10" r="1"/><circle cx="7" cy="16" r="1"/></svg>
            </span>
            <h3>Visit History for &ldquo;<?= h($q) ?>&rdquo;</h3>
        </div>

        <?php if ($patient_eligible): ?>
            <?php render_patient_profile_summary($conn, $q); ?>
        <?php endif; ?>

        <?php render_patient_timeline($conn, $q); ?>
    </div>

    <?php if (!$patient_eligible): ?>
        <!-- Ineligible patient: show explanation, NO add form -->
        <div class="card">
            <div class="empty-state-block">
                <div class="empty-icon" aria-hidden="true">🚫</div>
                <p class="empty-state-message">No FPE record found for &ldquo;<strong><?= h($q) ?></strong>&rdquo;.</p>
                <p class="empty-state-hint">This patient must be added through the Daily Log first before a consultation can be logged.</p>
            </div>
        </div>
    <?php else: ?>
        <?php if (is_viewer()): ?>
            <div class="card" id="viewer-readonly-card">
                <div class="empty-state-block">
                    <div class="empty-icon" aria-hidden="true">🔒</div>
                    <p class="empty-state-message">Viewer mode is active.</p>
                    <p class="empty-state-hint">You can review patient details and consultation history, but cannot add or edit records.</p>
                </div>
            </div>
        <?php else: ?>
            <!-- ============ SECTION 2: LOG A NEW CONSULTATION ============ -->
            <div class="card" id="new-consultation-card">
                <div class="card-head">
                    <span class="card-head-icon accent">
                        <svg viewBox="0 0 20 20" width="20" height="20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><path d="M4 3h12v14H4z"/><path d="M8 2v2"/><path d="M12 2v2"/><path d="M7 10h6"/><path d="M7 13h4"/></svg>
                    </span>
                    <h3>Log New Consultation</h3>
                </div>

                <?php if (!empty($msg)): ?>
                    <div class="flash-message flash-error"><?= h($msg) ?></div>
                <?php endif; ?>

                <form method="post" action="patient-consultation.php" id="pc-add-form" onsubmit="var btn=this.querySelector('button[type=submit]'); if(btn){btn.disabled=true;btn.textContent='Saving…';}">
                    <input type="hidden" name="action" value="add_consultation">
                    <input type="hidden" name="q" value="<?= h($q) ?>">

                    <div class="grid-2col">
                        <div class="form-group">
                            <label for="pc_name">Patient Name *</label>
                            <input type="text" name="patient_name" id="pc_name" value="<?= h($q) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="pc_date">Record Date *</label>
                            <input type="date" name="record_date" id="pc_date" value="<?= h(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="pc_physician">Physician *</label>
                            <select name="physician_id" id="pc_physician" required>
                                <option value="">-- Select Physician --</option>
                                <?php foreach ($physicians_list as $doc): ?>
                                    <option value="<?= (int)$doc['physician_id'] ?>"><?= h($doc['physician_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="pc_meds_type">Meds Type *</label>
                            <select name="meds_type_id" id="pc_meds_type" required>
                                <?php foreach ($consultation_types as $ct): ?>
                                    <option value="<?= (int)$ct['meds_type_id'] ?>" <?= $default_meds_type_id === (int)$ct['meds_type_id'] ? 'selected' : '' ?>><?= h($ct['meds_type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" style="display:flex; gap:1rem; flex-wrap:wrap; margin-top:0.5rem;">
                        <label class="select-card-box" style="background:#fff;">
                            <input type="checkbox" name="has_meds" value="1" onchange="updateMedsListVisibility()"> Meds
                        </label>
                        <label class="select-card-box" style="background:#fff;">
                            <input type="checkbox" name="has_labs" value="1" onchange="toggleBox(this, 'pc-labs-box')"> Labs
                        </label>
                        <label class="select-card-box" style="background:#fff;">
                            <input type="checkbox" name="has_gamot_meds" value="1" onchange="toggleBox(this, 'pc-gamot-box')"> Gamot
                        </label>
                    </div>

                    <!-- Standard MEDS Selector -->
                    <div id="standard-meds-list" class="toggle-selection-container" hidden>
                        <div style="font-size: 0.75rem; font-weight: 600; color: var(--brand-primary); margin-bottom: 0.35rem;">Select Standard Medicines:</div>
                        <?php foreach ($standard_meds_grouped as $category => $meds): ?>
                            <div class="group-title"><?= h($category) ?></div>
                            <div class="items-grid">
                                <?php foreach ($meds as $smed): ?>
                                    <div class="select-card-box" onclick="toggleCard(this)">
                                        <input type="checkbox" name="medications[]" value="<?= h($smed) ?>">
                                        <span><?= h($smed) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- GAMOT Selector -->
                    <div id="pc-gamot-box" class="toggle-selection-container" style="border-color: #e9d5ff;" hidden>
                        <div style="font-size: 0.75rem; font-weight: 600; color: #7c3aed; margin-bottom: 0.35rem;">Select GAMOT Medicines (PhilHealth YAKAP List):</div>
                        <?php foreach ($available_medicines as $category => $meds): ?>
                            <div class="group-title gamot-title"><?= h($category) ?></div>
                            <div class="items-grid">
                                <?php foreach ($meds as $med): ?>
                                    <div class="select-card-box" onclick="toggleCard(this)">
                                        <input type="checkbox" name="gamot_meds[]" value="<?= h($med) ?>">
                                        <span><?= h($med) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- LABS Selector -->
                    <div id="pc-labs-box" class="toggle-selection-container" style="border-color: #a7f3d0;" hidden>
                        <div style="font-size: 0.75rem; font-weight: 600; color: #047857; margin-bottom: 0.35rem;">Select Laboratory Tests:</div>
                        <?php foreach ($available_labs as $category => $labs): ?>
                            <div class="group-title lab-title"><?= h($category) ?></div>
                            <div class="items-grid">
                                <?php foreach ($labs as $lab): ?>
                                    <div class="select-card-box" onclick="toggleCard(this)">
                                        <input type="checkbox" name="labs[]" value="<?= h($lab) ?>">
                                        <span><?= h($lab) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:flex-end; margin-top:0.75rem;">
                        <button type="submit" class="btn btn-primary btn-sm">💾 Save Consultation</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php
function get_patient_profile($conn, $patient_name) {
    $stmt = $conn->prepare("SELECT dr.record_date, dr.has_meds, dr.has_labs, dr.has_gamot_meds, dr.record_medications, dr.record_labs, dr.record_gamot, p.physician_name, mt.meds_type_name, mt.is_consultation FROM daily_records dr LEFT JOIN physicians p ON dr.physician_id = p.physician_id LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id WHERE dr.patient_name = ? ORDER BY dr.record_date ASC, dr.created_at ASC");
    $stmt->bind_param('s', $patient_name);
    $stmt->execute();
    $res = $stmt->get_result();

    $visits = [];
    while ($row = $res->fetch_assoc()) {
        $visits[] = $row;
    }
    $stmt->close();

    $profile = [
        'patient_name' => $patient_name,
        'total_visits' => count($visits),
        'first_visit' => $visits[0]['record_date'] ?? null,
        'last_visit' => $visits[count($visits) - 1]['record_date'] ?? null,
        'consultation_count' => 0,
        'last_consultation_type' => null,
        'doctors' => [],
        'med_items' => [],
        'lab_items' => [],
        'gamot_items' => [],
    ];

    foreach ($visits as $visit) {
        if (!empty($visit['is_consultation'])) {
            $profile['consultation_count']++;
            if (empty($profile['last_consultation_type'])) {
                $profile['last_consultation_type'] = $visit['meds_type_name'] ?: 'Consultation';
            }
        }

        if (!empty($visit['physician_name'])) {
            $profile['doctors'][$visit['physician_name']] = true;
        }

        foreach (decode_checkbox_items($visit['record_medications'] ?? '') as $item) {
            $profile['med_items'][] = $item;
        }
        foreach (decode_checkbox_items($visit['record_labs'] ?? '') as $item) {
            $profile['lab_items'][] = $item;
        }
        foreach (decode_checkbox_items($visit['record_gamot'] ?? '') as $item) {
            $profile['gamot_items'][] = $item;
        }
    }

    $profile['doctors'] = array_keys($profile['doctors']);
    $profile['med_items'] = array_values(array_unique($profile['med_items']));
    $profile['lab_items'] = array_values(array_unique($profile['lab_items']));
    $profile['gamot_items'] = array_values(array_unique($profile['gamot_items']));

    return $profile;
}

function render_patient_profile_summary($conn, $patient_name) {
    $profile = get_patient_profile($conn, $patient_name);
    if (empty($profile['total_visits'])) {
        return;
    }

    $first_visit = $profile['first_visit'] ? date('M j, Y', strtotime($profile['first_visit'])) : '—';
    $last_visit = $profile['last_visit'] ? date('M j, Y', strtotime($profile['last_visit'])) : '—';
    $doctors = $profile['doctors'];
    $doctor_text = !empty($doctors) ? implode(', ', $doctors) : '—';
    $med_summary = !empty($profile['med_items']) ? implode(', ', array_slice($profile['med_items'], 0, 6)) : 'No meds recorded';
    $lab_summary = !empty($profile['lab_items']) ? implode(', ', array_slice($profile['lab_items'], 0, 6)) : 'No labs recorded';
    $gamot_summary = !empty($profile['gamot_items']) ? implode(', ', array_slice($profile['gamot_items'], 0, 6)) : 'No gamot meds recorded';

    echo '<div style="background:linear-gradient(135deg,#f6fbf8 0%,#ffffff 100%);border:1px solid #dfe7e2;border-radius:16px;padding:1.2rem;margin-bottom:1.25rem;box-shadow:0 6px 18px rgba(27,67,50,0.05);">';
    echo '  <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">';
    echo '    <div>'; 
    echo '      <div style="font-size:0.72rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:#2d6a4f;">Patient Profile</div>';
    echo '      <h3 style="margin:0.2rem 0 0;font-size:1.5rem;color:#1a1a2e;">' . h($profile['patient_name']) . '</h3>';
    echo '    </div>';
    echo '    <span style="display:inline-flex;align-items:center;padding:0.42rem 0.8rem;border-radius:999px;background:rgba(27,67,50,0.08);color:#1b4332;font-size:0.8rem;font-weight:700;">' . (int)$profile['total_visits'] . ' total visits</span>';
    echo '  </div>';
    echo '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.9rem;">';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.85rem;">';
    echo '      <div style="font-size:0.72rem;text-transform:uppercase;color:#5a5a7a;font-weight:700;">First Visit</div>';
    echo '      <div style="margin-top:0.35rem;font-size:1.1rem;font-weight:700;color:#1a1a2e;">' . h($first_visit) . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.85rem;">';
    echo '      <div style="font-size:0.72rem;text-transform:uppercase;color:#5a5a7a;font-weight:700;">Last Visit</div>';
    echo '      <div style="margin-top:0.35rem;font-size:1.1rem;font-weight:700;color:#1a1a2e;">' . h($last_visit) . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.85rem;">';
    echo '      <div style="font-size:0.72rem;text-transform:uppercase;color:#5a5a7a;font-weight:700;">Consultations</div>';
    echo '      <div style="margin-top:0.35rem;font-size:1.1rem;font-weight:700;color:#1a1a2e;">' . (int)$profile['consultation_count'] . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.85rem;">';
    echo '      <div style="font-size:0.72rem;text-transform:uppercase;color:#5a5a7a;font-weight:700;">Last Type</div>';
    echo '      <div style="margin-top:0.35rem;font-size:1rem;font-weight:700;color:#1a1a2e;">' . h($profile['last_consultation_type'] ?: '—') . '</div>';
    echo '    </div>';
    echo '  </div>';
    echo '  <div style="margin-top:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.9rem;">';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.9rem;">';
    echo '      <div style="font-size:0.75rem;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;color:#5a5a7a;">Physicians</div>';
    echo '      <div style="margin-top:0.45rem;color:#1a1a2e;font-size:0.92rem;line-height:1.6;">' . h($doctor_text) . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.9rem;">';
    echo '      <div style="font-size:0.75rem;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;color:#5a5a7a;">Meds</div>';
    echo '      <div style="margin-top:0.45rem;color:#1a1a2e;font-size:0.92rem;line-height:1.6;">' . h($med_summary) . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.9rem;">';
    echo '      <div style="font-size:0.75rem;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;color:#5a5a7a;">Labs</div>';
    echo '      <div style="margin-top:0.45rem;color:#1a1a2e;font-size:0.92rem;line-height:1.6;">' . h($lab_summary) . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #edf1ee;border-radius:12px;padding:0.9rem;">';
    echo '      <div style="font-size:0.75rem;font-weight:800;letter-spacing:0.04em;text-transform:uppercase;color:#5a5a7a;">GAMOT</div>';
    echo '      <div style="margin-top:0.45rem;color:#1a1a2e;font-size:0.92rem;line-height:1.6;">' . h($gamot_summary) . '</div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
}
?>

<script>
// Toggle a full card selection box (mirror of index.php behavior)
function toggleCard(cardElement) {
    const cb = cardElement.querySelector('input[type="checkbox"]');
    if (cb) {
        cb.checked = !cb.checked;
        if (cb.checked) {
            cardElement.classList.add('active');
        } else {
            cardElement.classList.remove('active');
        }
    }
}

// Show/hide the options panel when a Meds/Labs/Gamot checkbox is toggled.
// NOTE: We use inline style.display here (like index.php) because the
// .toggle-selection-container CSS class hardcodes `display: none`. Toggling
// only the `hidden` attribute would NOT re-show the panel — the class rule
// would keep it hidden. Inline style overrides the class rule.
function toggleBox(checkbox, boxId) {
    const el = document.getElementById(boxId);
    if (el) {
        el.style.display = checkbox.checked ? 'block' : 'none';
    }
}

// Hide/show the Standard Medicines checklist based on the Meds checkbox.
// Uses inline style.display for the same reason as toggleBox above.
// Unchecking just hides the container; it does not clear individual
// medicine selections made underneath it.
function updateMedsListVisibility() {
    const medsBox = document.querySelector('input[name="has_meds"]');
    const list = document.getElementById('standard-meds-list');
    if (!list) return;

    list.style.display = (medsBox && medsBox.checked) ? 'block' : 'none';
}

// Apply the correct initial state on page load (e.g. if the form is
// re-rendered after a validation error with checkboxes pre-checked).
document.addEventListener('DOMContentLoaded', function() {
    updateMedsListVisibility();

    // Sync the Gamot and Labs boxes with their checkboxes on load.
    const gamotBox = document.querySelector('input[name="has_gamot_meds"]');
    if (gamotBox) toggleBox(gamotBox, 'pc-gamot-box');

    const labsBox = document.querySelector('input[name="has_labs"]');
    if (labsBox) toggleBox(labsBox, 'pc-labs-box');
});

(function() {
    'use strict';

    var input = document.getElementById('pc-q-input');
    if (!input) return;

    var container = input.closest('.ph-autocomplete');
    var list = document.getElementById('pc-autocomplete-list');
    var form = document.getElementById('pc-search-form');

    var debounceTimer = null;
    var requestSeq = 0; // guards against out-of-order responses

    function hideList() {
        list.hidden = true;
        list.innerHTML = '';
    }

    function renderItems(items) {
        list.innerHTML = '';

        if (items.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'ph-autocomplete-empty';
            empty.textContent = 'No matching patients';
            list.appendChild(empty);
            list.hidden = false;
            return;
        }

        items.forEach(function(item) {
            var li = document.createElement('li');
            li.className = 'ph-autocomplete-item';
            li.tabIndex = -1;

            var nameSpan = document.createElement('span');
            nameSpan.textContent = item.patient_name; // textContent, not innerHTML

            li.appendChild(nameSpan);

            if (item.last_visit) {
                var hint = document.createElement('span');
                hint.className = 'ph-autocomplete-hint';
                hint.textContent = 'Last visit: ' + item.last_visit;
                li.appendChild(hint);
            }

            li.addEventListener('click', function() {
                input.value = item.patient_name;
                hideList();
                if (form) form.submit();
            });

            list.appendChild(li);
        });

        list.hidden = false;
    }

function doSearch() {
        var value = input.value.trim();

        // For empty or short input, still fetch the default eligible list so
        // there is something useful to show (the server returns up to 50).
        var seq = ++requestSeq;

        fetch('patient-consultation.php?ajax=search_patients&term=' + encodeURIComponent(value))
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                // Ignore stale responses from older, slower requests.
                if (seq !== requestSeq) return;
                renderItems(data);
            })
            .catch(function(err) {
                console.error('Autocomplete search error:', err);
                if (seq === requestSeq) hideList();
            });
    }

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(doSearch, 250);
    });

    // Show the default eligible list when the field receives focus (empty input).
    input.addEventListener('focus', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(doSearch, 150);
    });

    input.addEventListener('keydown', function(e) {
        var items = list.querySelectorAll('.ph-autocomplete-item');
        var activeIndex = -1;

        items.forEach(function(item, i) {
            if (item.classList.contains('ph-autocomplete-active')) {
                activeIndex = i;
            }
        });

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (items.length === 0) return;
            var next = (activeIndex + 1) % items.length;
            items.forEach(function(item) { item.classList.remove('ph-autocomplete-active'); });
            items[next].classList.add('ph-autocomplete-active');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (items.length === 0) return;
            var prev = (activeIndex - 1 + items.length) % items.length;
            items.forEach(function(item) { item.classList.remove('ph-autocomplete-active'); });
            items[prev].classList.add('ph-autocomplete-active');
        } else if (e.key === 'Enter') {
            if (activeIndex > -1 && items[activeIndex]) {
                e.preventDefault();
                items[activeIndex].click();
            }
            // Otherwise fall through to normal form submit.
        } else if (e.key === 'Escape') {
            hideList();
        }
    });

    // Clicking anywhere outside hides the dropdown.
    document.addEventListener('click', function(e) {
        if (container && !container.contains(e.target)) {
            hideList();
        }
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
