<?php
/**
 * YAKAP GAMOT SYSTEM - Daily Log (index.php)
 */

require_once 'config.php';
require_once 'includes/auth.php';

// 2nd Tranche is an administrative encoding status only.
// It is stored separately from daily_records, so it NEVER affects visits.
$tranche_status_file = __DIR__ . DIRECTORY_SEPARATOR . 'yakap_2nd_tranche.json';
$pcu_status_file = __DIR__ . '/pcu_status.json';$pcu_status = [];

$pcu_profile_file = __DIR__ . '/pcu_patient_profiles.json';$pcu_profiles = [];
if (file_exists($pcu_profile_file)) {
    $decoded_profiles = json_decode(file_get_contents($pcu_profile_file), true);
    if (is_array($decoded_profiles)) {
        $pcu_profiles =$decoded_profiles;
    }
}
if (file_exists($pcu_status_file)) {
    $decoded_pcu = json_decode(file_get_contents($pcu_status_file), true);
    if (is_array($decoded_pcu)) foreach ($decoded_pcu as $name =>$value) {
        $key = mb_strtolower(normalize_patient_name((string)$name));
        if ($key !== '')$pcu_status[$key] =$value;
    }
}
$second_tranche_status = [];
if (is_file($tranche_status_file) && is_readable($tranche_status_file)) {
    $raw_tranche = @file_get_contents($tranche_status_file);
    $decoded_tranche = json_decode((string)$raw_tranche, true);
    if (is_array($decoded_tranche)) {
        foreach ($decoded_tranche as $stored_name =>$stored_value) {
            $normalized_key = mb_strtolower(normalize_patient_name((string)$stored_name));
            if ($normalized_key !== '') {$second_tranche_status[$normalized_key] =$stored_value;
            }
        }
    }
}

function is_second_tranche_patient($patient_name,$status) {
    $key = mb_strtolower(normalize_patient_name($patient_name));
    return $key !== '' && isset($status[$key]);
}

/**
 * Reusable check: does a patient already have ANY recorded visit?
 *
 * YAKAP FPE rule:
 * - The patient's FIRST record in the system is automatically treated as the
 *   First FPE, even when the first record is entered directly as Consultation.
 * - Later Consultation visits are allowed.
 * - A later FPE/intake entry is not allowed because the patient already has
 *   an established first visit.
 *
 * Returns the earliest recorded visit's record_date and physician_name.
 *
 * @param mysqli $conn
 * @param string $patient_name
 * @param int|null $exclude_record_id Optional record_id to exclude on update.
 * @return array|false ['record_date' => string, 'physician_name' => string|null]
 */
function get_existing_fpe($conn, $patient_name,$exclude_record_id = null) {
    if ($patient_name === '') return false;

    $sql = "
        SELECT dr.record_date, p.physician_name
        FROM daily_records dr
        LEFT JOIN physicians p ON dr.physician_id = p.physician_id
        WHERE TRIM(dr.patient_name) = TRIM(?)
    ";
    $types = 's';
    $params = [$patient_name];

    if ($exclude_record_id !== null) {$sql .= " AND dr.record_id != ?";
        $types .= 'i';
        $params[] = (int)$exclude_record_id;
    }

    // Earliest record = First FPE date, regardless of visit type.
    $sql .= " ORDER BY dr.record_date ASC, dr.created_at ASC, dr.record_id ASC LIMIT 1";

    $stmt =$conn->prepare($sql);$stmt->bind_param($types, ...$params);
    $stmt->execute();$row = $stmt->get_result()->fetch_assoc();$stmt->close();

    return $row ? $row : false;
}

/**
 * Backward-compatible wrapper: returns the patient's first recorded visit
 * date, or false if the patient has never been recorded. Used by the
 * check_existing_fpe AJAX endpoint.
 */
function patient_has_fpe($conn, $patient_name) {$fpe = get_existing_fpe($conn,$patient_name);
    return $fpe ? $fpe['record_date'] : false;
}

/**
 * Is the given meds_type_id an FPE / non-consultation type?
 * A NULL/empty meds_type_id is also treated as FPE/intake (matches the
 * eligibility condition used elsewhere in this codebase).
 */
