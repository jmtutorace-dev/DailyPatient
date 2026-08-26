<?php
/**
 * YAKAP GAMOT SYSTEM - Patient Consultation (patient-consultation.php)
 *
 * Dedicated page for CONSULTATION visits (is_consultation = 1), separate from
 * the general daily FPE/intake log (index.php).
 *
 * This page focuses on ONE job: find a patient and LOG a new consultation.
 * Browsing/managing existing consultations lives on consultation-records.php.
 */

require_once 'config.php';
require_once 'includes/auth.php';

// ============================================================
// DATA INITIALIZATION
// Keep this page synchronized with the master lists used by index.php.
// ============================================================
if (!isset($physicians_list) || !is_array($physicians_list)) {
    $physicians_list = [];
    $p_res = $conn->query("SELECT physician_id, physician_name FROM physicians WHERE is_active = 1 ORDER BY physician_name ASC");
    if ($p_res) {
        while ($row = $p_res->fetch_assoc()) {
            $physicians_list[] = $row;
        }
    }
}

if (!isset($consultation_types) || !is_array($consultation_types)) {
    $consultation_types = [];
    $ct_res = $conn->query("SELECT meds_type_id, meds_type_name FROM meds_types WHERE is_consultation = 1 ORDER BY meds_type_name ASC");
    if ($ct_res) {
        while ($row = $ct_res->fetch_assoc()) {
            $consultation_types[] = $row;
        }
    }
}

$default_meds_type_id = $default_meds_type_id ?? (!empty($consultation_types) ? (int)$consultation_types[0]['meds_type_id'] : 0);

// Master list of Standard Meds (Grouped)
$standard_meds_grouped = [
    'Antihypertensives & Cardiovascular' => [
        'Amlodipine 5mg', 'Amlodipine 10mg', 'Losartan 50mg', 'Simvastatin 20mg', 'Atorvastatin 20mg'
    ],
    'Anti-Diabetes' => [
        'Metformin 500mg', 'Gliclazide 80mg'
    ],
    'Analgesics & Anti-Inflammatory' => [
        'Paracetamol 500mg', 'Ibuprofen 200mg', 'Mefenamic Acid 500mg'
    ],
    'Antibiotics' => [
        'Amoxicillin 500mg', 'Co-Amoxiclav 625mg', 'Ciprofloxacin 500mg', 'Cefalexin 500mg', 'Azithromycin 500mg'
    ],
    'Gastrointestinal, Respiratory & Antihistamines' => [
        'Omeprazole 20mg', 'Cetirizine 10mg', 'Salbutamol 2mg'
    ],
    'Vitamins & Supplements' => [
        'Ascorbic Acid 500mg', 'Multivitamins tab', 'Ferrous Sulfate'
    ]
];

// Master list of 54 PhilHealth YAKAP Essential Medicines (GAMOT)
$available_medicines = [
    'Anti-Infectives & Antibiotics' => [
        'Amoxicillin 500mg cap', 'Amoxicillin 250mg/5mL syrup', 'Co-Amoxiclav 625mg tab',
        'Co-Amoxiclav 228.5mg/5mL suspension', 'Co-trimoxazole 800mg/160mg tab', 'Co-trimoxazole 200mg/40mg/5mL suspension',
        'Ciprofloxacin 500mg tab', 'Azithromycin 500mg tab', 'Cefalexin 500mg cap', 'Cefalexin 250mg/5mL suspension',
        'Cefuroxime 500mg tab', 'Cefuroxime 250mg/5mL suspension', 'Clarithromycin 500mg tab', 'Metronidazole 500mg tab', 'Nitrofurantoin 100mg cap'
    ],
    'Antihypertensives & Cardiovascular' => [
        'Amlodipine 5mg tab', 'Amlodipine 10mg tab', 'Losartan 50mg tab', 'Losartan 100mg tab',
        'Enalapril 5mg tab', 'Enalapril 20mg tab', 'Metoprolol 50mg tab', 'Carvedilol 6.25mg tab',
        'Carvedilol 25mg tab', 'Hydrochlorothiazide 25mg tab', 'Furosemide 40mg tab', 'Simvastatin 20mg tab',
        'Atorvastatin 20mg tab', 'Aspirin 80mg tab', 'Clopidogrel 75mg tab'
    ],
    'Anti-Diabetes' => [
        'Metformin 500mg tab', 'Metformin 850mg tab', 'Gliclazide 80mg tab', 'Gliclazide 30mg MR tab', 'Glibenclamide 5mg tab'
    ],
    'Respiratory & Antiasthmatics' => [
        'Salbutamol 2mg tab', 'Salbutamol 2mg/5mL syrup', 'Salbutamol 100mcg Inhaler',
        'Fluticasone + Salmeterol Inhaler', 'Ipratropium + Salbutamol Nebule', 'Montelukast 10mg tab'
    ],
    'Analgesics, Antipyretics & Anti-Inflammatory' => [
        'Paracetamol 500mg tab', 'Paracetamol 250mg/5mL syrup', 'Paracetamol 120mg/5mL syrup',
        'Ibuprofen 200mg tab', 'Ibuprofen 400mg tab', 'Mefenamic Acid 500mg cap', 'Prednisone 5mg tab', 'Prednisone 20mg tab'
    ],
    'Gastrointestinal & Antihistamines' => [
        'Omeprazole 20mg cap', 'Ranitidine 150mg tab', 'Oral Rehydration Salts (ORS)', 'Cetirizine 10mg tab', 'Chlorphenamine 4mg tab'
    ]
];

