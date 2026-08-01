<?php
/**
 * YAKAP GAMOT SYSTEM - Daily Log (index.php)
 */

require_once 'config.php';
require_once 'includes/auth.php';

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

// --- 1. HANDLE CSV EXPORT ---
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
        $med_list = !empty($row['record_medications']) ? implode(', ', json_decode($row['record_medications'], true) ?? []) : '';
        $gamot_list = !empty($row['record_gamot']) ? implode(', ', json_decode($row['record_gamot'], true) ?? []) : '';
        $lab_list = !empty($row['record_labs']) ? implode(', ', json_decode($row['record_labs'], true) ?? []) : '';
        
        $export_data[] = [
            'Record Date'   => $row['record_date'],
            'Patient Name'  => $row['patient_name'],
            'Physician'     => $row['physician'],
            'Meds Type'     => $row['meds_type'],
            'Has Meds'      => $row['has_meds'] ? 'YES' : 'NO',
            'Selected Meds' => $med_list,
            'Has Gamot'     => $row['has_gamot_meds'] ? 'YES' : 'NO',
            'Selected Gamot'=> $gamot_list,
            'Has Labs'      => $row['has_labs'] ? 'YES' : 'NO',
            'Selected Labs' => $lab_list
        ];
    }
    $exp_stmt->close();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="daily_log_' . $exp_date . '.csv"');
    $output = fopen('php://output', 'w');
    if (!empty($export_data)) {
        fputcsv($output, array_keys($export_data[0]));
        foreach ($export_data as $data_row) {
            fputcsv($output, $data_row);
        }
    }
    fclose($output);
    exit;
}

// --- 2. HANDLE FORM ACTIONS (ADD / UPDATE / DELETE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $record_date = $_POST['record_date'] ?? '';
        $patient_name = trim($_POST['patient_name'] ?? '');
        $physician_id = !empty($_POST['physician_id']) ? (int)$_POST['physician_id'] : null;
        $meds_type_id = !empty($_POST['meds_type_id']) ? (int)$_POST['meds_type_id'] : null;
        $has_meds = isset($_POST['has_meds']) ? 1 : 0;
        $has_labs = isset($_POST['has_labs']) ? 1 : 0;
        $has_gamot_meds = isset($_POST['has_gamot_meds']) ? 1 : 0;
        
        $selected_meds = ($has_meds && isset($_POST['medications']) && is_array($_POST['medications'])) 
            ? json_encode(array_values($_POST['medications'])) 
            : null;

        $selected_gamot = ($has_gamot_meds && isset($_POST['gamot_meds']) && is_array($_POST['gamot_meds'])) 
            ? json_encode(array_values($_POST['gamot_meds'])) 
            : null;

        $selected_labs = ($has_labs && isset($_POST['labs']) && is_array($_POST['labs'])) 
            ? json_encode(array_values($_POST['labs'])) 
            : null;

        if (!empty($record_date) && !empty($patient_name)) {
            $stmt = $conn->prepare("INSERT INTO daily_records (record_date, patient_name, physician_id, meds_type_id, has_meds, record_medications, has_gamot_meds, record_gamot, has_labs, record_labs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiisisis", $record_date, $patient_name, $physician_id, $meds_type_id, $has_meds, $selected_meds, $has_gamot_meds, $selected_gamot, $has_labs, $selected_labs);
            if ($stmt->execute()) {
                $sync = $conn->prepare("INSERT IGNORE INTO patients (patient_name) VALUES (?)");
                $sync->bind_param("s", $patient_name);
                $sync->execute();
                $sync->close();
                redirect_with_msg('index.php?date=' . $record_date, 'Record added successfully.');
            } else { 
                $msg = 'Error: ' . $stmt->error; 
            }
            $stmt->close();
        } else { 
            $msg = 'Date and patient name are required.'; 
        }
    } elseif ($action === 'update') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $patient_name = trim($_POST['patient_name'] ?? '');
        $physician_id = !empty($_POST['physician_id']) ? (int)$_POST['physician_id'] : null;
        $meds_type_id = !empty($_POST['meds_type_id']) ? (int)$_POST['meds_type_id'] : null;
        $has_meds = isset($_POST['has_meds']) ? 1 : 0;
        $has_labs = isset($_POST['has_labs']) ? 1 : 0;
        $has_gamot_meds = isset($_POST['has_gamot_meds']) ? 1 : 0;
        $record_date = $_POST['record_date'] ?? '';

        $selected_meds = ($has_meds && isset($_POST['medications']) && is_array($_POST['medications'])) 
            ? json_encode(array_values($_POST['medications'])) 
            : null;

        $selected_gamot = ($has_gamot_meds && isset($_POST['gamot_meds']) && is_array($_POST['gamot_meds'])) 
            ? json_encode(array_values($_POST['gamot_meds'])) 
            : null;

        $selected_labs = ($has_labs && isset($_POST['labs']) && is_array($_POST['labs'])) 
            ? json_encode(array_values($_POST['labs'])) 
            : null;

        if ($record_id > 0 && !empty($patient_name)) {
            $stmt = $conn->prepare("UPDATE daily_records SET patient_name = ?, physician_id = ?, meds_type_id = ?, has_meds = ?, record_medications = ?, has_gamot_meds = ?, record_gamot = ?, has_labs = ?, record_labs = ? WHERE record_id = ?");
            $stmt->bind_param("siiisisisi", $patient_name, $physician_id, $meds_type_id, $has_meds, $selected_meds, $has_gamot_meds, $selected_gamot, $has_labs, $selected_labs, $record_id);
            $stmt->execute() ? redirect_with_msg('index.php?date=' . $record_date, 'Record updated successfully.') : ($msg = 'Error: ' . $stmt->error);
            $stmt->close();
        } else { 
            $msg = 'Invalid update request.'; 
        }
    } elseif ($action === 'delete') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $record_date = $_POST['record_date'] ?? '';
        if ($record_id > 0) {
            $stmt = $conn->prepare("DELETE FROM daily_records WHERE record_id = ?");
            $stmt->bind_param("i", $record_id);
            $stmt->execute() ? redirect_with_msg('index.php?date=' . $record_date, 'Record deleted successfully.') : ($msg = 'Error: ' . $stmt->error);
            $stmt->close();
        }
    }
}