function is_fpe_meds_type($conn, $meds_type_id) {
    if (empty($meds_type_id)) return true;

    $stmt = $conn->prepare("SELECT is_consultation FROM meds_types WHERE meds_type_id = ?");
    $stmt->bind_param('i', $meds_type_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['is_consultation'] === 0 : true;
}

/**
 * Return the Meds Type ID used for Consultation visits.
 * Prefer the exact label "Consultation" and fall back to the first
 * consultation-enabled meds type if the label was renamed.
 */
function get_consultation_meds_type_id($conn) {
    $stmt = $conn->prepare("SELECT meds_type_id FROM meds_types WHERE is_consultation = 1 ORDER BY (LOWER(meds_type_name) = 'consultation') DESC, meds_type_id ASC LIMIT 1");
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['meds_type_id'] : null;
}

// Ensure necessary JSON columns exist
$check_meds = $conn->query("SHOW COLUMNS FROM daily_records LIKE 'record_medications'");
if ($check_meds && $check_meds->num_rows === 0) {
    $conn->query("ALTER TABLE daily_records ADD COLUMN record_medications TEXT NULL AFTER has_gamot_meds");
}

$check_gamot = $conn->query("SHOW COLUMNS FROM daily_records LIKE 'record_gamot'");
if ($check_gamot && $check_gamot->num_rows === 0) {
    $conn->query("ALTER TABLE daily_records ADD COLUMN record_gamot TEXT NULL AFTER record_medications");
}

$check_labs = $conn->query("SHOW COLUMNS FROM daily_records LIKE 'record_labs'");
if ($check_labs && $check_labs->num_rows === 0) {
    $conn->query("ALTER TABLE daily_records ADD COLUMN record_labs TEXT NULL AFTER record_gamot");
}

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

// --- AJAX Endpoints ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_similar_name') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = trim($_GET['name'] ?? '');
    $name = normalize_patient_name($raw);
    $matches = [];
    if ($name !== '') {
        $stmt = $conn->prepare("SELECT DISTINCT patient_name FROM patients WHERE patient_name IS NOT NULL AND patient_name != ''");
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = [];
        while ($row = $res->fetch_assoc()) { $existing[] = $row['patient_name']; }
        $stmt->close();
        foreach ($existing as $existing_name) {
            $norm_existing = normalize_patient_name($existing_name);
            if (strcasecmp($norm_existing, $name) === 0) continue;
            $similar_text_percent = 0.0;
            similar_text($name, $norm_existing, $similar_text_percent);
            $lev = levenshtein($name, $norm_existing);
            if ($similar_text_percent >= 80.0 || (mb_strlen($norm_existing) > 0 && mb_strlen($norm_existing) <= 8 && $lev <= 2)) {
                $matches[] = ['patient_name' => $existing_name];
            }
        }
    }
    echo json_encode(['name' => $name, 'matches' => $matches]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_existing_fpe') {
    header('Content-Type: application/json; charset=utf-8');
    $name = normalize_patient_name($_GET['name'] ?? '');
    $last_fpe_date = $name !== '' ? patient_has_fpe($conn, $name) : false;
    echo json_encode([
        'has_fpe'       => ($last_fpe_date !== false),
        'last_fpe_date' => $last_fpe_date ? date('M j, Y', strtotime($last_fpe_date)) : null,
    ]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_registered') {
    header('Content-Type: application/json; charset=utf-8');
    $name = normalize_patient_name($_GET['name'] ?? '');
    $reg = $name !== '' ? patient_is_registered($conn, $name) : false;
    echo json_encode([
        'registered' => ($reg !== false),
        'record_date'=> ($reg !== false && isset($reg['record_date']) && $reg['record_date']) ? $reg['record_date'] : null,
    ]);
    exit;
}

// --- HANDLE REAL-TIME AJAX FILTER ENDPOINT ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter_records') {
    header('Content-Type: application/json; charset=utf-8');
    $selected_date = $_GET['date'] ?? date('Y-m-d');
    $search_query = trim($_GET['search'] ?? '');
    $filter_physician = isset($_GET['filter_physician']) ? (int)$_GET['filter_physician'] : 0;
    $filter_meds_type = isset($_GET['filter_meds_type']) ? (int)$_GET['filter_meds_type'] : 0;

    $where_clauses = ["dr.record_date = ?"];
    $params = [$selected_date]; 
    $types = "s";

    if (!empty($search_query)) { 
        $where_clauses[] = "dr.patient_name LIKE ?"; 
        $params[] = '%' . $search_query . '%'; 
        $types .= "s"; 
    }
    if ($filter_physician > 0) { 
        $where_clauses[] = "dr.physician_id = ?"; 
        $params[] = $filter_physician; 
        $types .= "i"; 
    }
    if ($filter_meds_type > 0) { 
        $where_clauses[] = "dr.meds_type_id = ?"; 
        $params[] = $filter_meds_type; 
        $types .= "i"; 
    }

    $query = "
        SELECT dr.*, p.physician_name, mt.meds_type_name,
               (
                   SELECT MIN(first_visit.record_date)
                   FROM daily_records first_visit
                   WHERE TRIM(first_visit.patient_name) = TRIM(dr.patient_name)
               ) AS fpe_date
        FROM daily_records dr 
        LEFT JOIN physicians p ON dr.physician_id = p.physician_id 
        LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id 
        WHERE " . implode(" AND ", $where_clauses) . " 
        ORDER BY dr.created_at DESC, dr.record_id DESC
    ";
    $records_stmt = $conn->prepare($query);
    if (!empty($types)) {
        $records_stmt->bind_param($types, ...$params);
    }
    $records_stmt->execute();
    $records_rows = $records_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $records_stmt->close();

    $patient_rows = [];
    foreach ($records_rows as $row) {
        $patient_key = mb_strtolower(normalize_patient_name($row['patient_name']));
        if (!isset($patient_rows[$patient_key]) || $row['created_at'] >= $patient_rows[$patient_key]['created_at']) {
            $patient_rows[$patient_key] = $row;
        }
    }
    $records_rows = array_values($patient_rows);

    foreach ($records_rows as &$display_row) {
        $display_row['second_tranche'] = is_second_tranche_patient($display_row['patient_name'], $second_tranche_status) ? 1 : 0;
        $pcu_key = mb_strtolower(normalize_patient_name($display_row['patient_name']));
        $display_row['pcu_done'] = isset($pcu_status[$pcu_key]) ? 1 : 0;
        $display_row['pcu_profile'] = $pcu_profiles[$pcu_key] ?? [];
    }
    unset($display_row);

    echo json_encode([
        'success' => true,
        'count' => count($records_rows),
        'records' => $records_rows,
        'selected_date' => $selected_date
    ]);
    exit;
}

// --- HANDLE CSV EXPORT ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exp_date = selected_date();
    $exp_stmt = $conn->prepare("
        SELECT dr.record_date, dr.patient_name, COALESCE(p.physician_name, '') AS physician, 
               COALESCE(mt.meds_type_name, '') AS meds_type, dr.has_meds, dr.record_medications, 
               dr.has_gamot_meds, dr.record_gamot, dr.has_labs, dr.record_labs 
        FROM daily_records dr 
        LEFT JOIN physicians p ON dr.physician_id = p.physician_id 
        LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id 
        WHERE dr.record_date = ? 
        ORDER BY dr.created_at ASC
    ");
    $exp_stmt->bind_param("s", $exp_date);
    $exp_stmt->execute();
    $exp_result = $exp_stmt->get_result();

    $export_data = [];
    while ($row = $exp_result->fetch_assoc()) {
        $export_data[] = [
            'Record Date'   => $row['record_date'],
            'Patient Name'  => $row['patient_name'],
            'Physician'     => $row['physician'],
            'Meds Type'     => $row['meds_type'],
            'Has Meds'      => $row['has_meds'] ? 'YES' : 'NO',
            'Selected Meds' => !empty($row['record_medications']) ? implode(', ', json_decode($row['record_medications'], true) ?? []) : '',
            'Has Gamot'     => $row['has_gamot_meds'] ? 'YES' : 'NO',
            'Selected Gamot'=> !empty($row['record_gamot']) ? implode(', ', json_decode($row['record_gamot'], true) ?? []) : '',
            'Has Labs'      => $row['has_labs'] ? 'YES' : 'NO',
            'Selected Labs' => !empty($row['record_labs']) ? implode(', ', json_decode($row['record_labs'], true) ?? []) : ''
        ];
    }
    $exp_stmt->close();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_log_' . $exp_date . '.csv"');
    $output = fopen('php://output', 'w');
    if (!empty($export_data)) {
        fputcsv($output, array_keys($export_data[0]));
        foreach ($export_data as $data_row) { fputcsv($output, $data_row); }
    }
    fclose($output);
    exit;
}

// --- FORM ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_pcu_profile') {
        $patient_name = normalize_patient_name($_POST['patient_name'] ?? '');
        $pcu_key = mb_strtolower($patient_name);

        if ($pcu_key === '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Patient name is required.']);
            exit;
        }

        $pcu_profiles[$pcu_key] = [
            'patient_name' => $patient_name,
            'last_name'    => trim($_POST['last_name'] ?? ''),
            'first_name'   => trim($_POST['first_name'] ?? ''),
            'middle_name'  => trim($_POST['middle_name'] ?? ''),
            'no_middle'    => !empty($_POST['no_middle']) ? 1 : 0,
            'suffix'       => trim($_POST['suffix'] ?? ''),
            'birth_date'   => trim($_POST['birth_date'] ?? ''),
            'sex'          => trim($_POST['sex'] ?? ''),
            'updated_at'   => date('Y-m-d H:i:s')
        ];

        file_put_contents(
            $pcu_profile_file,
            json_encode($pcu_profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'profile' => $pcu_profiles[$pcu_key]]);
        exit;
    }

    if ($action === 'toggle_pcu') {
        $name = normalize_patient_name($_POST['patient_name'] ?? '');
        $key = mb_strtolower($name);
        if ($key !== '') {
            if (isset($pcu_status[$key])) unset($pcu_status[$key]);
            else $pcu_status[$key] = ['done' => true, 'done_at' => date('Y-m-d H:i:s')];
            file_put_contents($pcu_status_file, json_encode($pcu_status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'pcu_done' => ($key !== '' && isset($pcu_status[$key]))
            ]);
            exit;
        }
        header('Location: index.php?date=' . urlencode($_POST['record_date'] ?? date('Y-m-d')));
        exit;
    }
    if ($action === 'add') {
        $record_date = $_POST['record_date'] ?? '';
        $patient_name = normalize_patient_name($_POST['patient_name'] ?? '');
        $physician_id = !empty($_POST['physician_id']) ? (int)$_POST['physician_id'] : null;
        $meds_type_id = !empty($_POST['meds_type_id']) ? (int)$_POST['meds_type_id'] : null;
        $has_meds = isset($_POST['has_meds']) ? 1 : 0;
        $has_labs = isset($_POST['has_labs']) ? 1 : 0;
        $has_gamot_meds = isset($_POST['has_gamot_meds']) ? 1 : 0;

        // Checking a service (Meds/Labs/Gamot) nudges the visit type to
        // Consultation, but only as a DEFAULT — it does not overwrite a
        // consultation-type value the user already picked. The one thing
        // still enforced server-side is that a service can't be attached
        // to an FPE/intake-type visit; that part can't be bypassed.
        $has_consultation_service = ($has_meds || $has_labs || $has_gamot_meds);
        if ($has_consultation_service && is_fpe_meds_type($conn, $meds_type_id)) {
            $consultation_type_id = get_consultation_meds_type_id($conn);
            if ($consultation_type_id !== null) {
                $meds_type_id = $consultation_type_id;
            }
        }

        $selected_meds = ($has_meds && isset($_POST['medications']) && is_array($_POST['medications'])) ? json_encode(array_values($_POST['medications'])) : null;
        $selected_gamot = ($has_gamot_meds && isset($_POST['gamot_meds']) && is_array($_POST['gamot_meds'])) ? json_encode(array_values($_POST['gamot_meds'])) : null;
        $selected_labs = ($has_labs && isset($_POST['labs']) && is_array($_POST['labs'])) ? json_encode(array_values($_POST['labs'])) : null;

        $is_consultation_visit = $meds_type_id !== null && !is_fpe_meds_type($conn, $meds_type_id);
        if ($is_consultation_visit && empty($physician_id)) {
            redirect_with_msg(
                'index.php?date=' . $record_date,
                'A physician is required for a Consultation. Please select a physician before saving.',
                'error'
            );
        }

        if (!empty($record_date) && !empty($patient_name)) {
            $dup_stmt = $conn->prepare("SELECT record_id FROM daily_records WHERE TRIM(patient_name) = ? AND record_date = ? LIMIT 1");
            $dup_stmt->bind_param('ss', $patient_name, $record_date);
            $dup_stmt->execute();
            $is_dup = $dup_stmt->get_result()->fetch_assoc();
            $dup_stmt->close();

            if ($is_dup) {
                redirect_with_msg('index.php?date=' . $record_date, 'This patient is already recorded for this date. No duplicate was created.', 'error');
            }

            // The first-ever record for this patient is automatically the First FPE,
            // even when the first entry is a direct Consultation.
            // Once a first visit exists, later FPE/intake entries are blocked;
            // subsequent Consultation visits remain allowed.
            $existing_fpe = get_existing_fpe($conn, $patient_name);
            if ($existing_fpe && is_fpe_meds_type($conn, $meds_type_id)) {
                $fpe_date = date('M j, Y', strtotime($existing_fpe['record_date']));
                redirect_with_msg(
                    'index.php?date=' . $record_date,
                    "\"{$patient_name}\" already has a First FPE dated {$fpe_date}. Please select a consultation-type visit for a subsequent visit.",
                    'error'
                );
            }

            $stmt = $conn->prepare("INSERT INTO daily_records (record_date, patient_name, physician_id, meds_type_id, has_meds, record_medications, has_gamot_meds, record_gamot, has_labs, record_labs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiisisis", $record_date, $patient_name, $physician_id, $meds_type_id, $has_meds, $selected_meds, $has_gamot_meds, $selected_gamot, $has_labs, $selected_labs);
            if ($stmt->execute()) {
                $sync = $conn->prepare("INSERT IGNORE INTO patients (patient_name) VALUES (?)");
                $sync->bind_param("s", $patient_name);
                $sync->execute();
                $sync->close();
                redirect_with_msg(
                    'index.php?date=' . $record_date,
                    $existing_fpe
                        ? 'Follow-up consultation recorded successfully.'
                        : 'First patient record saved successfully. This visit is automatically the First FPE.'
                );
            }
            $stmt->close();
        }
    } elseif ($action === 'update') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $patient_name = normalize_patient_name($_POST['patient_name'] ?? '');
        $physician_id = !empty($_POST['physician_id']) ? (int)$_POST['physician_id'] : null;
        $meds_type_id = !empty($_POST['meds_type_id']) ? (int)$_POST['meds_type_id'] : null;
        $has_meds = isset($_POST['has_meds']) ? 1 : 0;
        $has_labs = isset($_POST['has_labs']) ? 1 : 0;
        $has_gamot_meds = isset($_POST['has_gamot_meds']) ? 1 : 0;
        $record_date = $_POST['record_date'] ?? '';

        // Same rule as on add: a checked service defaults the visit type to
        // Consultation only when it isn't already a consultation-type — a
        // manually-picked consultation type is left as the user set it.
        $has_consultation_service = ($has_meds || $has_labs || $has_gamot_meds);
        if ($has_consultation_service && is_fpe_meds_type($conn, $meds_type_id)) {
            $consultation_type_id = get_consultation_meds_type_id($conn);
            if ($consultation_type_id !== null) {
                $meds_type_id = $consultation_type_id;
            }
        }

        $selected_meds = ($has_meds && isset($_POST['medications']) && is_array($_POST['medications'])) ? json_encode(array_values($_POST['medications'])) : null;
        $selected_gamot = ($has_gamot_meds && isset($_POST['gamot_meds']) && is_array($_POST['gamot_meds'])) ? json_encode(array_values($_POST['gamot_meds'])) : null;
        $selected_labs = ($has_labs && isset($_POST['labs']) && is_array($_POST['labs'])) ? json_encode(array_values($_POST['labs'])) : null;

        $is_consultation_visit = $meds_type_id !== null && !is_fpe_meds_type($conn, $meds_type_id);
        if ($is_consultation_visit && empty($physician_id)) {
            redirect_with_msg(
                'index.php?date=' . $record_date,
                'A physician is required for a Consultation. Please select a physician before saving.',
                'error'
            );
        }

        if ($record_id > 0 && !empty($patient_name)) {
            if (is_fpe_meds_type($conn, $meds_type_id)) {
                $existing = patient_is_registered($conn, $patient_name, $record_id);
                if ($existing !== false) {
                    redirect_with_msg('index.php?date=' . $record_date, "This patient is already registered. Visits must be logged as a Consultation.", 'error');
                }
            }
            $stmt = $conn->prepare("UPDATE daily_records SET patient_name = ?, physician_id = ?, meds_type_id = ?, has_meds = ?, record_medications = ?, has_gamot_meds = ?, record_gamot = ?, has_labs = ?, record_labs = ? WHERE record_id = ?");
            $stmt->bind_param("siiisisisi", $patient_name, $physician_id, $meds_type_id, $has_meds, $selected_meds, $has_gamot_meds, $selected_gamot, $has_labs, $selected_labs, $record_id);
            $stmt->execute();
            $stmt->close();
            redirect_with_msg('index.php?date=' . $record_date, 'Record updated successfully.');
        }
    } elseif ($action === 'delete') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $record_date = $_POST['record_date'] ?? '';
        if ($record_id > 0) {
            $name_stmt = $conn->prepare("SELECT patient_name FROM daily_records WHERE record_id = ?");
            $name_stmt->bind_param("i", $record_id);
            $name_stmt->execute();
            $patient_name = $name_stmt->get_result()->fetch_assoc()['patient_name'] ?? null;
            $name_stmt->close();

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("DELETE FROM daily_records WHERE record_id = ?");
                $stmt->bind_param("i", $record_id);
                $stmt->execute();
                $stmt->close();

                $cascade_removed = 0;
                if ($patient_name) {
                    $elig = $conn->prepare("SELECT dr.record_id FROM daily_records dr LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id WHERE dr.patient_name = ? AND (dr.meds_type_id IS NULL OR mt.is_consultation = 0) LIMIT 1");
                    $elig->bind_param("s", $patient_name);
                    $elig->execute();
                    $still_eligible = ($elig->get_result()->num_rows > 0);
                    $elig->close();

                    if (!$still_eligible) {
                        $cascade_stmt = $conn->prepare("DELETE dr FROM daily_records dr LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id WHERE dr.patient_name = ? AND mt.is_consultation = 1");
                        $cascade_stmt->bind_param("s", $patient_name);
                        $cascade_stmt->execute();
                        $cascade_removed = (int)$conn->affected_rows;
                        $cascade_stmt->close();
                    }
                }
                $conn->commit();
                redirect_with_msg('index.php?date=' . $record_date, $cascade_removed > 0 ? "Record deleted. {$cascade_removed} related consultation(s) also removed." : 'Record deleted successfully.');
            } catch (Throwable $e) {
                $conn->rollback();
            }
        }
    }
}

