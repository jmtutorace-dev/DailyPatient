<?php
/**
 * YAKAP GAMOT SYSTEM - Configuration
 */

// Start session for flash messages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'yakap_gamot';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// Set charset to utf8mb4
$conn->set_charset('utf8mb4');

/**
 * Escape for safe HTML output
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize a patient name before it is stored:
 * trims leading/trailing whitespace and collapses any run of internal
 * whitespace to a single space. This prevents new whitespace-inconsistency
 * duplicates (e.g. "Jaypee C. Madelo" vs "Jaypee C.  Madelo") from being
 * created from now on. Exact normalization/case is intentionally left alone —
 * we do NOT enforce uniqueness (two real people can share a name); this only
 * reduces accidental fragmentation from typos/spacing.
 */
function normalize_patient_name($name) {
    if ($name === null) return '';
    return trim(preg_replace('/\s+/', ' ', (string)$name));
}

/**
 * Is this patient already registered anywhere in the system?
 *
 * CORE BUSINESS RULE: a patient is registered (FPE) exactly ONCE, ever. After
 * that, every future visit must be logged as a Consultation — never another FPE.
 *
 * This is the SINGLE shared source of truth for that rule. It returns:
 *   - FALSE            if the patient is brand new (no existing rows anywhere)
 *   - ['record_id'=>..,'record_date'=>..]  if they have at least one
 *     daily_records row (the earliest one, so callers can show the first date)
 *   - [] (empty array)  if they exist only in the patients master list
 *
 * Every place that needs to know "is this patient new or existing" calls THIS
 * exact function — no separate copies of similar logic anywhere else.
 */
function patient_is_registered($conn, $name, $exclude_record_id = null) {
    $norm = normalize_patient_name($name);
    if ($norm === '') return false;

    $sql = "SELECT record_id, record_date FROM daily_records WHERE TRIM(patient_name) = ?";
    $types = 's';
    $params = [$norm];

    // On the update path we must exclude the row being edited, so a patient
    // keeping their own existing FPE row isn't treated as a duplicate.
    if ($exclude_record_id !== null) {
        $sql .= " AND record_id != ?";
        $types .= 'i';
        $params[] = (int)$exclude_record_id;
    }
    $sql .= " ORDER BY record_date ASC LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row;

    $stmt2 = $conn->prepare("SELECT patient_id FROM patients WHERE TRIM(patient_name) = ? LIMIT 1");
    $stmt2->bind_param("s", $norm);
    $stmt2->execute();
    $exists = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    return $exists ? [] : false;
}

/**
 * Read ?date= from query string, default to today
 * Returns a date string in 'Y-m-d' format
 */
function selected_date() {
    if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
        return $_GET['date'];
    }
    return date('Y-m-d');
}

/**
 * Set a flash message (stored in session)
 */
function set_flash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

/**
 * Get and clear flash message
 */
function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Redirect with a flash message (session-based, no URL params)
 */
function redirect_with_msg($url, $msg, $type = 'success') {
    set_flash($msg, $type);
    header("Location: {$url}");
    exit;
}

/**
 * Output CSV headers and stream data
 * Usage: export_csv('filename.csv', $headers_array, $data_result_set)
 */
function export_csv($filename, $headers, $data, $conn = null) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for UTF-8 Excel

    fputcsv($output, $headers);

    if (is_array($data)) {
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    } elseif ($data instanceof mysqli_result) {
        while ($row = $data->fetch_assoc()) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit;
}

/**
 * Safely sanitize numeric input for money fields and similar values.
 */
function sanitize_decimal($value, $min = 0.0, $max = null) {
    $num = (float) $value;

    if (!is_finite($num)) {
        $num = 0.0;
    }

    if ($num < $min) {
        $num = (float) $min;
    }

    if ($max !== null && $num > $max) {
        $num = (float) $max;
    }

    return round($num, 2);
}

/**
 * Check if a value already exists in a table for a given column.
 */
function record_exists($conn, $table, $column, $value, $ignore_column = null, $ignore_value = null) {
    $sql = "SELECT 1 FROM `{$table}` WHERE LOWER(`{$column}`) = LOWER(?)";
    $types = 's';
    $params = [$value];

    if ($ignore_column !== null && $ignore_value !== null) {
        $sql .= " AND `{$ignore_column}` != ?";
        $types .= 'i';
        $params[] = (int) $ignore_value;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