// Master list of available laboratory tests
$available_labs = [
    'Blood Tests & Metabolic' => [
        'Complete blood count (CBC) with platelet count', 'Lipid profile (cholesterol and triglycerides)',
        'Fasting blood sugar (FBS)', 'Oral glucose tolerance test (OGTT)', 'Glycosylated hemoglobin (HbA1c)', 'Creatinine'
    ],
    'Imaging & Diagnostics' => [
        'Chest X-ray', 'Sputum microscopy', 'Electrocardiogram (ECG)'
    ],
    'Excretory & Screening' => [
        'Urinalysis', 'Pap smear', 'Fecalysis (stool exam)', 'Fecal occult blood test'
    ]
];

// ============================================================
// AJAX: patient autocomplete search (live-search dropdown)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_patients') {
    $term = trim($_GET['term'] ?? '');

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
 * Decode checkbox items safely.
 */
function decode_checkbox_items($data) {
    if (empty($data)) return [];

    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            $data = $decoded;
        } else {
            $data = array_map('trim', explode(',', $data));
        }
    }

    if (!is_array($data)) {
        $data = [$data];
    }

    $clean = [];
    foreach ($data as $item) {
        $item = trim((string)$item);
        if ($item !== '' && $item !== '0') {
            $clean[] = $item;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Reusable full-visit timeline renderer.
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
        echo '<div class="empty-state">';
        echo '  <div class="empty-state-icon" aria-hidden="true">🗂️</div>';
        echo '  <div class="empty-state-title">No visit history found</div>';
        echo '  <div class="empty-state-text">No prior medical records found for &ldquo;<strong>' . h($patient_name) . '</strong>&rdquo;.</div>';
        echo '</div>';
        return;
    }

    echo '<div class="healthcare-timeline">';
    foreach ($visits as $visit) {
        $badgeClass = !empty($visit['is_consultation']) ? 'badge-success' : 'badge-warning';
        $physician = $visit['physician_name'] ?? '— Unassigned Physician —';

        $date_label = date('M j, Y', strtotime($visit['record_date']));
        $time_label = '';
        if (!empty($visit['created_at'])) {
            $ts = strtotime($visit['created_at']);
            if (date('H:i:s', $ts) !== '00:00:00') {
                $time_label = 'at ' . date('g:i A', $ts);
            }
        }

        $med_items = decode_checkbox_items($visit['record_medications'] ?? '');
        $lab_items = decode_checkbox_items($visit['record_labs'] ?? '');
        $gamot_items = decode_checkbox_items($visit['record_gamot'] ?? '');

        echo '<div class="timeline-card">';
        echo '  <div class="timeline-header">';
        echo '      <div class="timeline-meta">';
        echo '          <span class="timeline-date">' . h($date_label) . '</span>';
        if (!empty($time_label)) {
            echo '          <span class="timeline-time text-muted">• ' . h($time_label) . '</span>';
        }
        echo '      </div>';
        echo '      <span class="badge ' . $badgeClass . '">' . h($visit['meds_type_name'] ?? 'Record') . '</span>';
        echo '  </div>';

        echo '  <div class="timeline-body">';
        echo '      <div class="timeline-physician"><strong>Physician:</strong> ' . h($physician) . '</div>';

        echo '      <div class="timeline-flags">';
        echo '          <span class="badge ' . ($visit['has_meds'] ? 'badge-info' : 'badge-subtle') . '">' . ($visit['has_meds'] ? '✓' : '—') . ' Standard Meds</span>';
        echo '          <span class="badge ' . ($visit['has_labs'] ? 'badge-info' : 'badge-subtle') . '">' . ($visit['has_labs'] ? '✓' : '—') . ' Labs</span>';
        echo '          <span class="badge ' . ($visit['has_gamot_meds'] ? 'badge-purple' : 'badge-subtle') . '">' . ($visit['has_gamot_meds'] ? '✓' : '—') . ' Gamot</span>';
        echo '      </div>';

        if (!empty($med_items)) {
            echo '  <div class="timeline-detail-row"><span class="detail-label">Meds:</span> <span class="detail-val">' . h(implode(', ', $med_items)) . '</span></div>';
        }
        if (!empty($lab_items)) {
            echo '  <div class="timeline-detail-row"><span class="detail-label">Labs:</span> <span class="detail-val">' . h(implode(', ', $lab_items)) . '</span></div>';
        }
        if (!empty($gamot_items)) {
            echo '  <div class="timeline-detail-row"><span class="detail-label">Gamot:</span> <span class="detail-val">' . h(implode(', ', $gamot_items)) . '</span></div>';
        }
        echo '  </div>';

        if ($show_delete && !empty($visit['is_consultation'])) {
            $rid = (int)$visit['record_id'];
            $confirm_msg = 'Delete this consultation visit for ' . addslashes($patient_name) . '?';
            echo '  <div class="timeline-footer">';
            echo '      <button type="submit" class="btn btn-danger-ghost btn-xs" form="delete-consult-' . $rid . '" onclick="return confirm(\'' . $confirm_msg . '\');">Delete Visit</button>';
            echo '  </div>';
        }

        echo '</div>';
    }
    echo '</div>';

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

// AJAX: patient detail endpoint
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_detail') {
    $patient_name = trim($_GET['patient_name'] ?? '');

    if ($patient_name === '') {
        echo '<div class="empty-state"><div class="empty-state-icon">🗂️</div><div class="empty-state-title">No patient specified</div></div>';
        exit;
    }

    render_patient_timeline($conn, $patient_name, is_admin());
    exit;
}