// --- 3. FETCH DATA ---
$selected_date = selected_date();
$search_query = trim($_GET['search'] ?? '');
$filter_physician = isset($_GET['filter_physician']) ? (int)$_GET['filter_physician'] : 0;
$filter_meds_type = isset($_GET['filter_meds_type']) ? (int)$_GET['filter_meds_type'] : 0;

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

$total_dates = (int)$conn->query("SELECT COUNT(DISTINCT record_date) AS cnt FROM daily_records")->fetch_assoc()['cnt'];
$total_pages = max(1, ceil($total_dates / $per_page));
$dates_result = $conn->query("SELECT record_date, COUNT(*) as total FROM daily_records GROUP BY record_date ORDER BY record_date DESC LIMIT $per_page OFFSET $offset");

$physicians_list = $conn->query("SELECT physician_id, physician_name FROM physicians WHERE is_active = 1 ORDER BY physician_name")->fetch_all(MYSQLI_ASSOC);
$meds_types_list = $conn->query("SELECT meds_type_id, meds_type_name FROM meds_types ORDER BY meds_type_name")->fetch_all(MYSQLI_ASSOC);
$patients_list = $conn->query("SELECT DISTINCT patient_name FROM patients ORDER BY patient_name")->fetch_all(MYSQLI_ASSOC);

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
    SELECT dr.*, p.physician_name, mt.meds_type_name 
    FROM daily_records dr 
    LEFT JOIN physicians p ON dr.physician_id = p.physician_id 
    LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id 
    WHERE " . implode(" AND ", $where_clauses) . " 
    ORDER BY dr.created_at ASC
";
$records_stmt = $conn->prepare($query);
$records_stmt->bind_param($types, ...$params);
$records_stmt->execute();
$records_rows = $records_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$records_stmt->close();