// --- DATA FETCHING ---
$selected_date = selected_date();
$search_query = trim($_GET['search'] ?? '');
$filter_physician = isset($_GET['filter_physician']) ? (int)$_GET['filter_physician'] : 0;
$filter_meds_type = isset($_GET['filter_meds_type']) ? (int)$_GET['filter_meds_type'] : 0;

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

$total_dates = (int)$conn->query("SELECT COUNT(DISTINCT record_date) AS cnt FROM daily_records")->fetch_assoc()['cnt'];
$total_pages = max(1, ceil($total_dates / $per_page));
$dates_result = $conn->query("SELECT record_date, COUNT(DISTINCT LOWER(TRIM(patient_name))) AS total FROM daily_records GROUP BY record_date ORDER BY record_date DESC LIMIT $per_page OFFSET $offset");

$physicians_list = $conn->query("SELECT physician_id, physician_name FROM physicians WHERE is_active = 1 ORDER BY physician_name")->fetch_all(MYSQLI_ASSOC);
$meds_types_list = $conn->query("SELECT meds_type_id, meds_type_name, is_consultation FROM meds_types ORDER BY meds_type_name")->fetch_all(MYSQLI_ASSOC);
$patients_list = $conn->query("SELECT DISTINCT patient_name FROM patients ORDER BY patient_name")->fetch_all(MYSQLI_ASSOC);

$where_clauses = ["dr.record_date = ?"];
$params = [$selected_date]; 
$types = "s";

if (!empty($search_query)) { $where_clauses[] = "dr.patient_name LIKE ?"; $params[] = '%' . $search_query . '%'; $types .= "s"; }
if ($filter_physician > 0) { $where_clauses[] = "dr.physician_id = ?"; $params[] = $filter_physician; $types .= "i"; }
if ($filter_meds_type > 0) { $where_clauses[] = "dr.meds_type_id = ?"; $params[] = $filter_meds_type; $types .= "i"; }

$query = "
    SELECT dr.*, p.physician_name, mt.meds_type_name,
           (
               SELECT MIN(first_visit.record_date)
               FROM daily_records first_visit
               WHERE TRIM(first_visit.patient_name) = TRIM(dr.patient_name)
           ) AS fpe_date
    FROM daily_records dr 
    LEFT JOIN physicians p ON dr.physician_id = p.physician_id 
    LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id 
    WHERE " . implode(" AND ", $where_clauses) . " 
    ORDER BY dr.created_at DESC, dr.record_id DESC
";
$records_stmt = $conn->prepare($query);
$records_stmt->bind_param($types, ...$params);
$records_stmt->execute();
$records_rows = $records_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$records_stmt->close();

$patient_rows = [];
foreach ($records_rows as $row) {
    $patient_key = mb_strtolower(normalize_patient_name($row['patient_name']));
    if (!isset($patient_rows[$patient_key]) || $row['created_at'] >= $patient_rows[$patient_key]['created_at']) {
        $patient_rows[$patient_key] = $row;
    }
}
$records_rows = array_values($patient_rows);

// Apply the separate 2nd Tranche encoding marker to the displayed patient row.
// This does not touch the visit data or daily_records.
foreach ($records_rows as &$display_row) {
    $display_row['second_tranche'] = is_second_tranche_patient($display_row['patient_name'], $second_tranche_status) ? 1 : 0;
    $pcu_key = mb_strtolower(normalize_patient_name($display_row['patient_name']));
    $display_row['pcu_done'] = isset($pcu_status[$pcu_key]) ? 1 : 0;
    $display_row['pcu_profile'] = $pcu_profiles[$pcu_key] ?? [];
}
unset($display_row);

$has_filters = (!empty($search_query) || $filter_physician > 0 || $filter_meds_type > 0);

include 'includes/header.php';
?><style>
/* Print-specific layout rules */
@media print {
    body * {
        visibility: hidden;
    }
    #printable-patient-list, #printable-patient-list * {
        visibility: visible;
    }
    #printable-patient-list {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 20px;
        background: #fff;
    }
    .no-print {
        display: none !important;
    }
}

#printable-patient-list {
    display: none;
}
</style>
<style>
/* Compact Single-Row Add Patient Panel */
.add-patient-panel { 
    padding: 0.6rem 1rem !important; 
    margin-bottom: 1rem !important;
}
.add-patient-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.add-patient-caption {
    font-size: 0.75rem;
    color: var(--text-muted);
}
#add-patient-form-wrap {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border-color);
}
.inline-form-top {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
.inline-form-top .custom-autocomplete-wrapper {
    flex: 2;
    min-width: 180px;
}
.inline-form-top select {
    flex: 1;
    min-width: 130px;
}
.inline-form-top .select-card-box {
    padding: 0.35rem 0.5rem;
}
</style>
<style>
/* Compact patient action buttons so Consultation, PCU, and History fit cleanly */
.patient-action-btn {
    padding: 0.28rem 0.55rem !important;
    font-size: 0.72rem !important;
    line-height: 1.15 !important;
    white-space: nowrap;
}
</style>


<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --primary-light: #eff6ff;
    --bg-surface: #ffffff;
    --bg-body: #f8fafc;
    --border-color: #e2e8f0;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --success-bg: #d1fae5;
    --success-text: #065f46;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --shadow-subtle: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -1px rgba(0, 0, 0, 0.02);
    --shadow-elevated: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

/* Custom Smooth Scrollbar Styling Across the App */
* {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
}
*::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
*::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}
*::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
*::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