$q = trim($_GET['q'] ?? '');
$msg = '';

// Handle POST: add new consultation
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

        if ($meds_type_id !== null) {
            $check = $conn->prepare("SELECT meds_type_id FROM meds_types WHERE meds_type_id = ? AND is_consultation = 1");
            $check->bind_param('i', $meds_type_id);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                $meds_type_id = null;
            }
            $check->close();
        }

        $eligible = ($patient_name !== '') && (patient_is_registered($conn, $patient_name) !== false);

        if (!$eligible) {
            $msg = "No registration record found for \"{$patient_name}\". This patient must be logged in the Daily Intake system first.";
        } elseif (empty($record_date) || empty($patient_name)) {
            $msg = 'Record date and patient name are required fields.';
        } elseif (empty($physician_id)) {
            $msg = 'Please assign a physician for this consultation.';
        } else {
            $stmt = $conn->prepare("INSERT INTO daily_records (record_date, patient_name, physician_id, meds_type_id, has_meds, record_medications, has_labs, record_labs, has_gamot_meds, record_gamot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiiisisis', $record_date, $patient_name, $physician_id, $meds_type_id, $has_meds, $selected_meds, $has_labs, $selected_labs, $has_gamot_meds, $selected_gamot);

            if ($stmt->execute()) {
                $sync = $conn->prepare("INSERT IGNORE INTO patients (patient_name) VALUES (?)");
                $sync->bind_param('s', $patient_name);
                $sync->execute();
                $sync->close();

                redirect_with_msg('patient-consultation.php?q=' . urlencode($patient_name), 'Consultation successfully recorded.');
            } else {
                $msg = 'Database error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

$patient_eligible = false;
if ($q !== '') {
    $patient_eligible = (patient_is_registered($conn, $q) !== false);
}

include 'includes/header.php';
?>

<!-- Minimal Healthcare UI Custom Design Tokens & Stylesheet Overlay -->
<style>
:root {
    --hc-primary: #0284c7;
    --hc-primary-hover: #0369a1;
    --hc-surface: #ffffff;
    --hc-background: #f8fafc;
    --hc-border: #e2e8f0;
    --hc-text-main: #0f172a;
    --hc-text-muted: #64748b;
    --hc-success-bg: #f0fdf4;
    --hc-success-text: #166534;
    --hc-warning-bg: #fffbeb;
    --hc-warning-text: #92400e;
    --hc-danger-bg: #fef2f2;
    --hc-danger-text: #991b1b;
}

/* Page Header Layout */
.hc-page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.75rem;
    gap: 1rem;
    flex-wrap: wrap;
}
.hc-title {
    font-size: 1.375rem;
    font-weight: 700;
    color: var(--hc-text-main);
    letter-spacing: -0.01em;
    margin: 0 0 0.2rem 0;
}
.hc-subtitle {
    font-size: 0.875rem;
    color: var(--hc-text-muted);
    margin: 0;
}

/* Structural Card Styling */
.hc-card {
    background: var(--hc-surface);
    border: 1px solid var(--hc-border);
    border-radius: 10px;
    padding: 1.5rem;
    margin-bottom: 1.25rem;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.02);
}
.hc-card-header {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    margin-bottom: 1.25rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--hc-border);
}
.hc-card-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--hc-text-main);
    margin: 0;
}

