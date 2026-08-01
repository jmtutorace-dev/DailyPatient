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