body {
    background-color: var(--bg-body);
    color: var(--text-main);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.dashboard-container {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.5rem;
    margin-top: 1rem;
}

@media (max-width: 900px) {
    .dashboard-container { grid-template-columns: 1fr; }
}

.card-box {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    box-shadow: var(--shadow-subtle);
    margin-bottom: 1.25rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card-title {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    letter-spacing: -0.01em;
}

.date-picker-group {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.date-picker-group input {
    flex: 1;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    outline: none;
    transition: border-color 0.15s ease;
}
.date-picker-group input:focus { border-color: var(--primary); }

.date-nav-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    max-height: 320px;
    overflow-y: auto;
    padding-right: 0.25rem;
}

.date-nav-item a {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.6rem 0.75rem;
    border-radius: var(--radius-sm);
    color: var(--text-main);
    text-decoration: none;
    background: #f8fafc;
    border: 1px solid transparent;
    transition: all 0.15s ease;
    font-size: 0.875rem;
}

.date-nav-item.active a, .date-nav-item a:hover {
    background: var(--primary-light);
    border-color: #bfdbfe;
    color: var(--primary-dark);
}

.date-nav-item.active .badge, .date-nav-item a:hover .badge {
    background: var(--primary);
    color: #fff;
}

.badge {
    font-size: 0.75rem;
    padding: 0.15rem 0.6rem;
    background: #e2e8f0;
    border-radius: 20px;
    color: var(--text-muted);
    font-weight: 600;
    transition: background-color 0.15s ease;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.custom-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    text-align: left;
    font-size: 0.9rem;
}

.custom-table th {
    background: #f8fafc;
    padding: 0.9rem 0.75rem;
    color: var(--text-muted);
    font-weight: 600;
    border-bottom: 2px solid var(--border-color);
    letter-spacing: 0.03em;
    text-transform: uppercase;
    font-size: 0.7rem;
}

.custom-table td {
    padding: 0.95rem 0.75rem;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

.clickable-row {
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.clickable-row:hover {
    background-color: #f1f5f9 !important;
}

.second-tranche-row td {
    background: #dcfce7 !important;
    border-color: #bbf7d0 !important;
}
.second-tranche-row:hover td {
    background: #bbf7d0 !important;
}
.second-tranche-name {
    color: #166534 !important;
    font-weight: 800;
}
.second-tranche-badge {
    display: inline-block;
    margin-top: 0.25rem;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    background: #16a34a;
    color: #fff;
    font-size: 0.68rem;
    font-weight: 800;
}
.patient-name-text {
    color: var(--primary);
    font-weight: 600;
}

.tag {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
}

.tag-success { background: var(--success-bg); color: var(--success-text); }
.tag-gray { background: #f1f5f9; color: #94a3b8; }

.med-chip, .gamot-chip, .lab-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border-radius: 6px;
    padding: 0.3rem 0.6rem;
    font-size: 0.8rem;
    margin: 0.2rem;
    font-weight: 500;
}
.med-chip { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.gamot-chip { background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }
.lab-chip { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }

.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.filter-bar input, .filter-bar select {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: #fff;
    outline: none;
    transition: border-color 0.15s ease;
}
.filter-bar input:focus, .filter-bar select:focus { border-color: var(--primary); }

.add-record-panel {
    background: linear-gradient(to bottom right, var(--primary-light), #ffffff);
    border: 1px solid #93c5fd;
    border-radius: var(--radius-md);
    padding: 1.5rem;
    margin-bottom: 1.25rem;
    box-shadow: var(--shadow-subtle);
}

.select-card-box {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.75rem;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: var(--radius-sm);
    font-size: 0.8rem;
    cursor: pointer;
    user-select: none;
    transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
}

.select-card-box:hover {
    background: #f1f5f9;
    border-color: var(--primary);
}

.select-card-box input[type="checkbox"] {
    cursor: pointer;
    accent-color: var(--primary);
    transform: scale(1.1);
}

.select-card-box.active {
    background: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary-dark);
    font-weight: 600;
    box-shadow: 0 0 0 1px var(--primary);
}

.toggle-selection-container {
    margin-top: 1rem;
    padding: 1rem;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    display: none;
    max-height: 250px;
    overflow-y: auto;
    box-shadow: inset 0 2px 4px 0 rgba(0,0,0,0.02);
}

.group-title {
    font-weight: 700;
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--primary);
    margin: 0.75rem 0 0.35rem 0;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 0.25rem;
    letter-spacing: 0.05em;
}
.group-title.lab-title { color: #059669; }
.group-title.gamot-title { color: #7c3aed; }
.group-title:first-child { margin-top: 0; }

.items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.5rem;
}

/* Custom Patient Autocomplete Dropdown Box with Scrollbar */
.custom-autocomplete-wrapper {
    position: relative;
    width: 100%;
}

.custom-suggestions-list {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-elevated);
    max-height: 200px;
    overflow-y: auto;
    z-index: 1050;
    display: none;
    list-style: none;
    padding: 0;
    margin: 0;
}

.custom-suggestions-list li {
    padding: 0.6rem 0.85rem;
    font-size: 0.875rem;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    color: var(--text-main);
    transition: background-color 0.12s ease;
}

.custom-suggestions-list li:last-child {
    border-bottom: none;
}

.custom-suggestions-list li:hover, .custom-suggestions-list li.selected {
    background-color: var(--primary-light);
    color: var(--primary-dark);
    font-weight: 500;
}

.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 999;
}

.modal-card {
    background: #fff;
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 720px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 2rem;
    box-shadow: var(--shadow-elevated);
    animation: modalScaleUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Patient History Slide/Modal Scroll Styling */
.ph-modal {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    z-index: 1000;
    display: flex;
    justify-content: flex-end;
    opacity: 0;
    transition: opacity 0.2s ease;
}
.ph-modal.ph-open { opacity: 1; }
.ph-backdrop {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(3px);
    cursor: pointer;
}
.ph-panel {
    position: relative;
    width: 100%;
    max-width: 540px;
    height: 100%;
    background: var(--bg-surface);
    box-shadow: -16px 0 40px rgba(15, 23, 42, 0.18);
    display: flex;
    flex-direction: column;
    animation: slideInRight 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.ph-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    background: linear-gradient(to bottom right, var(--primary-light), #ffffff);
    flex-shrink: 0;
}
.ph-header-text { min-width: 0; }
.ph-eyebrow {
    margin: 0 0 0.15rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--primary);
}
.ph-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ph-subtitle {
    margin: 0.2rem 0 0;
    font-size: 0.8rem;
    color: var(--text-muted);
}
.ph-close {
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    border: 1px solid var(--border-color);
    background: #fff;
    color: var(--text-muted);
    font-size: 1.15rem;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
}
.ph-close:hover {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fecaca;
    transform: rotate(90deg);
}
.ph-body {
    padding: 1.5rem;
    overflow-y: auto;
    overflow-x: hidden;
    flex: 1;
    scrollbar-width: thin;
    scrollbar-color: var(--border-color) transparent;
}
.ph-body * { max-width: 100%; box-sizing: border-box; }
.ph-body table { table-layout: fixed; word-break: break-word; }
.ph-body::-webkit-scrollbar { width: 8px; }
.ph-body::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 999px; }
.ph-body::-webkit-scrollbar-track { background: transparent; }

/* Loading / empty / error states */
.ph-loading, .ph-empty-state, .ph-error-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: 0.75rem;
    padding: 3rem 1rem;
    color: var(--text-muted);
}
.ph-spinner {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 3px solid var(--primary-light);
    border-top-color: var(--primary);
    animation: phSpin 0.7s linear infinite;
}
.ph-empty-state .ph-state-icon, .ph-error-state .ph-state-icon { font-size: 2rem; }
.ph-empty-state strong, .ph-error-state strong { color: #0f172a; font-size: 0.95rem; }
.ph-empty-state p, .ph-error-state p, .ph-loading p { margin: 0; font-size: 0.85rem; }

/* Generic professional styling for whatever content patient_history.php renders */
.ph-body table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    margin-bottom: 1.25rem;
}
.ph-body table th, .ph-body table td {
    padding: 0.6rem 0.65rem;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
    vertical-align: top;
}
.ph-body table th {
    background: var(--bg-body);
    color: var(--text-muted);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 700;
}
.ph-body table tr:last-child td { border-bottom: none; }
.ph-body table tr:hover td { background: var(--primary-light); }
.ph-body ul, .ph-body ol { margin: 0 0 1rem; padding-left: 1.1rem; font-size: 0.85rem; }
.ph-body li { margin-bottom: 0.3rem; }
.ph-body h1, .ph-body h2, .ph-body h3, .ph-body h4 {
    font-size: 0.95rem;
    color: #0f172a;
    margin: 1.25rem 0 0.5rem;
}
.ph-body h1:first-child, .ph-body h2:first-child, .ph-body h3:first-child, .ph-body h4:first-child { margin-top: 0; }
.ph-body p { font-size: 0.85rem; color: #334155; line-height: 1.5; }
.ph-body hr { border: none; border-top: 1px solid var(--border-color); margin: 1.25rem 0; }

@keyframes phSpin { to { transform: rotate(360deg); } }

@keyframes modalScaleUp {
    from { transform: scale(0.92); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

@keyframes slideInRight {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
}

@media (max-width: 640px) {
    .ph-panel { max-width: 100%; }
}
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
    <h2>📋 Daily Log</h2>
    <div style="display: flex; gap: 0.5rem;">
        <button type="button" class="btn btn-blue btn-sm" onclick="printDailyPatients()">🖨️ Print Patient List</button>
        <a href="?date=<?= h($selected_date) ?>&export=csv" class="btn btn-blue btn-sm">📥 Export CSV</a>
    </div>
</div>

<!-- Hidden Printable Layout Structure -->
<div id="printable-patient-list">
    <div style="text-align: center; margin-bottom: 20px;">
        <h2>YAKAP-GAMOT patient list</h2>
        <p style="font-size: 14px; color: #555;">Date: <?= h(date('F j, Y', strtotime($selected_date))) ?></p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px;">
        <thead>
            <tr style="background-color: #f2f2f2;">
                <th style="border: 1px solid #333; padding: 6px; text-align: center; width: 5%;">No.</th>
                <th style="border: 1px solid #333; padding: 6px; text-align: left; width: 20%;">Patient Full Name</th>
                <th style="border: 1px solid #333; padding: 6px; text-align: left; width: 12%;">Meds Type</th>
                <th style="border: 1px solid #333; padding: 6px; text-align: center; width: 8%;">PCU Check</th>
                <th style="border: 1px solid #333; padding: 6px; text-align: left; width: 18%;">GAMOT</th>
                <th style="border: 1px solid #333; padding: 6px; text-align: left; width: 18%;">MEDS</th>
                <th style="border: 1px solid #333; padding: 6px; text-align: left; width: 18%;">LABS</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($records_rows)): ?>
                <tr>
                    <td colspan="7" style="border: 1px solid #333; padding: 12px; text-align: center;">No patient records found for today.</td>
                </tr>
            <?php else: 
                $counter = 1;
                foreach ($records_rows as $row): 
                    $print_gamot = !empty($row['record_gamot']) ? implode(', ', json_decode($row['record_gamot'], true) ?? []) : '';
                    $print_meds  = !empty($row['record_medications']) ? implode(', ', json_decode($row['record_medications'], true) ?? []) : '';
                    $print_labs  = !empty($row['record_labs']) ? implode(', ', json_decode($row['record_labs'], true) ?? []) : '';
                    
                    $pcu_checked_status = !empty($row['pcu_done']) ? '[ ✓ ] PCU' : '[ &nbsp; ]';
            ?>
                <tr>
                    <td style="border: 1px solid #333; padding: 6px; text-align: center;"><?= $counter++ ?></td>
                    <td style="border: 1px solid #333; padding: 6px; font-weight: bold;"><?= h($row['patient_name']) ?></td>
                    <td style="border: 1px solid #333; padding: 6px;"><?= !empty($row['meds_type_name']) ? h($row['meds_type_name']) : '<span style="color:#888; font-style:italic;">—</span>' ?></td>
                    <td style="border: 1px solid #333; padding: 6px; text-align: center;"><?= $pcu_checked_status ?></td>
                    <td style="border: 1px solid #333; padding: 6px;"><?= !empty($print_gamot) ? h($print_gamot) : '<span style="color:#888; font-style:italic;">None</span>' ?></td>
                    <td style="border: 1px solid #333; padding: 6px;"><?= !empty($print_meds) ? h($print_meds) : '<span style="color:#888; font-style:italic;">None</span>' ?></td>
                    <td style="border: 1px solid #333; padding: 6px;"><?= !empty($print_labs) ? h($print_labs) : '<span style="color:#888; font-style:italic;">None</span>' ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div class="dashboard-container">
    <!-- Left Navigation Sidebar -->
    <aside class="sidebar-col">
        <div class="card-box">
            <div class="card-title">Jump to Date</div>
            <form class="date-picker-group" method="get" action="index.php">
                <input type="date" id="jump_date" name="date" value="<?= h($selected_date) ?>">
                <button type="submit" class="btn btn-primary btn-sm">Go</button>
            </form>
            <button type="button" class="btn btn-sm btn-outline" style="width: 100%; margin-bottom: 1.25rem;" onclick="document.getElementById('jump_date').value='<?= date('Y-m-d') ?>';this.form.submit();">
                📅 Jump to Today
            </button>

            <div class="card-title">Recent Logs</div>
            <ul class="date-nav-list">
                <?php if ($dates_result->num_rows === 0): ?>
                    <li style="color: var(--text-muted); font-size: 0.85rem; text-align: center; padding: 0.75rem;">No logs found.</li>
                <?php else: while ($d = $dates_result->fetch_assoc()): 
                    $is_selected = ($d['record_date'] === $selected_date) ? 'active' : ''; ?>
                    <li class="date-nav-item <?= $is_selected ?>">
                        <a href="index.php?date=<?= h($d['record_date']) ?>">
                            <span><?= h(date('M j, Y', strtotime($d['record_date']))) ?></span>
                            <span class="badge"><?= (int)$d['total'] ?></span>
                        </a>
                    </li>
                <?php endwhile; endif; ?>
            </ul>

            <?php if ($total_pages > 1): ?>
            <div style="display:flex; justify-content:space-between; align-items: center; margin-top: 1rem; font-size: 0.85rem;">
                <div><?php if ($page > 1): ?><a href="?date=<?= h($selected_date) ?>&page=<?= $page-1 ?>">← Prev</a><?php endif; ?></div>
                <span style="color: var(--text-muted);">Page <?= $page ?> / <?= $total_pages ?></span>
                <div><?php if ($page < $total_pages): ?><a href="?date=<?= h($selected_date) ?>&page=<?= $page+1 ?>">Next →</a><?php endif; ?></div>
            </div>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="main-col">
        <!-- Quick Add Panel -->
        <div class="add-record-panel add-patient-panel">
            <div class="add-patient-header">
                <div style="display: flex; align-items: baseline; gap: 0.75rem;">
                    <span style="font-weight: 600; font-size: 0.95rem; color: var(--primary-dark);">Add Patient Record</span>
                    <span class="add-patient-caption"><?= h(date('F j, Y', strtotime($selected_date))) ?></span>
                </div>
                <button type="button" id="toggle-add-patient" class="btn btn-primary btn-sm" aria-expanded="false" style="padding: 0.25rem 0.75rem; font-size: 0.8rem;">
                    + Add Patient
                </button>
            </div>

            <div id="add-patient-form-wrap" hidden>
                <form method="post" action="index.php" autocomplete="off" onsubmit="return validatePatientRecordForm(this);">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="record_date" value="<?= h($selected_date) ?>">

                <div class="inline-form-top">
                    <!-- Enhanced Patient Name Input with Scrollable Custom Suggestions List -->
                    <div class="custom-autocomplete-wrapper">
                        <input type="text" name="patient_name" id="add_patient_input" placeholder="Patient Name *" required style="width: 100%; padding: 0.4rem 0.6rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); outline:none; background:#fff; font-size: 0.85rem;" oninput="filterPatients(this.value, 'add-suggestions')" onfocus="filterPatients(this.value, 'add-suggestions')">
                        <ul id="add-suggestions" class="custom-suggestions-list"></ul>
                    </div>

                    <select name="physician_id" id="add_physician_id" style="padding: 0.4rem 0.6rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); outline:none; background:#fff; font-size: 0.85rem;">
                        <option value="">-- Physician --</option>
                        <?php foreach ($physicians_list as $p): ?>
                            <option value="<?= (int)$p['physician_id'] ?>"><?= h($p['physician_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="meds_type_id" id="add_meds_type_id" style="padding: 0.4rem 0.6rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); outline:none; background:#fff; font-size: 0.85rem;">
                        <option value="">-- Meds Type --</option>
                        <?php foreach ($meds_types_list as $mt): ?>
                            <option value="<?= (int)$mt['meds_type_id'] ?>" <?= !empty($mt['is_consultation']) ? 'data-is-consultation="1"' : 'data-is-fpe="1"' ?>><?= h($mt['meds_type_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="select-card-box" style="background:#fff; font-size: 0.8rem;">
                        <input type="checkbox" name="has_meds" value="1" onchange="toggleBox(this, 'add-meds-box'); syncConsultationRequirements(this.form)"> Meds
                    </label>
                    <label class="select-card-box" style="background:#fff; font-size: 0.8rem;">
                        <input type="checkbox" name="has_labs" value="1" onchange="toggleBox(this, 'add-labs-box'); syncConsultationRequirements(this.form)"> Labs
                    </label>
                    <label class="select-card-box" style="background:#fff; font-size: 0.8rem;">
                        <input type="checkbox" name="has_gamot_meds" value="1" onchange="toggleBox(this, 'add-gamot-box'); syncConsultationRequirements(this.form)"> Gamot
                    </label>

                    <button type="submit" id="add_record_btn" class="btn btn-primary btn-sm" style="padding: 0.4rem 0.8rem;">Save</button>
                </div>

                <!-- Add Form Standard Meds Selector -->
                <div id="add-meds-box" class="toggle-selection-container">
                    <div style="font-size: 0.8rem; font-weight: 600; color: var(--primary); margin-bottom: 0.5rem;">Select Yakap Medicine:</div>
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

                <!-- Add Form Gamot Selector -->
                <div id="add-gamot-box" class="toggle-selection-container" style="border-color: #e9d5ff;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: #7c3aed; margin-bottom: 0.5rem;">Select Gamot Medicine:</div>
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

                <!-- Add Form Labs Selector -->
                <div id="add-labs-box" class="toggle-selection-container" style="border-color: #a7f3d0;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: #047857; margin-bottom: 0.5rem;">Select Laboratory Tests:</div>
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
                </form>
            </div>
        </div>

        <!-- Filter Bar Card (Enhanced for Real-Time AJAX Filtering) -->
        <div class="card-box" style="padding: 0.85rem 1.25rem;">
            <div class="filter-bar" id="live-filter-form">
                <input type="hidden" id="filter_date" value="<?= h($selected_date) ?>">
                <span style="font-weight:600; font-size: 0.85rem; color: var(--text-muted);">Search & Filter:</span>
                <div style="position: relative; flex: 1; min-width: 220px; display: flex; align-items: center;">
                    <span style="position: absolute; left: 10px; font-size: 0.9rem; pointer-events: none;">🔍</span>
                    <input type="text" id="live_search_input" value="<?= h($search_query) ?>" placeholder="Type patient name..." style="width: 100%; padding-left: 30px; padding-right: 30px;" oninput="triggerLiveSearch()">
                    <button type="button" id="clear_search_btn" onclick="clearSearchBox()" style="position: absolute; right: 8px; background: none; border: none; font-size: 1rem; cursor: pointer; color: var(--text-muted); display: <?= !empty($search_query) ? 'block' : 'none' ?>;" title="Clear search">&times;</button>
                </div>
                <select id="filter_physician" onchange="triggerLiveSearch()">
                    <option value="0">All Physicians</option>
                    <?php foreach ($physicians_list as $doc): ?>
                        <option value="<?= (int)$doc['physician_id'] ?>" <?= $filter_physician === (int)$doc['physician_id'] ? 'selected' : '' ?>><?= h($doc['physician_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filter_meds_type" onchange="triggerLiveSearch()">
                    <option value="0">All Types</option>
                    <?php foreach ($meds_types_list as $mt): ?>
                        <option value="<?= (int)$mt['meds_type_id'] ?>" <?= $filter_meds_type === (int)$mt['meds_type_id'] ? 'selected' : '' ?>><?= h($mt['meds_type_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="search_status_indicator" style="font-size: 0.75rem; color: var(--text-muted); display: none;">Updating...</div>
            </div>
        </div>

        <!-- Records Table Card -->
        <div class="card-box">
            <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
                <span>Records (<span id="records_date_label"><?= h(date('F j, Y', strtotime($selected_date))) ?></span>)</span>
                <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: normal;">
                    <label for="max_lines_input" style="color: var(--text-muted);">Show lines:</label>
                    <input type="number" id="max_lines_input" value="8" min="3" max="50" style="width: 60px; padding: 0.25rem 0.5rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); outline: none;" onchange="updateTableHeight(this.value)" onkeyup="updateTableHeight(this.value)">
                    <span id="records_count_badge" style="color: var(--text-muted); background: #f1f5f9; padding: 0.2rem 0.6rem; border-radius: 20px;"><?= count($records_rows) ?> Patient(s)</span>
                </div>
            </div>

            <div class="table-responsive" id="table-scroll-container" style="max-height: 420px; overflow-y: auto;">
                <table class="custom-table" id="records-table">
                    <thead>
                        <tr style="position: sticky; top: 0; background: #f8fafc; z-index: 10;">
                            <th>Patient Name</th>
                            <th>Physician</th>
                            <th>Meds Type</th>
                            <th>Availed Services</th>
                            <th style="text-align: right;">Actions</th>
                            <th>Consultation</th>
                             <th style="text-align: center;">PCU</th>
                            <th style="text-align: center;">History</th>
                        </tr>
                    </thead>
                    <tbody id="records_table_body">
                        <?php if (empty($records_rows)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 3rem;" id="no_records_row">
                                    <?= $has_filters ? 'No records matching filters.' : 'No records logged for this date.' ?>
                                </td>
                            </tr>
                        <?php else: foreach ($records_rows as $row): $current_norm = normalize_patient_name($row['patient_name']); ?>
                            <tr class="clickable-row <?= !empty($row['second_tranche']) ? 'second-tranche-row' : '' ?>" onclick="openSummaryModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">
                                <td data-patient="<?= h($current_norm) ?>">
                                    <span class="patient-name-text <?= !empty($row['second_tranche']) ? 'second-tranche-name' : '' ?>"><?= h($row['patient_name']) ?></span>
                                    <?php if (!empty($row['second_tranche'])): ?>
                                        <span class="second-tranche-badge">✓ 2nd Tranche Encoded</span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['fpe_date'])): ?>
                                        <small style="display: block; margin-top: 0.2rem; color: var(--text-muted); font-size: 0.75rem;">
                                            First FPE: <?= h(date('M j, Y', strtotime($row['fpe_date']))) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['physician_name'] ?? '—') ?></td>
                                <td><?= h($row['meds_type_name'] ?? '—') ?></td>
                                <td>
                                    <div style="display:flex; gap: 0.25rem;">
                                        <span class="tag <?= $row['has_meds'] ? 'tag-success' : 'tag-gray' ?>">Meds</span>
                                        <span class="tag <?= $row['has_gamot_meds'] ? 'tag-success' : 'tag-gray' ?>">Gamot</span>
                                        <span class="tag <?= $row['has_labs'] ? 'tag-success' : 'tag-gray' ?>">Labs</span>
                                    </div>
                                </td>
                                <td style="text-align: right; vertical-align: middle;" onclick="event.stopPropagation()">
                                    <div style="display: flex; gap: 0.35rem; justify-content: flex-end;">
                                        <button type="button" class="btn btn-sm btn-outline" onclick="openEditModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">✏️ Edit</button>
                                        <form method="post" action="index.php" onsubmit="return confirm('Delete record for <?= h(addslashes($row['patient_name'])) ?>?');" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="record_id" value="<?= (int)$row['record_id'] ?>">
                                            <input type="hidden" name="record_date" value="<?= h($selected_date) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                                <td style="vertical-align: middle;" onclick="event.stopPropagation()">
                                    <a href="patient-consultation.php?q=<?= urlencode($row['patient_name']) ?>" class="btn btn-outline btn-sm patient-action-btn">Consultation</a>
                                </td>
                                <td style="text-align: center; vertical-align: middle;" onclick="event.stopPropagation()">
                                    <form method="post" action="index.php" style="display:inline;" class="pcu-toggle-form" onsubmit="return false;">
                                        <input type="hidden" name="action" value="toggle_pcu">
                                        <input type="hidden" name="ajax" value="1">
                                        <input type="hidden" name="patient_name" value="<?= h($row['patient_name']) ?>">
                                        <input type="hidden" name="record_date" value="<?= h($selected_date) ?>">
                                        <button type="button"
                                            class="btn btn-sm patient-action-btn <?= !empty($row['pcu_done']) ? 'btn-primary' : 'btn-outline' ?>"
                                            data-pcu-done="<?= !empty($row['pcu_done']) ? '1' : '0' ?>"
                                            data-patient="<?= h($row['patient_name']) ?>"
                                            onclick="togglePCU(this); return false;">
                                            <?= !empty($row['pcu_done']) ? '✓ PCU' : 'PCU' ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align: center; vertical-align: middle;" onclick="event.stopPropagation()">
                                    <button type="button" class="btn btn-outline btn-sm patient-action-btn" data-patient="<?= h($row['patient_name']) ?>" onclick="openPatientHistory(this.dataset.patient)">History</button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Global Patient List Array for Autocomplete Dropdown -->
<script>
function printDailyPatients() {
    // Set flag to auto-trigger print dialog after reload fetches fresh data
    sessionStorage.setItem('trigger_print_after_reload', 'true');
    window.location.reload();
}

// Auto-trigger print if flag is detected after reload
document.addEventListener('DOMContentLoaded', function() {
    if (sessionStorage.getItem('trigger_print_after_reload') === 'true') {
        sessionStorage.removeItem('trigger_print_after_reload');
        setTimeout(() => {
            const printableArea = document.getElementById('printable-patient-list');
            if (printableArea) {
                printableArea.style.display = 'block';
                window.print();
                printableArea.style.display = 'none';
            }
        }, 500);
    }
});

const globalPatientsList = [
    <?php foreach ($patients_list as $p): ?>
        <?= json_encode($p['patient_name']) ?>,
    <?php endforeach; ?>
];

function filterPatients(query, listId) {
    const listEl = document.getElementById(listId);
    if (!listEl) return;
    
    const q = query.trim().toLowerCase();
    const filtered = globalPatientsList.filter(name => name.toLowerCase().includes(q));
    
    if (filtered.length === 0 || q === '') {
        listEl.style.display = 'none';
        listEl.innerHTML = '';
        return;
    }
    
    let html = '';
    filtered.slice(0, 50).forEach(name => {
        html += `<li onclick="selectPatientName('${escapeJsString(name)}', '${listId}')">${escapeHtml(name)}</li>`;
    });
    
    listEl.innerHTML = html;
    listEl.style.display = 'block';
}

function selectPatientName(name, listId) {
    if (listId === 'add-suggestions') {
        const input = document.getElementById('add_patient_input');
        if (input) input.value = name;
    } else if (listId === 'edit-suggestions') {
        const input = document.getElementById('edit_patient_name');
        if (input) input.value = name;
    }
    const listEl = document.getElementById(listId);
    if (listEl) listEl.style.display = 'none';
}

// Close suggestion box on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-autocomplete-wrapper')) {
        document.querySelectorAll('.custom-suggestions-list').forEach(el => el.style.display = 'none');
    }
});

function escapeJsString(str) {
    return str.replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

// Real-Time Live Search & Filtering Implementation via Fetch AJAX
let searchDebounceTimer = null;

function triggerLiveSearch() {
    clearTimeout(searchDebounceTimer);
    const searchInput = document.getElementById('live_search_input');
    const clearBtn = document.getElementById('clear_search_btn');
    if (searchInput && clearBtn) {
        clearBtn.style.display = searchInput.value.trim().length > 0 ? 'block' : 'none';
    }
    
    // 150ms debounce for immediate responsive feel without hammering backend
    searchDebounceTimer = setTimeout(performLiveSearchAjax, 150);
}

function clearSearchBox() {
    const searchInput = document.getElementById('live_search_input');
    if (searchInput) {
        searchInput.value = '';
        triggerLiveSearch();
    }
}

function performLiveSearchAjax() {
    const searchVal = document.getElementById('live_search_input') ? document.getElementById('live_search_input').value.trim() : '';
    const physVal = document.getElementById('filter_physician') ? document.getElementById('filter_physician').value : '0';
    const medsTypeVal = document.getElementById('filter_meds_type') ? document.getElementById('filter_meds_type').value : '0';
    const dateVal = document.getElementById('filter_date') ? document.getElementById('filter_date').value : '<?= h($selected_date) ?>';
    const indicator = document.getElementById('search_status_indicator');

    if (indicator) indicator.style.display = 'inline';

    const params = new URLSearchParams({
        ajax: 'filter_records',
        date: dateVal,
        search: searchVal,
        filter_physician: physVal,
        filter_meds_type: medsTypeVal
    });

    fetch('index.php?' + params.toString(), {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) throw new Error('Live search request failed');
        return response.json();
    })
    .then(data => {
        if (!data || !data.success) throw new Error('Invalid response data');
        renderRecordsTable(data.records);
    })
    .catch(error => {
        console.error('Live search error:', error);
    })
    .finally(() => {
        if (indicator) indicator.style.display = 'none';
    });
}

function renderRecordsTable(records) {
    const tbody = document.getElementById('records_table_body');
    const badge = document.getElementById('records_count_badge');
    if (!tbody) return;

    if (badge) {
        badge.textContent = records.length + ' Patient(s)';
    }

    if (!records || records.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                    No records matching filters.
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    records.forEach(row => {
        const currentNorm = row.patient_name.toLowerCase();
        const isSecondTranche = parseInt(row.second_tranche) === 1;
        const pcuDone = parseInt(row.pcu_done) === 1;
        
        let fpeHtml = '';
        if (row.fpe_date) {
            const fpeDateFormatted = new Date(row.fpe_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            fpeHtml = `<small style="display: block; margin-top: 0.2rem; color: var(--text-muted); font-size: 0.75rem;">First FPE: ${escapeHtml(fpeDateFormatted)}</small>`;
        }

        let secondTrancheBadgeHtml = isSecondTranche ? '<span class="second-tranche-badge">✓ 2nd Tranche Encoded</span>' : '';
        let rowClass = isSecondTranche ? 'clickable-row second-tranche-row' : 'clickable-row';
        let nameClass = isSecondTranche ? 'patient-name-text second-tranche-name' : 'patient-name-text';

        const hasMedsTag = parseInt(row.has_meds) === 1 ? '<span class="tag tag-success">Meds</span>' : '<span class="tag tag-gray">Meds</span>';
        const hasGamotTag = parseInt(row.has_gamot_meds) === 1 ? '<span class="tag tag-success">Gamot</span>' : '<span class="tag tag-gray">Gamot</span>';
        const hasLabsTag = parseInt(row.has_labs) === 1 ? '<span class="tag tag-success">Labs</span>' : '<span class="tag tag-gray">Labs</span>';

        const recordJsonEsc = escapeHtml(JSON.stringify(row));

        html += `
            <tr class="${rowClass}" onclick='openSummaryModal(${recordJsonEsc})'>
                <td data-patient="${escapeHtml(currentNorm)}">
                    <span class="${nameClass}">${escapeHtml(row.patient_name)}</span>
                    ${secondTrancheBadgeHtml}
                    ${fpeHtml}
                </td>
                <td>${escapeHtml(row.physician_name || '—')}</td>
                <td>${escapeHtml(row.meds_type_name || '—')}</td>
                <td>
                    <div style="display:flex; gap: 0.25rem;">
                        ${hasMedsTag} ${hasGamotTag} ${hasLabsTag}
                    </div>
                </td>
                <td style="text-align: right; vertical-align: middle;" onclick="event.stopPropagation()">
                    <div style="display: flex; gap: 0.35rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-sm btn-outline" onclick='openEditModal(${recordJsonEsc})'>✏️ Edit</button>
                        <form method="post" action="index.php" onsubmit="return confirm('Delete record for ${escapeHtml(row.patient_name).replace(/'/g, "\\'")}?');" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="record_id" value="${row.record_id}">
                            <input type="hidden" name="record_date" value="${escapeHtml(row.record_date)}">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </div>
                </td>
                <td style="vertical-align: middle;" onclick="event.stopPropagation()">
                    <a href="patient-consultation.php?q=${encodeURIComponent(row.patient_name)}" class="btn btn-outline btn-sm patient-action-btn">Consultation</a>
                </td>
                <td style="text-align: center; vertical-align: middle;" onclick="event.stopPropagation()">
                    <form method="post" action="index.php" style="display:inline;" class="pcu-toggle-form" onsubmit="return false;">
                        <input type="hidden" name="action" value="toggle_pcu">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="patient_name" value="${escapeHtml(row.patient_name)}">
                        <input type="hidden" name="record_date" value="${escapeHtml(row.record_date)}">
                        <button type="button"
                            class="btn btn-sm patient-action-btn ${pcuDone ? 'btn-primary' : 'btn-outline'}"
                            data-pcu-done="${pcuDone ? '1' : '0'}"
                            data-patient="${escapeHtml(row.patient_name)}"
                            onclick="togglePCU(this); return false;">
                            ${pcuDone ? '✓ PCU' : 'PCU'}
                        </button>
                    </form>
                </td>
                <td style="text-align: center; vertical-align: middle;" onclick="event.stopPropagation()">
                    <button type="button" class="btn btn-outline btn-sm patient-action-btn" data-patient="${escapeHtml(row.patient_name)}" onclick="openPatientHistory(this.dataset.patient)">History</button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

// Dynamic row count height control for the table container
function updateTableHeight(numLines) {
    const container = document.getElementById('table-scroll-container');
    if (!container) return;

    const parsed = parseInt(numLines);
    const lines = (isNaN(parsed) || parsed < 1) ? 8 : parsed;
    const approximateRowHeight = 52; 
    const calculatedHeight = Math.max(150, lines * approximateRowHeight);
    
    container.style.maxHeight = calculatedHeight + 'px';
    localStorage.setItem('yakap_preferred_patient_lines', lines);
}

// Restore line user preference on page load
document.addEventListener('DOMContentLoaded', () => {
    const savedLines = localStorage.getItem('yakap_preferred_patient_lines');
    const input = document.getElementById('max_lines_input');
    if (savedLines && input) {
        input.value = savedLines;
        updateTableHeight(savedLines);
    } else {
        updateTableHeight(8);
    }
});
</script>

<!-- Patient Summary Details Modal -->
<div id="summaryModal" class="modal-overlay" onclick="closeSummaryModal(event)">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="card-title">
            <span id="summary_patient_name" style="color: var(--primary);">Patient Details</span>
            <button type="button" onclick="document.getElementById('summaryModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size: 1.5rem; color: var(--text-muted);">&times;</button>
        </div>
        <div style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; background: #f8fafc; padding: 0.75rem; border-radius: var(--radius-sm); display:flex; flex-direction:column; gap:0.25rem;">
            <div><strong>Physician:</strong> <span id="summary_physician"></span></div>
            <div><strong>Meds Type:</strong> <span id="summary_meds_type"></span></div>
            <div><strong>Record Date:</strong> <?= h(date('F j, Y', strtotime($selected_date))) ?></div>
        </div>
        <hr style="border: 0; border-top: 1px solid var(--border-color); margin-bottom: 1rem;">
        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <div>
                <div style="font-weight: 700; font-size: 0.875rem; color: #1d4ed8; margin-bottom: 0.5rem;">💊 Yakap Medicine</div>
                <div id="summary_meds_list"></div>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 0.875rem; color: #6b21a8; margin-bottom: 0.5rem;">💜 Gamot Medicine</div>
                <div id="summary_gamot_list"></div>
            </div>
            <div>
                <div style="font-weight: 700; font-size: 0.875rem; color: #047857; margin-bottom: 0.5rem;">🧪 Laboratory Tests</div>
                <div id="summary_labs_list"></div>
            </div>
        </div>
        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('summaryModal').style.display='none'">Close</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal-overlay" onclick="closeEditModal(event)">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="card-title">
            Edit Patient Record
            <button type="button" onclick="document.getElementById('editModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size: 1.5rem; color: var(--text-muted);">&times;</button>
        </div>
        <form method="post" action="index.php" autocomplete="off" onsubmit="return validatePatientRecordForm(this);">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="record_id" id="edit_record_id">
            <input type="hidden" name="record_date" value="<?= h($selected_date) ?>">
            <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                <div>
                    <label style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.25rem; display:block;">Patient Name *</label>
                    <div class="custom-autocomplete-wrapper">
                        <input type="text" name="patient_name" id="edit_patient_name" required style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); outline:none;" oninput="filterPatients(this.value, 'edit-suggestions')" onfocus="filterPatients(this.value, 'edit-suggestions')">
                        <ul id="edit-suggestions" class="custom-suggestions-list"></ul>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.25rem; display:block;">Physician</label>
                        <select name="physician_id" id="edit_physician_id" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); outline:none;">
                            <option value="">-- None --</option>
                            <?php foreach ($physicians_list as $p): ?>
                                <option value="<?= (int)$p['physician_id'] ?>"><?= h($p['physician_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.25rem; display:block;">Meds Type</label>
                        <select name="meds_type_id" id="edit_meds_type_id" style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); outline:none;">
                            <option value="">-- None --</option>
                            <?php foreach ($meds_types_list as $mt): ?>
                                <option value="<?= (int)$mt['meds_type_id'] ?>" <?= !empty($mt['is_consultation']) ? 'data-is-consultation="1"' : 'data-is-fpe="1"' ?>><?= h($mt['meds_type_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 1rem; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 0.75rem 0;">
                    <label class="select-card-box"><input type="checkbox" name="has_meds" id="edit_has_meds" value="1" onchange="toggleBox(this, 'edit-meds-box'); syncConsultationRequirements(this.form)"> Meds</label>
                    <label class="select-card-box"><input type="checkbox" name="has_gamot_meds" id="edit_has_gamot_meds" value="1" onchange="toggleBox(this, 'edit-gamot-box'); syncConsultationRequirements(this.form)"> Gamot</label>
                    <label class="select-card-box"><input type="checkbox" name="has_labs" id="edit_has_labs" value="1" onchange="toggleBox(this, 'edit-labs-box'); syncConsultationRequirements(this.form)"> Labs</label>
                </div>
                
                <!-- Edit Modal Standard Meds Selector -->
                <div id="edit-meds-box" class="toggle-selection-container">
                    <div style="font-size: 0.8rem; font-weight: 600; color: var(--primary); margin-bottom: 0.5rem;">Select Yakap Medicine:</div>
                    <?php foreach ($standard_meds_grouped as $category => $meds): ?>
                        <div class="group-title"><?= h($category) ?></div>
                        <div class="items-grid">
                            <?php foreach ($meds as $smed): ?>
                                <div class="select-card-box" onclick="toggleCard(this)"><input type="checkbox" class="edit-med-cb" name="medications[]" value="<?= h($smed) ?>"><span><?= h($smed) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Edit Modal Gamot Selector -->
                <div id="edit-gamot-box" class="toggle-selection-container" style="border-color: #e9d5ff;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: #7c3aed; margin-bottom: 0.5rem;">Select Gamot Medicine:</div>
                    <?php foreach ($available_medicines as $category => $meds): ?>
                        <div class="group-title gamot-title"><?= h($category) ?></div>
                        <div class="items-grid">
                            <?php foreach ($meds as $med): ?>
                                <div class="select-card-box" onclick="toggleCard(this)"><input type="checkbox" class="edit-gamot-cb" name="gamot_meds[]" value="<?= h($med) ?>"><span><?= h($med) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Edit Modal Labs Selector -->
                <div id="edit-labs-box" class="toggle-selection-container" style="border-color: #a7f3d0;">
                    <div style="font-size: 0.8rem; font-weight: 600; color: #047857; margin-bottom: 0.5rem;">Select Laboratory Tests:</div>
                    <?php foreach ($available_labs as $category => $labs): ?>
                        <div class="group-title lab-title"><?= h($category) ?></div>
                        <div class="items-grid">
                            <?php foreach ($labs as $lab): ?>
                                <div class="select-card-box" onclick="toggleCard(this)"><input type="checkbox" class="edit-lab-cb" name="labs[]" value="<?= h($lab) ?>"><span><?= h($lab) ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Patient History Modal -->
<div id="patient-history-modal" class="ph-modal" role="dialog" aria-modal="true" aria-labelledby="ph-title" hidden style="display:none;">
    <div class="ph-backdrop" onclick="closePatientHistory()"></div>
    <div class="ph-panel">
        <div class="ph-header">
            <div class="ph-header-text">
                <p class="ph-eyebrow">🕘 Patient History</p>
                <h3 class="ph-title" id="ph-title">Patient History</h3>
                <p class="ph-subtitle" id="ph-subtitle">Full visit record</p>
            </div>
            <button type="button" class="ph-close" id="ph-close" onclick="closePatientHistory()" aria-label="Close">&times;</button>
        </div>
        <div class="ph-body" id="patient-history-body">
            <div class="ph-loading">
                <div class="ph-spinner"></div>
                <p>Loading history…</p>
            </div>
        </div>
    </div>
</div>

<script>
function toggleCard(cardElement) {
    const cb = cardElement.querySelector('input[type="checkbox"]');
    if (cb) {
        cb.checked = !cb.checked;
        cb.checked ? cardElement.classList.add('active') : cardElement.classList.remove('active');
    }
}

function syncCardStates(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.querySelectorAll('.select-card-box').forEach(card => {
        const cb = card.querySelector('input[type="checkbox"]');
        cb && cb.checked ? card.classList.add('active') : card.classList.remove('active');
    });
}

function toggleBox(checkbox, boxId) {
    const el = document.getElementById(boxId);
    if (el) el.style.display = checkbox.checked ? 'block' : 'none';
}


function formHasConsultationServices(form) {
    if (!form) return false;
    return ['has_meds', 'has_labs', 'has_gamot_meds'].some(function(name) {
        const cb = form.querySelector('input[name="' + name + '"]');
        return !!(cb && cb.checked);
    });
}

function getConsultationOption(select) {
    if (!select) return null;
    return Array.from(select.options).find(function(option) {
        return option.dataset && option.dataset.isConsultation === '1';
    }) || Array.from(select.options).find(function(option) {
        return option.textContent.trim().toLowerCase() === 'consultation';
    });
}

function isConsultationSelection(select) {
    const option = select && select.selectedOptions && select.selectedOptions[0];
    return !!(option && (
        option.dataset.isConsultation === '1' ||
        option.textContent.trim().toLowerCase() === 'consultation'
    ));
}

function syncConsultationRequirements(form) {
    if (!form) return;

    const medsType = form.querySelector('select[name="meds_type_id"]');
    const physician = form.querySelector('select[name="physician_id"]');
    if (!medsType || !physician) return;

    const hasServices = formHasConsultationServices(form);

    // Checking Meds, Labs, or Gamot nudges the visit type to Consultation —
    // but only as a default. If the field is already on some consultation-
    // type option (whether auto-set earlier or picked manually), leave it
    // alone so the user can still edit it freely.
    if (hasServices) {
        if (!isConsultationSelection(medsType)) {
            const consultationOption = getConsultationOption(medsType);
            if (consultationOption) {
                medsType.value = consultationOption.value;
                medsType.dataset.autoConsultation = '1';
            }
        }
    } else if (medsType.dataset.autoConsultation === '1') {
        // Once all service checkboxes are cleared, allow the user to choose the visit type again.
        medsType.value = '';
        delete medsType.dataset.autoConsultation;
    }

    const consultationSelected = hasServices || isConsultationSelection(medsType);
    physician.required = consultationSelected;

    const physicianLabel = form.querySelector('label[for="pc_physician"], label[for="edit_physician_id"]');
    if (physicianLabel) {
        physicianLabel.textContent = consultationSelected ? 'Physician *' : 'Physician';
    }

    // Clear visual warning once a physician has been selected.
    physician.style.borderColor = consultationSelected && !physician.value ? '#ef4444' : '';
}

function validatePatientRecordForm(form) {
    syncConsultationRequirements(form);

    const medsType = form.querySelector('select[name="meds_type_id"]');
    const physician = form.querySelector('select[name="physician_id"]');
    const hasServices = formHasConsultationServices(form);
    const isConsultation = hasServices || isConsultationSelection(medsType);

    if (isConsultation && (!physician || !physician.value)) {
        if (physician) {
            physician.required = true;
            physician.focus();
        }
        alert('A physician is required for a Consultation. Please select a physician before saving.');
        return false;
    }

    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[action="index.php"]').forEach(function(form) {
        if (form.querySelector('input[name="action"][value="add"], input[name="action"][value="update"]')) {
            syncConsultationRequirements(form);
        }
    });

    document.querySelectorAll('select[name="meds_type_id"]').forEach(function(select) {
        select.addEventListener('change', function() {
            const form = select.form;
            if (!form) return;
            // A manual choice is respected as-is, as long as it's a
            // consultation-type option. Only nudge it back to Consultation
            // if the user picked an FPE/intake type while a service is
            // still checked (services can't attach to an FPE visit).
            if (formHasConsultationServices(form) && !isConsultationSelection(select)) {
                const consultationOption = getConsultationOption(select);
                if (consultationOption) select.value = consultationOption.value;
            }
            // This was a manual edit, not an auto-fill — don't treat it as
            // something to auto-clear later when checkboxes are unchecked.
            delete select.dataset.autoConsultation;
            syncConsultationRequirements(form);
        });
    });
});

function closeSummaryModal(event) {
    if (event.target.id === 'summaryModal') document.getElementById('summaryModal').style.display = 'none';
}

function closeEditModal(event) {
    if (event.target.id === 'editModal') document.getElementById('editModal').style.display = 'none';
}

function openSummaryModal(record) {
    document.getElementById('summary_patient_name').innerText = record.patient_name;
    document.getElementById('summary_physician').innerText = record.physician_name || '—';
    document.getElementById('summary_meds_type').innerText = record.meds_type_name || '—';

    let savedMeds = [], savedGamot = [], savedLabs = [];
    try { savedMeds = JSON.parse(record.record_medications) || []; } catch(e) {}
    try { savedGamot = JSON.parse(record.record_gamot) || []; } catch(e) {}
    try { savedLabs = JSON.parse(record.record_labs) || []; } catch(e) {}

    document.getElementById('summary_meds_list').innerHTML = savedMeds.length > 0 ? savedMeds.map(m => `<span class="med-chip">💊 ${escapeHtml(m)}</span>`).join('') : '<span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">None recorded.</span>';
    document.getElementById('summary_gamot_list').innerHTML = savedGamot.length > 0 ? savedGamot.map(g => `<span class="gamot-chip">💜 ${escapeHtml(g)}</span>`).join('') : '<span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">None recorded.</span>';
    document.getElementById('summary_labs_list').innerHTML = savedLabs.length > 0 ? savedLabs.map(l => `<span class="lab-chip">🧪 ${escapeHtml(l)}</span>`).join('') : '<span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">None recorded.</span>';

    document.getElementById('summaryModal').style.display = 'flex';
}

function openEditModal(record) {
    document.getElementById('edit_record_id').value = record.record_id;
    document.getElementById('edit_patient_name').value = record.patient_name;
    document.getElementById('edit_physician_id').value = record.physician_id || '';
    document.getElementById('edit_meds_type_id').value = record.meds_type_id || '';

    const cbMeds = document.getElementById('edit_has_meds');
    const cbGamot = document.getElementById('edit_has_gamot_meds');
    const cbLabs = document.getElementById('edit_has_labs');

    cbMeds.checked = parseInt(record.has_meds) === 1;
    cbGamot.checked = parseInt(record.has_gamot_meds) === 1;
    cbLabs.checked = parseInt(record.has_labs) === 1;

    toggleBox(cbMeds, 'edit-meds-box');
    toggleBox(cbGamot, 'edit-gamot-box');
    toggleBox(cbLabs, 'edit-labs-box');

    let savedMeds = [], savedGamot = [], savedLabs = [];
    try { savedMeds = JSON.parse(record.record_medications) || []; } catch(e) {}
    try { savedGamot = JSON.parse(record.record_gamot) || []; } catch(e) {}
    try { savedLabs = JSON.parse(record.record_labs) || []; } catch(e) {}

    document.querySelectorAll('.edit-med-cb').forEach(cb => cb.checked = savedMeds.includes(cb.value));
    document.querySelectorAll('.edit-gamot-cb').forEach(cb => cb.checked = savedGamot.includes(cb.value));
    document.querySelectorAll('.edit-lab-cb').forEach(cb => cb.checked = savedLabs.includes(cb.value));

    syncCardStates('edit-meds-box');
    syncCardStates('edit-gamot-box');
    syncCardStates('edit-labs-box');

    document.getElementById('editModal').style.display = 'flex';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.innerText = text;
    return div.innerHTML;
}

function openPatientHistory(patientName) {
    const modal = document.getElementById('patient-history-modal');
    const title = document.getElementById('ph-title');
    const subtitle = document.getElementById('ph-subtitle');
    const body = document.getElementById('patient-history-body');
    if (!modal || !body) return;

    title.textContent = patientName || 'Patient History';
    if (subtitle) subtitle.textContent = 'Full visit record';

    modal.hidden = false;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    // Force reflow so the opacity transition actually plays.
    requestAnimationFrame(() => modal.classList.add('ph-open'));

    body.innerHTML = '<div class="ph-loading"><div class="ph-spinner"></div><p>Loading history…</p></div>';

    fetch('patient_history.php?embed=1&patient_name=' + encodeURIComponent(patientName))
        .then(response => {
            if (!response.ok) throw new Error('Network error');
            return response.text();
        })
        .then(html => {
            const trimmed = (html || '').trim();
            if (!trimmed) {
                body.innerHTML = '<div class="ph-empty-state"><div class="ph-state-icon">🗒️</div><strong>No visits recorded yet</strong><p>This patient has no history on file.</p></div>';
                return;
            }

            // patient_history.php returns its own full standalone page (own
            // <head>/<style>/nav). Injecting that as-is duplicates page chrome,
            // leaks conflicting styles, and any links inside it navigate the
            // whole app away instead of staying inside this overlay. Parse it
            // and keep only the body's content, stripped of scripts/styles.
            let fragment = trimmed;
            try {
                const parsed = new DOMParser().parseFromString(trimmed, 'text/html');
                if (parsed && parsed.body) {
                    parsed.body.querySelectorAll('script, header, nav').forEach(el => el.remove());
                    fragment = parsed.body.innerHTML.trim() || fragment;
                }
            } catch (e) { /* fall back to raw html if parsing fails */ }

            body.innerHTML = fragment || '<div class="ph-empty-state"><div class="ph-state-icon">🗒️</div><strong>No visits recorded yet</strong><p>This patient has no history on file.</p></div>';
        })
        .catch(err => {
            body.innerHTML = '<div class="ph-error-state"><div class="ph-state-icon">⚠️</div><strong>Could not load history</strong><p>Please check your connection and try again.</p></div>';
        });
}

// Any same-tab link rendered inside the history panel should never navigate
// the whole app away — this is a read-only overlay, not a page. Links the
// fragment deliberately marks target="_blank" (CSV export, jump-to-date)
// are left alone so they open safely in a new tab instead.
document.addEventListener('click', function (e) {
    const body = document.getElementById('patient-history-body');
    if (!body || !body.contains(e.target)) return;
    const link = e.target.closest('a[href]');
    if (link && link.target !== '_blank') e.preventDefault();
});

function closePatientHistory() {
    const modal = document.getElementById('patient-history-modal');
    if (!modal) return;
    modal.classList.remove('ph-open');
    document.body.style.overflow = '';
    // Wait for the fade-out to finish before actually hiding it.
    setTimeout(() => {
        modal.hidden = true;
        modal.style.display = 'none';
    }, 200);
}

document.addEventListener('keydown', e => {
    const modal = document.getElementById('patient-history-modal');
    if (e.key === 'Escape' && modal && !modal.hidden) closePatientHistory();
});
</script>


<script>
/*
 * PCU button: STATUS ONLY.
 * Clicking PCU only marks/unmarks the patient as PCU.
 * It does not open PhilHealth, a modal, another page, or an API.
 */
function togglePCU(button) {
    var form = button.closest('.pcu-toggle-form');
    if (!form || button.dataset.loading === '1') return false;

    button.dataset.loading = '1';
    button.disabled = true;

    var formData = new FormData(form);
    formData.set('ajax', '1');

    fetch(form.getAttribute('action') || 'index.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        credentials: 'same-origin'
    })
    .then(function(response) {
        if (!response.ok) throw new Error('PCU request failed');
        return response.json();
    })
    .then(function(data) {
        if (!data || !data.success) throw new Error('PCU update failed');

        if (data.pcu_done) {
            button.textContent = '✓ PCU';
            button.dataset.pcuDone = '1';
            button.classList.remove('btn-outline');
            button.classList.add('btn-primary');
        } else {
            button.textContent = 'PCU';
            button.dataset.pcuDone = '0';
            button.classList.remove('btn-primary');
            button.classList.add('btn-outline');
        }
    })
    .catch(function(error) {
        console.error(error);
        alert('PCU status could not be updated.');
    })
    .finally(function() {
        button.disabled = false;
        delete button.dataset.loading;
    });

    return false;
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('toggle-add-patient');
    const wrap = document.getElementById('add-patient-form-wrap');

    if (toggle && wrap) {
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            wrap.hidden = !wrap.hidden;
            const open = !wrap.hidden;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.textContent = open ? '− Hide Form' : '+ Add Patient';

            if (open) {
                const input = document.getElementById('add_patient_input');
                if (input) setTimeout(() => input.focus(), 50);
            }
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>