/* Clean Form Controls */
.hc-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.hc-field {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}
.hc-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--hc-text-main);
}
.hc-input, .hc-select {
    width: 100%;
    padding: 0.5625rem 0.75rem;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.875rem;
    background: #fff;
    color: var(--hc-text-main);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.hc-input:focus, .hc-select:focus {
    outline: none;
    border-color: var(--hc-primary);
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
}
.hc-input[readonly] {
    background: #f1f5f9;
    color: #475569;
}

/* Selectable Item Cards & Toggles */
.select-card-box {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.65rem;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.8125rem;
    cursor: pointer;
    user-select: none;
    transition: all 0.1s ease;
}
.select-card-box:hover {
    border-color: #94a3b8;
    background: #f8fafc;
}
.select-card-box.is-selected {
    border-color: var(--hc-primary);
    background: #f0f9ff;
    color: #0369a1;
}

.toggle-selection-container {
    margin-bottom: 1.25rem;
    padding: 1rem;
    border: 1px solid var(--hc-border);
    border-radius: 8px;
    background: #f8fafc;
    display: none;
}

/* Status Badges */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.55rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 999px;
    white-space: nowrap;
}
.badge-success { background: var(--hc-success-bg); color: var(--hc-success-text); }
.badge-warning { background: var(--hc-warning-bg); color: var(--hc-warning-text); }
.badge-danger  { background: var(--hc-danger-bg); color: var(--hc-danger-text); }
.badge-info    { background: #e0f2fe; color: #0369a1; }
.badge-purple  { background: #f3e8ff; color: #6b21a8; }
.badge-subtle  { background: #f1f5f9; color: #64748b; }
.badge-neutral { background: #e2e8f0; color: #334155; }

/* Timeline UI */
.healthcare-timeline {
    display: flex;
    flex-direction: column;
    gap: 0.875rem;
}
.timeline-card {
    background: #fff;
    border: 1px solid var(--hc-border);
    border-radius: 8px;
    padding: 1rem;
}
.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}
.timeline-meta {
    font-size: 0.8125rem;
}
.timeline-date {
    font-weight: 600;
    color: var(--hc-text-main);
}
.timeline-body {
    font-size: 0.85rem;
    color: #334155;
}
.timeline-physician {
    margin-bottom: 0.35rem;
}
.timeline-flags {
    display: flex;
    gap: 0.4rem;
    margin: 0.5rem 0;
    flex-wrap: wrap;
}
.timeline-detail-row {
    margin-top: 0.35rem;
    font-size: 0.8125rem;
}
.detail-label {
    font-weight: 600;
    color: var(--hc-text-muted);
}
.timeline-footer {
    margin-top: 0.75rem;
    padding-top: 0.5rem;
    border-top: 1px solid #f1f5f9;
    display: flex;
    justify-content: flex-end;
}

/* Empty State Styling */
.empty-state {
    text-align: center;
    padding: 2.5rem 1rem;
}
.empty-state-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}
.empty-state-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--hc-text-main);
    margin-bottom: 0.2rem;
}
.empty-state-text {
    font-size: 0.8125rem;
    color: var(--hc-text-muted);
}