$has_filters = (!empty($search_query) || $filter_physician > 0 || $filter_meds_type > 0);

include 'includes/header.php';
?>

<style>
:root {
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --bg-surface: #ffffff;
    --bg-body: #f8fafc;
    --border-color: #e2e8f0;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --success: #10b981;
    --purple: #8b5cf6;
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
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 1.25rem;
}

.card-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.date-picker-group {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.date-picker-group input {
    flex: 1;
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
}

.date-nav-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.date-nav-item a {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.6rem 0.75rem;
    border-radius: 6px;
    color: var(--text-main);
    text-decoration: none;
    background: #f1f5f9;
    transition: all 0.2s;
    font-size: 0.875rem;
}

.date-nav-item.active a, .date-nav-item a:hover {
    background: var(--primary);
    color: #fff;
}

.date-nav-item.active .badge, .date-nav-item a:hover .badge {
    background: rgba(255,255,255,0.2);
    color: #fff;
}

.badge {
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    background: #e2e8f0;
    border-radius: 12px;
    color: var(--text-muted);
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
}

.custom-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 0.9rem;
}

.custom-table th {
    background: #f8fafc;
    padding: 0.75rem;
    color: var(--text-muted);
    font-weight: 600;
    border-bottom: 2px solid var(--border-color);
}

.custom-table td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

/* CLICKABLE ROW FOR SUMMARY MODAL */
.clickable-row {
    cursor: pointer;
    transition: background-color 0.15s ease-in-out;
}

.clickable-row:hover {
    background-color: #f1f5f9;
}

.patient-name-text {
    color: var(--primary);
    font-weight: 600;
}