/* Buttons */
.btn-primary {
    background: var(--hc-primary);
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
    transition: background 0.15s ease;
}
.btn-primary:hover {
    background: var(--hc-primary-hover);
}
.btn-outline {
    background: #fff;
    color: var(--hc-text-main);
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    font-weight: 500;
    font-size: 0.875rem;
    cursor: pointer;
}
.btn-outline:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.btn-danger-ghost {
    background: transparent;
    color: #dc2626;
    border: 1px solid #fecaca;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    cursor: pointer;
}
.btn-danger-ghost:hover {
    background: #fef2f2;
}

/* CSS Fallback for selection visibility */
#pc-add-form #standard-meds-list,
#pc-add-form #pc-labs-box,
#pc-add-form #pc-gamot-box {
    display: none;
}
#pc-add-form:has(input[name="has_meds"]:checked) #standard-meds-list,
#pc-add-form:has(input[name="has_labs"]:checked) #pc-labs-box,
#pc-add-form:has(input[name="has_gamot_meds"]:checked) #pc-gamot-box {
    display: block !important;
}
</style>

<!-- Minimal Professional Healthcare Header -->
<div class="hc-page-header">
    <div>
        <h1 class="hc-title">Patient Consultations</h1>
        <p class="hc-subtitle">Search patient history and record clinical visits efficiently.</p>
    </div>
    <div>
        <a href="consultation-records.php" class="btn-outline">View All Consultations</a>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="hc-card">
    <div class="hc-card-header">
        <span style="color: var(--hc-primary);">
            <svg viewBox="0 0 20 20" width="18" height="18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><circle cx="10" cy="6" r="2.5"/><path d="M10 8.5V12"/><path d="M6.5 12h7"/><path d="M10 12v3.5"/><path d="M7 15.5h6"/></svg>
        </span>
        <h3 class="hc-card-title">Patient Quick Search</h3>
    </div>

    <form method="get" action="patient-consultation.php" id="pc-search-form" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <div class="ph-autocomplete" style="position: relative; flex: 1; min-width: 260px;">
            <input type="text" name="q" id="pc-q-input" value="<?= h($q) ?>" class="hc-input" placeholder="🔍 Search patient name..." autocomplete="off">
            <ul class="ph-autocomplete-list" id="pc-autocomplete-list" hidden style="position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); list-style: none; padding: 0; margin: 4px 0 0 0; z-index: 50; max-height: 200px; overflow-y: auto;"></ul>
        </div>
        <button type="submit" class="btn-primary">Search</button>
        <?php if ($q !== ''): ?>
            <a href="patient-consultation.php" class="btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($q !== ''): ?>
    <div class="hc-card">
        <div class="hc-card-header">
            <span style="color: var(--hc-primary);">
                <svg viewBox="0 0 20 20" width="18" height="18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><path d="M3 4h14"/><path d="M3 10h14"/><path d="M3 16h14"/><circle cx="7" cy="4" r="1"/><circle cx="7" cy="10" r="1"/><circle cx="7" cy="16" r="1"/></svg>
            </span>
            <h3 class="hc-card-title">Visit History for &ldquo;<?= h($q) ?>&rdquo;</h3>
        </div>

        <?php if ($patient_eligible): ?>
            <?php render_patient_profile_summary($conn, $q); ?>
        <?php endif; ?>

        <?php render_patient_timeline($conn, $q); ?>
    </div>

    <?php if (!$patient_eligible): ?>
        <div class="hc-card">
            <div class="empty-state">
                <div class="empty-state-icon">🚫</div>
                <div class="empty-state-title">No FPE record found for &ldquo;<?= h($q) ?>&rdquo;</div>
                <div class="empty-state-text">This patient must be added through the Daily Intake log first before logging a consultation.</div>
            </div>
        </div>
    <?php else: ?>
        <?php if (is_viewer()): ?>
            <div class="hc-card" id="viewer-readonly-card">
                <div class="empty-state">
                    <div class="empty-state-icon">🔒</div>
                    <div class="empty-state-title">Viewer Mode Active</div>
                    <div class="empty-state-text">You can review history and details, but adding or editing records is restricted.</div>
                </div>
            </div>
        <?php else: ?>
            <div class="hc-card" id="new-consultation-card">
                <div class="hc-card-header">
                    <span style="color: var(--hc-primary);">
                        <svg viewBox="0 0 20 20" width="18" height="18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><path d="M4 3h12v14H4z"/><path d="M8 2v2"/><path d="M12 2v2"/><path d="M7 10h6"/><path d="M7 13h4"/></svg>
                    </span>
                    <h3 class="hc-card-title">Log New Consultation</h3>
                </div>

                <?php if (!empty($msg)): ?>
                    <div style="padding: 0.75rem; background: var(--hc-danger-bg); color: var(--hc-danger-text); border-radius: 6px; font-size: 0.875rem; margin-bottom: 1rem;"><?= h($msg) ?></div>
                <?php endif; ?>

                <form method="post" action="patient-consultation.php" id="pc-add-form" onsubmit="var btn=this.querySelector('button[type=submit]'); if(btn){btn.disabled=true;btn.textContent='Saving…';}">
                    <input type="hidden" name="action" value="add_consultation">
                    <input type="hidden" name="q" value="<?= h($q) ?>">

                    <div class="hc-form-grid">
                        <div class="hc-field">
                            <label for="pc_name" class="hc-label">Patient Name *</label>
                            <input type="text" name="patient_name" id="pc_name" value="<?= h($q) ?>" readonly class="hc-input">
                        </div>
                        <div class="hc-field">
                            <label for="pc_date" class="hc-label">Record Date *</label>
                            <input type="date" name="record_date" id="pc_date" value="<?= h(date('Y-m-d')) ?>" required class="hc-input">
                        </div>
                        <div class="hc-field">
                            <label for="pc_physician" class="hc-label">Physician *</label>
                            <select name="physician_id" id="pc_physician" required class="hc-select">
                                <option value="">-- Select Physician --</option>
                                <?php foreach ($physicians_list as $doc): ?>
                                    <option value="<?= (int)$doc['physician_id'] ?>"><?= h($doc['physician_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="hc-field">
                            <label for="pc_meds_type" class="hc-label">Meds Type *</label>
                            <select name="meds_type_id" id="pc_meds_type" required class="hc-select">
                                <option value="">-- Select Meds Type --</option>
                                <?php foreach ($consultation_types as $ct): ?>
                                    <option value="<?= (int)$ct['meds_type_id'] ?>" <?= $default_meds_type_id === (int)$ct['meds_type_id'] ? 'selected' : '' ?>><?= h($ct['meds_type_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
                        <label class="select-card-box">
                            <input type="checkbox" name="has_meds" value="1" onchange="updateMedsListVisibility()"> Standard Meds
                        </label>
                        <label class="select-card-box">
                            <input type="checkbox" name="has_labs" value="1" onchange="toggleBox(this, 'pc-labs-box')"> Laboratory Tests
                        </label>
                        <label class="select-card-box">
                            <input type="checkbox" name="has_gamot_meds" value="1" onchange="toggleBox(this, 'pc-gamot-box')"> Gamot Medicines
                        </label>
                    </div>

                    <div id="standard-meds-list" class="toggle-selection-container">
                        <div style="font-size: 0.75rem; font-weight: 600; color: var(--hc-primary); margin-bottom: 0.5rem; text-transform: uppercase;">Select Standard Medicines:</div>
                        <?php foreach ($standard_meds_grouped as $category => $meds): ?>
                            <div style="font-size: 0.8125rem; font-weight: 600; margin-bottom: 0.35rem; color: #475569;"><?= h($category) ?></div>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <?php foreach ($meds as $smed): ?>
                                    <div class="select-card-box" onclick="toggleCard(this, event)">
                                        <input type="checkbox" name="medications[]" value="<?= h($smed) ?>">
                                        <span><?= h($smed) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="pc-gamot-box" class="toggle-selection-container" style="border-color: #e9d5ff; background: #faf5ff;">
                        <div style="font-size: 0.75rem; font-weight: 600; color: #7c3aed; margin-bottom: 0.5rem; text-transform: uppercase;">Select GAMOT Medicines (PhilHealth YAKAP List):</div>
                        <?php foreach ($available_medicines as $category => $meds): ?>
                            <div style="font-size: 0.8125rem; font-weight: 600; margin-bottom: 0.35rem; color: #6b21a8;"><?= h($category) ?></div>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <?php foreach ($meds as $med): ?>
                                    <div class="select-card-box" onclick="toggleCard(this, event)" style="border-color: #d8b4fe;">
                                        <input type="checkbox" name="gamot_meds[]" value="<?= h($med) ?>">
                                        <span><?= h($med) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="pc-labs-box" class="toggle-selection-container" style="border-color: #a7f3d0; background: #f0fdf4;">
                        <div style="font-size: 0.75rem; font-weight: 600; color: #047857; margin-bottom: 0.5rem; text-transform: uppercase;">Select Laboratory Tests:</div>
                        <?php foreach ($available_labs as $category => $labs): ?>
                            <div style="font-size: 0.8125rem; font-weight: 600; margin-bottom: 0.35rem; color: #065f46;"><?= h($category) ?></div>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <?php foreach ($labs as $lab): ?>
                                    <div class="select-card-box" onclick="toggleCard(this, event)" style="border-color: #6ee7b7;">
                                        <input type="checkbox" name="labs[]" value="<?= h($lab) ?>">
                                        <span><?= h($lab) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; justify-content:flex-end; margin-top:1.25rem;">
                        <button type="submit" class="btn-primary">Save Consultation</button>
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

    echo '<div style="background: #f8fafc; border: 1px solid var(--hc-border); border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">';
    echo '  <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">';
    echo '    <div>'; 
    echo '      <div style="font-size:0.6875rem;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:var(--hc-text-muted);">Patient Profile Summary</div>';
    echo '      <h3 style="margin:0.15rem 0 0;font-size:1.125rem;font-weight:600;color:var(--hc-text-main);">' . h($profile['patient_name']) . '</h3>';
    echo '    </div>';
    echo '    <span class="badge badge-neutral">' . (int)$profile['total_visits'] . ' total visits</span>';
    echo '  </div>';
    echo '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.75rem;">';
    echo '    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:0.6rem;">';
    echo '      <div style="font-size:0.68rem;text-transform:uppercase;color:#64748b;font-weight:600;">First Visit</div>';
    echo '      <div style="margin-top:0.2rem;font-size:0.875rem;font-weight:600;color:#0f172a;">' . h($first_visit) . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:0.6rem;">';
    echo '      <div style="font-size:0.68rem;text-transform:uppercase;color:#64748b;font-weight:600;">Last Visit</div>';
    echo '      <div style="margin-top:0.2rem;font-size:0.875rem;font-weight:600;color:#0f172a;">' . h($last_visit) . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:0.6rem;">';
    echo '      <div style="font-size:0.68rem;text-transform:uppercase;color:#64748b;font-weight:600;">Consultations</div>';
    echo '      <div style="margin-top:0.2rem;font-size:0.875rem;font-weight:600;color:#0f172a;">' . (int)$profile['consultation_count'] . '</div>';
    echo '    </div>';
    echo '    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:0.6rem;">';
    echo '      <div style="font-size:0.68rem;text-transform:uppercase;color:#64748b;font-weight:600;">Last Type</div>';
    echo '      <div style="margin-top:0.2rem;font-size:0.875rem;font-weight:600;color:#0f172a;">' . h($profile['last_consultation_type'] ?: '—') . '</div>';
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
}
?>

<script>
function syncSelectionCard(cardElement, checkbox) {
    if (!cardElement || !checkbox) return;
    cardElement.classList.toggle('is-selected', checkbox.checked);
}

function toggleCard(cardElement, event) {
    const cb = cardElement ? cardElement.querySelector('input[type="checkbox"]') : null;
    if (!cb) return;

    if (!event || event.target !== cb) {
        cb.checked = !cb.checked;
    }
    syncSelectionCard(cardElement, cb);
}

function setBoxVisibility(checkbox, boxId) {
    const el = document.getElementById(boxId);
    if (!el) return;
    const visible = !!(checkbox && checkbox.checked);
    el.style.display = visible ? 'block' : 'none';
}

function toggleBox(checkbox, boxId) {
    setBoxVisibility(checkbox, boxId);
}

function updateMedsListVisibility() {
    const medsBox = document.querySelector('#pc-add-form input[name="has_meds"]');
    setBoxVisibility(medsBox, 'standard-meds-list');
}

function initConsultationSelectionBoxes() {
    updateMedsListVisibility();

    const gamotBox = document.querySelector('#pc-add-form input[name="has_gamot_meds"]');
    if (gamotBox) toggleBox(gamotBox, 'pc-gamot-box');

    const labsBox = document.querySelector('#pc-add-form input[name="has_labs"]');
    if (labsBox) toggleBox(labsBox, 'pc-labs-box');

    document.querySelectorAll('#pc-add-form .select-card-box input[type="checkbox"]').forEach(function(cb) {
        syncSelectionCard(cb.closest('.select-card-box'), cb);
        if (!cb.dataset.selectionBound) {
            cb.dataset.selectionBound = '1';
            cb.addEventListener('change', function() {
                syncSelectionCard(cb.closest('.select-card-box'), cb);
            });
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initConsultationSelectionBoxes);
} else {
    initConsultationSelectionBoxes();
}

(function() {
    'use strict';

    var input = document.getElementById('pc-q-input');
    if (!input) return;

    var container = input.closest('.ph-autocomplete');
    var list = document.getElementById('pc-autocomplete-list');
    var form = document.getElementById('pc-search-form');

    var debounceTimer = null;
    var requestSeq = 0;

    function hideList() {
        list.hidden = true;
        list.innerHTML = '';
    }

    function renderItems(items) {
        list.innerHTML = '';

        if (items.length === 0) {
            var empty = document.createElement('li');
            empty.style.padding = '0.5rem 0.75rem';
            empty.style.color = '#64748b';
            empty.style.fontSize = '0.85rem';
            empty.textContent = 'No matching patients found';
            list.appendChild(empty);
            list.hidden = false;
            return;
        }

        items.forEach(function(item) {
            var li = document.createElement('li');
            li.style.padding = '0.5rem 0.75rem';
            li.style.cursor = 'pointer';
            li.style.fontSize = '0.875rem';
            li.style.borderBottom = '1px solid #f1f5f9';
            li.tabIndex = -1;

            var nameSpan = document.createElement('span');
            nameSpan.textContent = item.patient_name;
            li.appendChild(nameSpan);

            if (item.last_visit) {
                var hint = document.createElement('span');
                hint.style.display = 'block';
                hint.style.fontSize = '0.75rem';
                hint.style.color = '#64748b';
                hint.textContent = 'Last visit: ' + item.last_visit;
                li.appendChild(hint);
            }

            li.addEventListener('mouseover', function() { li.style.background = '#f8fafc'; });
            li.addEventListener('mouseout', function() { li.style.background = '#fff'; });

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
        var seq = ++requestSeq;

        fetch('patient-consultation.php?ajax=search_patients&term=' + encodeURIComponent(value))
            .then(function(response) {
                if (!response.ok) throw new Error('Network error');
                return response.json();
            })
            .then(function(data) {
                if (seq !== requestSeq) return;
                renderItems(data);
            })
            .catch(function(err) {
                if (seq === requestSeq) hideList();
            });
    }

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(doSearch, 250);
    });

    input.addEventListener('focus', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(doSearch, 150);
    });

    input.addEventListener('keydown', function(e) {
        var items = list.querySelectorAll('li');
        if (e.key === 'Escape') {
            hideList();
        }
    });

    document.addEventListener('click', function(e) {
        if (container && !container.contains(e.target)) {
            hideList();
        }
    });
})();
</script>

<?php include 'includes/footer.php'; ?>