.tag {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.tag-success { background: #d1fae5; color: #065f46; }
.tag-gray { background: #f1f5f9; color: #94a3b8; }

.med-chip {
    display: inline-block;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    margin: 0.2rem;
    font-weight: 500;
}

.gamot-chip {
    display: inline-block;
    background: #f3e8ff;
    color: #6b21a8;
    border: 1px solid #e9d5ff;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    margin: 0.2rem;
    font-weight: 500;
}

.lab-chip {
    display: inline-block;
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    margin: 0.2rem;
    font-weight: 500;
}

.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.filter-bar input, .filter-bar select {
    padding: 0.4rem 0.6rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.875rem;
}

.add-record-panel {
    background: #eff6ff;
    border: 1px dashed #93c5fd;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.25rem;
}

.inline-form-top {
    display: grid;
    grid-template-columns: 2fr 1.5fr 1.5fr repeat(3, auto) auto;
    gap: 0.75rem;
    align-items: center;
}

@media (max-width: 1100px) {
    .inline-form-top { grid-template-columns: 1fr 1fr; }
}

/* COMPACT FULL BOX CLICKABLE SELECTION ITEM */
.select-card-box {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.45rem;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 0.75rem;
    cursor: pointer;
    user-select: none;
    transition: all 0.15s ease-in-out;
}

.select-card-box:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

.select-card-box input[type="checkbox"] {
    cursor: pointer;
    pointer-events: none; /* Passes click directly to parent box */
    transform: scale(0.9);
}

.select-card-box.active {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1d4ed8;
    font-weight: 600;
}

.toggle-selection-container {
    margin-top: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    display: none;
    max-height: 220px;
    overflow-y: auto;
}

.group-title {
    font-weight: 700;
    font-size: 0.7rem;
    text-transform: uppercase;
    color: var(--primary);
    margin: 0.35rem 0 0.15rem 0;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 0.1rem;
}

.group-title.lab-title { color: #059669; }
.group-title.gamot-title { color: #7c3aed; }
.group-title:first-child { margin-top: 0; }

.items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 0.25rem;
}

.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 999;
}

.modal-card {
    background: #fff;
    border-radius: 12px;
    width: 100%;
    max-width: 680px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
    <h2>📋 Daily Log</h2>
    <div>
        <a href="?date=<?= h($selected_date) ?>&export=csv" class="btn btn-blue btn-sm">📥 Export CSV</a>
    </div>
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
            <button type="button" class="btn btn-sm btn-outline" style="width: 100%; margin-bottom: 1rem;" onclick="document.getElementById('jump_date').value='<?= date('Y-m-d') ?>';this.form.submit();">
                📅 Jump to Today
            </button>

            <div class="card-title">Recent Logs</div>
            <ul class="date-nav-list">
                <?php if ($dates_result->num_rows === 0): ?>
                    <li style="color: var(--text-muted); font-size: 0.85rem; text-align: center; padding: 0.5rem;">No logs found.</li>
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
            <div style="display:flex; justify-content:space-between; align-items: center; margin-top: 1rem; font-size: 0.8rem;">
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
        <div class="add-record-panel">
            <div style="font-weight: 600; font-size: 0.9rem; margin-bottom: 0.5rem; color: var(--primary-dark);">
                ➕ Add New Record for <?= h(date('F j, Y', strtotime($selected_date))) ?>
            </div>
            <form method="post" action="index.php">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="record_date" value="<?= h($selected_date) ?>">

                <div class="inline-form-top">
                    <input type="text" name="patient_name" placeholder="Patient Name *" required list="patient-list" style="padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 6px;">

                    <select name="physician_id" style="padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 6px;">
                        <option value="">-- Physician --</option>
                        <?php foreach ($physicians_list as $p): ?>
                            <option value="<?= (int)$p['physician_id'] ?>"><?= h($p['physician_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="meds_type_id" style="padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 6px;">
                        <option value="">-- Meds Type --</option>
                        <?php foreach ($meds_types_list as $mt): ?>
                            <option value="<?= (int)$mt['meds_type_id'] ?>"><?= h($mt['meds_type_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="select-card-box" style="background:#fff;">
                        <input type="checkbox" name="has_meds" value="1" onchange="toggleBox(this, 'add-meds-box')"> Meds
                    </label>
                    <label class="select-card-box" style="background:#fff;">
                        <input type="checkbox" name="has_labs" value="1" onchange="toggleBox(this, 'add-labs-box')"> Labs
                    </label>
                    <label class="select-card-box" style="background:#fff;">
                        <input type="checkbox" name="has_gamot_meds" value="1" onchange="toggleBox(this, 'add-gamot-box')"> Gamot
                    </label>

                    <button type="submit" class="btn btn-primary btn-sm">Add Record</button>
                </div>

                <!-- Add Form Standard 21 MEDS Selector -->
                <div id="add-meds-box" class="toggle-selection-container">
                    <div style="font-size: 0.75rem; font-weight: 600; color: var(--primary); margin-bottom: 0.35rem;">Select Standard Medicines:</div>
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

                <!-- Add Form GAMOT Medicine Selector -->
                <div id="add-gamot-box" class="toggle-selection-container" style="border-color: #e9d5ff;">
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

                <!-- Add Form Labs Selector -->
                <div id="add-labs-box" class="toggle-selection-container" style="border-color: #a7f3d0;">
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
            </form>
        </div>

        <!-- Filter Bar Card -->
        <div class="card-box" style="padding: 0.75rem 1.25rem;">
            <form method="get" action="index.php" class="filter-bar">
                <input type="hidden" name="date" value="<?= h($selected_date) ?>">
                
                <span style="font-weight:600; font-size: 0.85rem; color: var(--text-muted);">Filters:</span>
                
                <input type="text" name="search" value="<?= h($search_query) ?>" placeholder="Search patient..." style="width: 160px;">

                <select name="filter_physician">
                    <option value="0">All Physicians</option>
                    <?php foreach ($physicians_list as $doc): ?>
                        <option value="<?= (int)$doc['physician_id'] ?>" <?= $filter_physician === (int)$doc['physician_id'] ? 'selected' : '' ?>><?= h($doc['physician_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="filter_meds_type">
                    <option value="0">All Types</option>
                    <?php foreach ($meds_types_list as $mt): ?>
                        <option value="<?= (int)$mt['meds_type_id'] ?>" <?= $filter_meds_type === (int)$mt['meds_type_id'] ? 'selected' : '' ?>><?= h($mt['meds_type_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($has_filters): ?>
                    <a href="index.php?date=<?= h($selected_date) ?>" class="btn btn-sm btn-outline">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Records Table Card -->
        <div class="card-box">
            <div class="card-title">
                Records (<?= h(date('F j, Y', strtotime($selected_date))) ?>)
                <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-muted);"><?= count($records_rows) ?> Entry(ies)</span>
            </div>

            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Patient Name</th>
                            <th>Physician</th>
                            <th>Meds Type</th>
                            <th>Availed Services</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records_rows)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                    <?= $has_filters ? 'No records matching filters.' : 'No records logged for this date.' ?>
                                </td>
                            </tr>
                        <?php else: foreach ($records_rows as $row): ?>
                            <tr class="clickable-row" onclick="openSummaryModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">
                                <td>
                                    <span class="patient-name-text"><?= h($row['patient_name']) ?></span>
                                </td>
                                <td><?= h($row['physician_name'] ?? '—') ?></td>
                                <td><?= h($row['meds_type_name'] ?? '—') ?></td>
                                <td>
                                    <div>
                                        <span class="tag <?= $row['has_meds'] ? 'tag-success' : 'tag-gray' ?>">Meds</span>
                                        <span class="tag <?= $row['has_gamot_meds'] ? 'tag-success' : 'tag-gray' ?>">Gamot</span>
                                        <span class="tag <?= $row['has_labs'] ? 'tag-success' : 'tag-gray' ?>">Labs</span>
                                    </div>
                                </td>
                                <td style="text-align: right; vertical-align: middle;" onclick="event.stopPropagation()">
                                    <div style="display: flex; gap: 0.35rem; justify-content: flex-end;">
                                        <button 
                                            type="button" 
                                            class="btn btn-sm btn-outline"
                                            onclick="openEditModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">
                                            ✏️ Edit
                                        </button>
                                        
                                        <form method="post" action="index.php" onsubmit="return confirm('Delete record for <?= h(addslashes($row['patient_name'])) ?>?');" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="record_id" value="<?= (int)$row['record_id'] ?>">
                                            <input type="hidden" name="record_date" value="<?= h($selected_date) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Autocomplete Datalist -->
<datalist id="patient-list">
    <?php foreach ($patients_list as $p): ?>
        <option value="<?= h($p['patient_name']) ?>">
    <?php endforeach; ?>
</datalist>

<!-- Patient Summary Details Modal -->
<div id="summaryModal" class="modal-overlay" onclick="closeSummaryModal(event)">
    <div class="modal-card" onclick="event.stopPropagation()">
        <div class="card-title">
            <span id="summary_patient_name" style="color: var(--primary);">Patient Details</span>
            <button type="button" onclick="document.getElementById('summaryModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size: 1.2rem;">&times;</button>
        </div>

        <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
            <div><strong>Physician:</strong> <span id="summary_physician"></span></div>
            <div><strong>Meds Type:</strong> <span id="summary_meds_type"></span></div>
            <div><strong>Record Date:</strong> <?= h(date('F j, Y', strtotime($selected_date))) ?></div>
        </div>

        <hr style="border: 0; border-top: 1px solid var(--border-color); margin-bottom: 1rem;">

        <div style="display: flex; flex-direction: column; gap: 1.25rem;">
            <!-- Availed Standard Medicines -->
            <div>
                <div style="font-weight: 700; font-size: 0.875rem; color: #1d4ed8; margin-bottom: 0.5rem;">
                    💊 Standard Medicines
                </div>
                <div id="summary_meds_list"></div>
            </div>

            <!-- Availed Gamot -->
            <div>
                <div style="font-weight: 700; font-size: 0.875rem; color: #6b21a8; margin-bottom: 0.5rem;">
                    💜 PhilHealth YAKAP Gamot
                </div>
                <div id="summary_gamot_list"></div>
            </div>

            <!-- Availed Labs -->
            <div>
                <div style="font-weight: 700; font-size: 0.875rem; color: #047857; margin-bottom: 0.5rem;">
                    🧪 Laboratory Tests
                </div>
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
            <button type="button" onclick="document.getElementById('editModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size: 1.2rem;">&times;</button>
        </div>
        
        <form method="post" action="index.php">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="record_id" id="edit_record_id">
            <input type="hidden" name="record_date" value="<?= h($selected_date) ?>">

            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <div>
                    <label style="font-size: 0.8rem; font-weight: 600;">Patient Name *</label>
                    <input type="text" name="patient_name" id="edit_patient_name" required style="width: 100%; padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 6px;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label style="font-size: 0.8rem; font-weight: 600;">Physician</label>
                        <select name="physician_id" id="edit_physician_id" style="width: 100%; padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 6px;">
                            <option value="">-- None --</option>
                            <?php foreach ($physicians_list as $p): ?>
                                <option value="<?= (int)$p['physician_id'] ?>"><?= h($p['physician_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 0.8rem; font-weight: 600;">Meds Type</label>
                        <select name="meds_type_id" id="edit_meds_type_id" style="width: 100%; padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 6px;">
                            <option value="">-- None --</option>
                            <?php foreach ($meds_types_list as $mt): ?>
                                <option value="<?= (int)$mt['meds_type_id'] ?>"><?= h($mt['meds_type_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 0.5rem 0;">
                    <label class="select-card-box">
                        <input type="checkbox" name="has_meds" id="edit_has_meds" value="1" onchange="toggleBox(this, 'edit-meds-box')"> Meds
                    </label>
                    <label class="select-card-box">
                        <input type="checkbox" name="has_gamot_meds" id="edit_has_gamot_meds" value="1" onchange="toggleBox(this, 'edit-gamot-box')"> Gamot
                    </label>
                    <label class="select-card-box">
                        <input type="checkbox" name="has_labs" id="edit_has_labs" value="1" onchange="toggleBox(this, 'edit-labs-box')"> Labs
                    </label>
                </div>

                <!-- Edit Modal Standard MEDS Selector -->
                <div id="edit-meds-box" class="toggle-selection-container">
                    <div style="font-size: 0.75rem; font-weight: 600; color: var(--primary); margin-bottom: 0.35rem;">Select Standard Medicines:</div>
                    <?php foreach ($standard_meds_grouped as $category => $meds): ?>
                        <div class="group-title"><?= h($category) ?></div>
                        <div class="items-grid">
                            <?php foreach ($meds as $smed): ?>
                                <div class="select-card-box" onclick="toggleCard(this)">
                                    <input type="checkbox" class="edit-med-cb" name="medications[]" value="<?= h($smed) ?>">
                                    <span><?= h($smed) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Edit Modal GAMOT Selector -->
                <div id="edit-gamot-box" class="toggle-selection-container" style="border-color: #e9d5ff;">
                    <div style="font-size: 0.75rem; font-weight: 600; color: #7c3aed; margin-bottom: 0.35rem;">Select GAMOT Medicines (PhilHealth YAKAP List):</div>
                    <?php foreach ($available_medicines as $category => $meds): ?>
                        <div class="group-title gamot-title"><?= h($category) ?></div>
                        <div class="items-grid">
                            <?php foreach ($meds as $med): ?>
                                <div class="select-card-box" onclick="toggleCard(this)">
                                    <input type="checkbox" class="edit-gamot-cb" name="gamot_meds[]" value="<?= h($med) ?>">
                                    <span><?= h($med) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Edit Modal LABS Selector -->
                <div id="edit-labs-box" class="toggle-selection-container" style="border-color: #a7f3d0;">
                    <div style="font-size: 0.75rem; font-weight: 600; color: #047857; margin-bottom: 0.35rem;">Select Laboratory Tests:</div>
                    <?php foreach ($available_labs as $category => $labs): ?>
                        <div class="group-title lab-title"><?= h($category) ?></div>
                        <div class="items-grid">
                            <?php foreach ($labs as $lab): ?>
                                <div class="select-card-box" onclick="toggleCard(this)">
                                    <input type="checkbox" class="edit-lab-cb" name="labs[]" value="<?= h($lab) ?>">
                                    <span><?= h($lab) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Toggle full card selection box
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

function syncCardStates(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.querySelectorAll('.select-card-box').forEach(card => {
        const cb = card.querySelector('input[type="checkbox"]');
        if (cb && cb.checked) {
            card.classList.add('active');
        } else {
            card.classList.remove('active');
        }
    });
}

function toggleBox(checkbox, boxId) {
    const el = document.getElementById(boxId);
    if (el) {
        el.style.display = checkbox.checked ? 'block' : 'none';
    }
}

// Backdrop click handlers to automatically close overlays
function closeSummaryModal(event) {
    if (event.target.id === 'summaryModal') {
        document.getElementById('summaryModal').style.display = 'none';
    }
}

function closeEditModal(event) {
    if (event.target.id === 'editModal') {
        document.getElementById('editModal').style.display = 'none';
    }
}

// Open Summary Modal (View Details on Row click)
function openSummaryModal(record) {
    document.getElementById('summary_patient_name').innerText = record.patient_name;
    document.getElementById('summary_physician').innerText = record.physician_name || '—';
    document.getElementById('summary_meds_type').innerText = record.meds_type_name || '—';

    let savedMeds = [];
    let savedGamot = [];
    let savedLabs = [];

    try { savedMeds = JSON.parse(record.record_medications) || []; } catch(e) {}
    try { savedGamot = JSON.parse(record.record_gamot) || []; } catch(e) {}
    try { savedLabs = JSON.parse(record.record_labs) || []; } catch(e) {}

    // Render Standard Meds
    const medsContainer = document.getElementById('summary_meds_list');
    if (savedMeds.length > 0) {
        medsContainer.innerHTML = savedMeds.map(m => `<span class="med-chip">💊 ${escapeHtml(m)}</span>`).join('');
    } else {
        medsContainer.innerHTML = '<span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">No standard medicines recorded.</span>';
    }

    // Render Gamot
    const gamotContainer = document.getElementById('summary_gamot_list');
    if (savedGamot.length > 0) {
        gamotContainer.innerHTML = savedGamot.map(g => `<span class="gamot-chip">💜 ${escapeHtml(g)}</span>`).join('');
    } else {
        gamotContainer.innerHTML = '<span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">No GAMOT medicines recorded.</span>';
    }

    // Render Labs
    const labsContainer = document.getElementById('summary_labs_list');
    if (savedLabs.length > 0) {
        labsContainer.innerHTML = savedLabs.map(l => `<span class="lab-chip">🧪 ${escapeHtml(l)}</span>`).join('');
    } else {
        labsContainer.innerHTML = '<span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">No lab tests recorded.</span>';
    }

    document.getElementById('summaryModal').style.display = 'flex';
}

// Open Edit Modal
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

    let savedMeds = [];
    let savedGamot = [];
    let savedLabs = [];

    try { savedMeds = JSON.parse(record.record_medications) || []; } catch(e) {}
    try { savedGamot = JSON.parse(record.record_gamot) || []; } catch(e) {}
    try { savedLabs = JSON.parse(record.record_labs) || []; } catch(e) {}

    document.querySelectorAll('.edit-med-cb').forEach(cb => {
        cb.checked = savedMeds.includes(cb.value);
    });

    document.querySelectorAll('.edit-gamot-cb').forEach(cb => {
        cb.checked = savedGamot.includes(cb.value);
    });

    document.querySelectorAll('.edit-lab-cb').forEach(cb => {
        cb.checked = savedLabs.includes(cb.value);
    });

    syncCardStates('edit-meds-box');
    syncCardStates('edit-gamot-box');
    syncCardStates('edit-labs-box');

    document.getElementById('editModal').style.display = 'flex';
}

// Utility function to sanitize HTML output
function escapeHtml(text) {
    const div = document.createElement('div');
    div.innerText = text;
    return div.innerHTML;
}
</script>

<?php include 'includes/footer.php'; ?>