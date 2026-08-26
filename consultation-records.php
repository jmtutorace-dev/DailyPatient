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
 *   - filter by physician + date range + real-time name search,
 *   - click a patient's name to view their full history on patient-consultation.php,
 *   - delete a consultation record (server-side re-verifies it is a
 *     consultation-type row before deleting — FPE/intake rows are never touched).
 */

require_once 'config.php';
require_once 'includes/auth.php';

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

// --- Patient Information AJAX endpoint for the consultation popup ---
// IMPORTANT: this endpoint reads daily_records directly. It does not depend on
// the HTML/CSS structure of patient-consultation.php.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_detail') {
    header('Content-Type: application/json; charset=utf-8');

    $patient_name = trim((string)($_GET['patient_name'] ?? ''));

    if ($patient_name === '') {
        echo json_encode([
            'ok' => false,
            'message' => 'No patient specified.',
            'html' => '<div class="ph-error">No patient specified.</div>'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        SELECT
            dr.record_id,
            dr.record_date,
            dr.created_at,
            dr.record_medications,
            dr.record_labs,
            dr.record_gamot,
            p.physician_name,
            mt.meds_type_name
        FROM daily_records dr
        LEFT JOIN physicians p ON dr.physician_id = p.physician_id
        LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
        WHERE TRIM(dr.patient_name) = TRIM(?)
          AND mt.is_consultation = 1
        ORDER BY dr.record_date DESC, dr.created_at DESC, dr.record_id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode([
            'ok' => false,
            'message' => 'Unable to prepare patient history query.',
            'html' => '<div class="ph-error">Unable to load patient information.</div>'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt->bind_param('s', $patient_name);
    $stmt->execute();
    $result = $stmt->get_result();

    $decode_items = static function ($value) {
        if ($value === null || $value === '') return [];

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        if (!is_array($value)) $value = [$value];

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                foreach ($item as $nested) {
                    $nested = trim((string)$nested);
                    if ($nested !== '' && $nested !== '0') $items[] = $nested;
                }
            } else {
                $item = trim((string)$item);
                if ($item !== '' && $item !== '0') $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    };

    $esc = static function ($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $render_items = static function (array $items, $class = '') use ($esc) {
        if (!$items) {
            return '<p class="ph-none">None recorded.</p>';
        }

        $html = '<div class="ph-items">';
        foreach ($items as $item) {
            $html .= '<span class="ph-item ' . $esc($class) . '">' . $esc($item) . '</span>';
        }
        $html .= '</div>';
        return $html;
    };

    $visits = [];
    while ($row = $result->fetch_assoc()) {
        $visits[] = $row;
    }
    $stmt->close();

    if (!$visits) {
        echo json_encode([
            'ok' => true,
            'count' => 0,
            'html' => '<div class="ph-error">No consultation history found for this patient.</div>'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $html = '';

    foreach ($visits as $visit) {
        $record_date = !empty($visit['record_date'])
            ? date('F j, Y', strtotime($visit['record_date']))
            : '—';
        $physician = trim((string)($visit['physician_name'] ?? '')) ?: '—';
        $meds_type = trim((string)($visit['meds_type_name'] ?? '')) ?: 'Consultation';

        $meds  = $decode_items($visit['record_medications'] ?? null);
        $gamot = $decode_items($visit['record_gamot'] ?? null);
        $labs  = $decode_items($visit['record_labs'] ?? null);

        $html .= '<section class="ph-visit">';
        $html .= '<div class="ph-info-box">';
        $html .= '<div class="ph-info-row"><strong>Physician:</strong> ' . $esc($physician) . '</div>';
        $html .= '<div class="ph-info-row"><strong>Meds Type:</strong> ' . $esc($meds_type) . '</div>';
        $html .= '<div class="ph-info-row"><strong>Record Date:</strong> ' . $esc($record_date) . '</div>';
        $html .= '</div>';
        $html .= '<div class="ph-divider"></div>';

        $html .= '<div class="ph-section">';
        $html .= '<h4 class="ph-section-title yakap">💊 Yakap Medicine</h4>';
        $html .= $render_items($meds, 'yakap');
        $html .= '</div>';

        $html .= '<div class="ph-section">';
        $html .= '<h4 class="ph-section-title gamot">💜 Gamot Medicine</h4>';
        $html .= $render_items($gamot, 'gamot');
        $html .= '</div>';

        $html .= '<div class="ph-section">';
        $html .= '<h4 class="ph-section-title labs">🧪 Laboratory Tests</h4>';
        $html .= $render_items($labs, 'labs');
        $html .= '</div>';

        $html .= '</section>';
    }


    echo json_encode([
        'ok' => true,
        'count' => count($visits),
        'html' => $html
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Handle AJAX Request for Real-Time Search / Filtering ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter_records') {
    $filter_physician = isset($_GET['filter_physician']) ? (int)$_GET['filter_physician'] : 0;
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to   = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $name_search = isset($_GET['name_search']) ? trim($_GET['name_search']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 15;
    $offset = ($page - 1) * $per_page;

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

    // Count DISTINCT patients for pagination
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

    // Fetch grouped ledger rows
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

    foreach ($consult_rows as &$consult_row) {
        $patient_key = mb_strtolower(normalize_patient_name($consult_row['patient_name']));
        $consult_row['second_tranche'] = ($patient_key !== '' && isset($second_tranche_status[$patient_key])) ? 1 : 0;
    }
    unset($consult_row);

    $has_filters = ($filter_physician > 0 || $date_from !== '' || $date_to !== '' || $name_search !== '');

    ob_start();
    if (empty($consult_rows)):
    ?>
        <div class="healthcare-empty">
            <?= $has_filters ? 'No consultations match your filters.' : 'No consultations logged yet.' ?>
            <?php if (!is_viewer()): ?>
                <div style="margin-top: 1rem;">
                    <a href="patient-consultation.php" class="btn-hc btn-hc-outline">+ Log New Consultation</a>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="healthcare-table-wrapper">
            <table class="healthcare-table">
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
                            <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.8125rem;"><?= h(date('M j, Y', strtotime($row['last_visit_date']))) ?></td>
                            <td>
                                <span class="cr-patient-name <?= !empty($row['second_tranche']) ? 'cr-second-tranche-name' : '' ?>" title="View full history" style="font-weight: 500;"><?= h($row['patient_name']) ?></span>
                            </td>
                            <td><span class="hc-badge hc-badge-success"><?= (int)$row['visit_count'] ?> visit(s)</span></td>
                            <td><?= h($row['physician_name'] ?: '— No physician —') ?></td>
                            <td style="color: var(--text-muted);"><?= h($row['types_list'] ?: '—') ?></td>
                            <td>
                                <span class="hc-flag <?= $row['has_meds'] ? 'hc-flag-yes' : 'hc-flag-no' ?>"><?= $row['has_meds'] ? '✓' : '—' ?> Meds</span>
                                <span class="hc-flag <?= $row['has_labs'] ? 'hc-flag-yes' : 'hc-flag-no' ?>"><?= $row['has_labs'] ? '✓' : '—' ?> Labs</span>
                                <span class="hc-flag <?= $row['has_gamot_meds'] ? 'hc-flag-yes' : 'hc-flag-no' ?>"><?= $row['has_gamot_meds'] ? '✓' : '—' ?> Gamot</span>
                            </td>
                            <td onclick="event.stopPropagation()">
                                <div style="display:flex; gap:0.35rem; align-items:center; justify-content:flex-start; flex-wrap:wrap;">
                                    <?php if (is_viewer()): ?>
                                        <span class="hc-badge" style="font-size:0.7rem;">Read Only</span>
                                    <?php else: ?>
                                        <form method="post" action="consultation-records.php" style="display:inline;" onsubmit="handleTrancheSubmit(event, this)">
                                            <input type="hidden" name="action" value="toggle_second_tranche">
                                            <input type="hidden" name="patient_name" value="<?= h($row['patient_name']) ?>">
                                            <input type="hidden" name="second_tranche" value="<?= !empty($row['second_tranche']) ? '0' : '1' ?>">
                                            <input type="hidden" name="filter_physician" value="<?= (int)$filter_physician ?>">
                                            <input type="hidden" name="name_search" value="<?= h($name_search) ?>">
                                            <input type="hidden" name="date_from" value="<?= h($date_from) ?>">
                                            <input type="hidden" name="date_to" value="<?= h($date_to) ?>">
                                            <input type="hidden" name="page" value="<?= (int)$page ?>">
                                            <button type="submit"
                                                    class="btn-hc btn-hc-sm cr-second-tranche-btn <?= !empty($row['second_tranche']) ? 'is-marked' : 'btn-hc-outline' ?>"
                                                    style="height: 32px; padding: 0 0.6rem; font-size: 0.75rem;"
                                                    title="<?= !empty($row['second_tranche']) ? 'Marked as 2nd Tranche — click to undo' : 'Mark this patient as 2nd Tranche' ?>">
                                                <?= !empty($row['second_tranche']) ? '✓ 2nd' : '2nd' ?>
                                            </button>
                                        </form>
                                        <a href="patient-consultation.php?q=<?= urlencode($row['patient_name']) ?>" class="btn-hc btn-hc-accent" style="height: 32px; padding: 0 0.6rem; font-size: 0.75rem;">Consultation</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="healthcare-pagination">
            <div>
                <?php if ($page > 1): ?>
                    <button type="button" class="btn-hc btn-hc-outline pagination-btn" data-page="<?= $page-1 ?>" style="height: 32px; padding: 0 0.75rem;">← Prev</button>
                <?php endif; ?>
            </div>
            <span>Page <?= $page ?> of <?= $total_pages ?></span>
            <div>
                <?php if ($page < $total_pages): ?>
                    <button type="button" class="btn-hc btn-hc-outline pagination-btn" data-page="<?= $page+1 ?>" style="height: 32px; padding: 0 0.75rem;">Next →</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php
    endif;
    $html_output = ob_get_clean();
    echo json_encode(['html' => $html_output, 'total_pages' => $total_pages, 'current_page' => $page]);
    exit;
}

// --- Read navigation parameters for initial standard page load ---
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

// --- Handle POST: delete a consultation or toggle second tranche ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_second_tranche') {
        if (is_viewer()) {
            redirect_with_msg('consultation-records.php', 'View-only users cannot change 2nd Tranche status.', 'error');
        }

        $patient_name = trim($_POST['patient_name'] ?? '');
        $second_tranche = isset($_POST['second_tranche']) ? (int)$_POST['second_tranche'] : 0;

        if ($patient_name !== '') {
            $patient_key = mb_strtolower(normalize_patient_name($patient_name));

            if ($second_tranche === 1) {
                $second_tranche_status[$patient_key] = [
                    'encoded' => true,
                    'encoded_at' => date('Y-m-d H:i:s')
                ];
                save_second_tranche_status($tranche_status_file, $second_tranche_status);
            } else {
                unset($second_tranche_status[$patient_key]);
                save_second_tranche_status($tranche_status_file, $second_tranche_status);
            }
        }

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

// --- Fetch initial consultation records ---
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

foreach ($consult_rows as &$consult_row) {
    $patient_key = mb_strtolower(normalize_patient_name($consult_row['patient_name']));
    $consult_row['second_tranche'] = ($patient_key !== '' && isset($second_tranche_status[$patient_key])) ? 1 : 0;
}
unset($consult_row);

$has_filters = ($filter_physician > 0 || $date_from !== '' || $date_to !== '' || $name_search !== '');

include 'includes/header.php';
?>

<style>
/* Healthcare Professional Design System Overrides & Enhancements */
:root {
    --primary: #0284c7;
    --primary-hover: #0369a1;
    --primary-light: #e0f2fe;
    --bg-main: #f8fafc;
    --surface: #ffffff;
    --border-color: #e2e8f0;
    --text-main: #1e293b;
    --text-muted: #64748b;
    --success-bg: #f0fdf4;
    --success-border: #bbf7d0;
    --success-text: #166534;
    --radius-sm: 6px;
    --radius-md: 8px;
    --shadow-subtle: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.03);
}

body {
    background-color: var(--bg-main);
    color: var(--text-main);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

/* Page Header */
.healthcare-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
    gap: 1rem;
    flex-wrap: wrap;
}

.healthcare-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0 0 0.25rem 0;
}

.healthcare-header p {
    color: var(--text-muted);
    font-size: 0.875rem;
    margin: 0;
}

/* Main Dashboard Card */
.healthcare-card {
    background: var(--surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-subtle);
    margin-bottom: 2rem;
    overflow: hidden;
}

.healthcare-card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: #ffffff;
}

.healthcare-card-header h3 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
    color: var(--text-main);
}

.healthcare-card-header svg {
    color: var(--primary);
}

/* Filters & Toolbar */
.healthcare-toolbar {
    padding: 1rem 1.5rem;
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
}

.healthcare-filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.healthcare-filter-form select,
.healthcare-filter-form input[type="text"],
.healthcare-filter-form input[type="date"] {
    height: 38px;
    padding: 0 0.75rem;
    font-size: 0.875rem;
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text-main);
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.healthcare-filter-form select:focus,
.healthcare-filter-form input[type="text"]:focus,
.healthcare-filter-form input[type="date"]:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}

.healthcare-filter-form .search-input-wrapper {
    position: relative;
    flex: 1;
    min-width: 220px;
    display: flex;
    align-items: center;
}

.healthcare-filter-form .search-input-wrapper input[type="text"] {
    width: 100%;
    padding-right: 2rem;
}

.clear-search-btn {
    position: absolute;
    right: 8px;
    background: transparent;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 1rem;
    padding: 0 4px;
    line-height: 1;
    display: none;
}
.clear-search-btn:hover {
    color: var(--text-main);
}

/* Buttons */
.btn-hc {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 38px;
    padding: 0 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease;
    text-decoration: none;
    white-space: nowrap;
}

.btn-hc-primary {
    background: var(--primary);
    color: #ffffff;
}
.btn-hc-primary:hover {
    background: var(--primary-hover);
}

.btn-hc-outline {
    background: var(--surface);
    border-color: var(--border-color);
    color: var(--text-main);
}
.btn-hc-outline:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}

.btn-hc-accent {
    background: var(--primary-light);
    color: var(--primary-hover);
    border-color: transparent;
}
.btn-hc-accent:hover {
    background: #bae6fd;
}

/* Tables */
.healthcare-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.healthcare-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 0.875rem;
}

.healthcare-table th {
    background: #f8fafc;
    color: var(--text-muted);
    font-weight: 600;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}

.healthcare-table td {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-main);
    vertical-align: middle;
}

.healthcare-table tbody tr {
    transition: background-color 0.1s ease;
}

.healthcare-table tbody tr:hover {
    background-color: #f8fafc;
}

/* Status Badges & Flags */
.hc-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 9999px;
    background: var(--primary-light);
    color: var(--primary-hover);
}

.hc-badge-success {
    background: var(--success-bg);
    color: var(--success-text);
    border: 1px solid var(--success-border);
}

.hc-flag {
    display: inline-block;
    padding: 0.125rem 0.375rem;
    font-size: 0.75rem;
    border-radius: 4px;
    margin-right: 0.25rem;
}
.hc-flag-yes {
    background: #f0fdf4;
    color: #166534;
    font-weight: 600;
}
.hc-flag-no {
    background: #f1f5f9;
    color: #94a3b8;
}

/* 2nd Tranche Custom Row */
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
.cr-second-tranche-btn.is-marked {
    background: #16a34a !important;
    border-color: #15803d !important;
    color: #fff !important;
    font-weight: 700;
}

/* Empty State */
.healthcare-empty {
    padding: 3rem 1.5rem;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.9375rem;
}

/* Pagination */
.healthcare-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
    background: #f8fafc;
    font-size: 0.875rem;
    color: var(--text-muted);
}

/* Patient information overlay - clean reference-style design */
.ph-modal {
    position: fixed;
    inset: 0;
    z-index: 1050;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
}
.ph-modal[hidden] { display: none !important; }
.ph-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.56);
    backdrop-filter: blur(3px);
}
.ph-panel {
    position: relative;
    z-index: 1051;
    width: min(650px, calc(100vw - 2rem));
    max-height: min(88vh, 720px);
    background: #fff;
    border-radius: 15px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .24);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.ph-header {
    padding: 1.55rem 1.75rem .85rem;
    border: 0;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.ph-title {
    margin: 0;
    color: #2563eb;
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 1.02rem;
    font-weight: 700;
    line-height: 1.35;
}
.ph-close {
    flex: 0 0 auto;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid #d6a73a;
    border-radius: 5px;
    background: #fff;
    color: #64748b;
    font-size: 1.15rem;
    line-height: 28px;
    cursor: pointer;
}
.ph-close:hover { background: #fffaf0; color: #334155; }
.ph-body {
    padding: 0 1.75rem 1.45rem;
    overflow-y: auto;
    flex: 1;
    color: #334155;
}
.ph-loading {
    padding: 3rem 1rem;
    text-align: center;
    color: #64748b;
}

/* The returned patient-consultation timeline is converted by JS into these simple blocks. */
.ph-visit {
    padding-top: .15rem;
}
.ph-info-box {
    background: #f6f8fb;
    border-radius: 8px;
    padding: .85rem .9rem;
    color: #64748b;
    font-size: .82rem;
    line-height: 1.8;
}
.ph-info-row strong { color: #64748b; font-weight: 700; }
.ph-divider {
    height: 1px;
    background: #dbe3ec;
    margin: .95rem 0 1.05rem;
}
.ph-section {
    padding: .05rem 0 .8rem;
}
.ph-section-title {
    margin: 0 0 .6rem;
    font-size: .82rem;
    line-height: 1.3;
    font-weight: 700;
}
.ph-section-title.yakap { color: #1d4ed8; }
.ph-section-title.gamot { color: #7e22ce; }
.ph-section-title.labs { color: #047857; }
.ph-none {
    margin: 0;
    color: #64748b;
    font-size: .78rem;
    font-style: italic;
}
.ph-items {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
}
.ph-item {
    display: inline-flex;
    align-items: center;
    min-height: 29px;
    padding: .3rem .65rem;
    border-radius: 5px;
    font-size: .75rem;
    font-weight: 600;
    background: #f8fafc;
    border: 1px solid #dbe4ee;
    color: #475569;
}
/* Match each item's chip color to its section label. */
.ph-item.yakap {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}
.ph-item.gamot {
    background: #faf5ff;
    border-color: #d8b4fe;
    color: #7e22ce;
}
.ph-item.labs {
    background: #ecfdf5;
    border-color: #86efac;
    color: #047857;
}
.ph-visit + .ph-visit {
    border-top: 1px solid #dbe3ec;
    margin-top: .6rem;
    padding-top: 1.15rem;
}
.ph-visit-date {
    margin: 0 0 .65rem;
    color: #475569;
    font-size: .82rem;
    font-weight: 600;
}
.ph-visit-type {
    display: inline-block;
    margin-left: .35rem;
    padding: .18rem .5rem;
    border-radius: 999px;
    background: #e8f3ee;
    color: #27724f;
    font-size: .65rem;
    font-weight: 700;
    text-transform: uppercase;
}
.ph-footer {
    display: flex;
    justify-content: flex-end;
    padding: 0 1.75rem 1.25rem;
}
.ph-footer button {
    height: 31px;
    padding: 0 .75rem;
    border: 1px solid #d9cdbb;
    border-radius: 7px;
    background: #fff;
    color: #475569;
    font-size: .72rem;
    cursor: pointer;
}
.ph-footer button:hover { background: #f8fafc; }
@media (max-width: 640px) {
    .ph-modal { padding: .75rem; }
    .ph-panel { width: 100%; max-height: 92vh; border-radius: 12px; }
    .ph-header { padding: 1.2rem 1.2rem .75rem; }
    .ph-body { padding: 0 1.2rem 1rem; }
    .ph-footer { padding: 0 1.2rem 1rem; }
}
</style>

<div class="healthcare-header">
    <div>
        <h2>🗂️ Consultation Records</h2>
        <p>Browse and review existing clinical consultations and patient visit history.</p>
    </div>
    <div>
        <?php if (!is_viewer()): ?>
            <a href="patient-consultation.php" class="btn-hc btn-hc-primary">+ Log New Consultation</a>
        <?php endif; ?>
    </div>
</div>

<div class="healthcare-card">
    <div class="healthcare-card-header">
        <svg viewBox="0 0 20 20" width="20" height="20" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none" aria-hidden="true"><path d="M15.5 12.5a4 4 0 0 1-4 4"/><path d="M6.5 12.5a4 4 0 0 0 4 4"/><path d="M10 2v3"/><path d="M10 5h1.5a2 2 0 0 1 0 4H10"/><path d="M10 9h-1.5a2 2 0 0 0 0 4H10"/></svg>
        <h3>Recent Consultations</h3>
    </div>

    <div class="healthcare-toolbar">
        <form method="get" action="consultation-records.php" id="cr-filter-form" class="healthcare-filter-form" onsubmit="return false;">
            <select name="filter_physician" id="cr-filter-physician">
                <option value="0">All Physicians</option>
                <?php foreach ($physicians_list as $doc): ?>
                    <option value="<?= (int)$doc['physician_id'] ?>" <?= $filter_physician === (int)$doc['physician_id'] ? 'selected' : '' ?>><?= h($doc['physician_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="search-input-wrapper">
                <input type="text" name="name_search" id="cr-name-search" value="<?= h($name_search) ?>" placeholder="🔍 Type patient name..." autocomplete="off">
                <button type="button" id="cr-clear-search" class="clear-search-btn" title="Clear search">&times;</button>
            </div>
            <input type="date" name="date_from" id="cr-date-from" value="<?= h($date_from) ?>" title="From date">
            <input type="date" name="date_to" id="cr-date-to" value="<?= h($date_to) ?>" title="To date">
            <a href="consultation-records.php" id="cr-clear-all" class="btn-hc btn-hc-outline" style="<?= $has_filters ? '' : 'display:none;' ?>">Clear</a>
        </form>
    </div>

    <div id="cr-results-container">
        <?php if (empty($consult_rows)): ?>
            <div class="healthcare-empty">
                <?= $has_filters ? 'No consultations match your filters.' : 'No consultations logged yet.' ?>
                <?php if (!is_viewer()): ?>
                    <div style="margin-top: 1rem;">
                        <a href="patient-consultation.php" class="btn-hc btn-hc-outline">+ Log New Consultation</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="healthcare-table-wrapper">
                <table class="healthcare-table">
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
                                <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.8125rem;"><?= h(date('M j, Y', strtotime($row['last_visit_date']))) ?></td>
                                <td>
                                    <span class="cr-patient-name <?= !empty($row['second_tranche']) ? 'cr-second-tranche-name' : '' ?>" title="View full history" style="font-weight: 500;"><?= h($row['patient_name']) ?></span>
                                </td>
                                <td><span class="hc-badge hc-badge-success"><?= (int)$row['visit_count'] ?> visit(s)</span></td>
                                <td data-cell="Physician"><?= h($row['physician_name'] ?: '— No physician —') ?></td>
                                <td style="color: var(--text-muted);"><?= h($row['types_list'] ?: '—') ?></td>
                                <td>
                                    <span class="hc-flag <?= $row['has_meds'] ? 'hc-flag-yes' : 'hc-flag-no' ?>"><?= $row['has_meds'] ? '✓' : '—' ?> Meds</span>
                                    <span class="hc-flag <?= $row['has_labs'] ? 'hc-flag-yes' : 'hc-flag-no' ?>"><?= $row['has_labs'] ? '✓' : '—' ?> Labs</span>
                                    <span class="hc-flag <?= $row['has_gamot_meds'] ? 'hc-flag-yes' : 'hc-flag-no' ?>"><?= $row['has_gamot_meds'] ? '✓' : '—' ?> Gamot</span>
                                </td>
                                <td onclick="event.stopPropagation()">
                                    <div style="display:flex; gap:0.35rem; align-items:center; justify-content:flex-start; flex-wrap:wrap;">
                                        <?php if (is_viewer()): ?>
                                            <span class="hc-badge" style="font-size:0.7rem;">Read Only</span>
                                        <?php else: ?>
                                            <form method="post" action="consultation-records.php" style="display:inline;" onsubmit="handleTrancheSubmit(event, this)">
                                                <input type="hidden" name="action" value="toggle_second_tranche">
                                                <input type="hidden" name="patient_name" value="<?= h($row['patient_name']) ?>">
                                                <input type="hidden" name="second_tranche" value="<?= !empty($row['second_tranche']) ? '0' : '1' ?>">
                                                <input type="hidden" name="filter_physician" value="<?= (int)$filter_physician ?>">
                                                <input type="hidden" name="name_search" value="<?= h($name_search) ?>">
                                                <input type="hidden" name="date_from" value="<?= h($date_from) ?>">
                                                <input type="hidden" name="date_to" value="<?= h($date_to) ?>">
                                                <input type="hidden" name="page" value="<?= (int)$page ?>">
                                                <button type="submit"
                                                        class="btn-hc btn-hc-sm cr-second-tranche-btn <?= !empty($row['second_tranche']) ? 'is-marked' : 'btn-hc-outline' ?>"
                                                        style="height: 32px; padding: 0 0.6rem; font-size: 0.75rem;"
                                                        title="<?= !empty($row['second_tranche']) ? 'Marked as 2nd Tranche — click to undo' : 'Mark this patient as 2nd Tranche' ?>">
                                                    <?= !empty($row['second_tranche']) ? '✓ 2nd' : '2nd' ?>
                                                </button>
                                            </form>
                                            <a href="patient-consultation.php?q=<?= urlencode($row['patient_name']) ?>" class="btn-hc btn-hc-accent" style="height: 32px; padding: 0 0.6rem; font-size: 0.75rem;">Consultation</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="healthcare-pagination">
                <div>
                    <?php if ($page > 1): ?>
                        <button type="button" class="btn-hc btn-hc-outline pagination-btn" data-page="<?= $page-1 ?>" style="height: 32px; padding: 0 0.75rem;">← Prev</button>
                    <?php endif; ?>
                </div>
                <span>Page <?= $page ?> of <?= $total_pages ?></span>
                <div>
                    <?php if ($page < $total_pages): ?>
                        <button type="button" class="btn-hc btn-hc-outline pagination-btn" data-page="<?= $page+1 ?>" style="height: 32px; padding: 0 0.75rem;">Next →</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Patient Detail Modal (view full visit history) -->
<div id="patient-detail-modal" class="ph-modal" hidden role="dialog" aria-modal="true" aria-labelledby="patient-detail-title">
    <div class="ph-backdrop" data-ph-close></div>
    <div class="ph-panel">
        <div class="ph-header">
            <h3 class="ph-title" id="patient-detail-title">Patient History</h3>
            <button type="button" class="ph-close" id="patient-detail-close" aria-label="Close patient history">&times;</button>
        </div>
        <div class="ph-body" id="patient-detail-body">
            <div class="ph-loading">Loading patient information…</div>
        </div>
        <div class="ph-footer">
            <button type="button" id="patient-detail-footer-close">Close</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // Real-Time Search and Filter Implementation via AJAX
    var searchInput = document.getElementById('cr-name-search');
    var clearSearchBtn = document.getElementById('cr-clear-search');
    var physicianSelect = document.getElementById('cr-filter-physician');
    var dateFromInput = document.getElementById('cr-date-from');
    var dateToInput = document.getElementById('cr-date-to');
    var resultsContainer = document.getElementById('cr-results-container');
    var clearAllBtn = document.getElementById('cr-clear-all');
    var debounceTimer = null;
    var currentRequestSeq = 0;

    function updateClearButtonVisibility() {
        if (!searchInput || !clearSearchBtn) return;
        if (searchInput.value.trim().length > 0) {
            clearSearchBtn.style.display = 'block';
        } else {
            clearSearchBtn.style.display = 'none';
        }
    }

    function fetchFilteredResults(page, immediate) {
        var query = searchInput ? searchInput.value.trim() : '';
        var physicianId = physicianSelect ? physicianSelect.value : '0';
        var dateFrom = dateFromInput ? dateFromInput.value : '';
        var dateTo = dateToInput ? dateToInput.value : '';
        page = page || 1;

        updateClearButtonVisibility();

        // Toggle clear all button
        if (clearAllBtn) {
            if (query !== '' || physicianId !== '0' || dateFrom !== '' || dateTo !== '') {
                clearAllBtn.style.display = 'inline-flex';
            } else {
                clearAllBtn.style.display = 'none';
            }
        }

        var seq = ++currentRequestSeq;

        var url = 'consultation-records.php?ajax=filter_records&filter_physician=' + encodeURIComponent(physicianId) +
                  '&name_search=' + encodeURIComponent(query) +
                  '&date_from=' + encodeURIComponent(dateFrom) +
                  '&date_to=' + encodeURIComponent(dateTo) +
                  '&page=' + encodeURIComponent(page);

        if (!immediate) {
            resultsContainer.style.opacity = '0.6';
        }

        fetch(url)
            .then(function(res) {
                if (!res.ok) throw new Error('Network response failed');
                return res.json();
            })
            .then(function(data) {
                if (seq !== currentRequestSeq) return;
                resultsContainer.style.opacity = '1';
                resultsContainer.innerHTML = data.html;
                bindPaginationEvents();
            })
            .catch(function(err) {
                console.error('Search error:', err);
                if (seq === currentRequestSeq) {
                    resultsContainer.style.opacity = '1';
                }
            });
    }

    if (searchInput) {
        updateClearButtonVisibility();
        searchInput.addEventListener('input', function() {
            updateClearButtonVisibility();
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                fetchFilteredResults(1, false);
            }, 250);
        });
    }

    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            if (searchInput) {
                searchInput.value = '';
                updateClearButtonVisibility();
                fetchFilteredResults(1, false);
                searchInput.focus();
            }
        });
    }

    if (physicianSelect) {
        physicianSelect.addEventListener('change', function() {
            fetchFilteredResults(1, false);
        });
    }

    if (dateFromInput) {
        dateFromInput.addEventListener('change', function() {
            fetchFilteredResults(1, false);
        });
    }

    if (dateToInput) {
        dateToInput.addEventListener('change', function() {
            fetchFilteredResults(1, false);
        });
    }

    function bindPaginationEvents() {
        var paginationBtns = resultsContainer.querySelectorAll('.pagination-btn');
        paginationBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var p = this.getAttribute('data-page');
                fetchFilteredResults(p, false);
            });
        });
    }

    bindPaginationEvents();

    // Global Tranche form handler for dynamic elements
    window.handleTrancheSubmit = function(e, form) {
        // Can be submitted normally or enhanced via AJAX if desired; standard post works perfectly with preservation.
    };

    // Patient Information Modal Logic
    var modal = document.getElementById('patient-detail-modal');
    var body = document.getElementById('patient-detail-body');
    var title = document.getElementById('patient-detail-title');
    var closeBtn = document.getElementById('patient-detail-close');
    var footerCloseBtn = document.getElementById('patient-detail-footer-close');
    var detailRequestSeq = 0;

    function openPatientDetail(name) {
        if (!modal || !body || !title) return;

        title.textContent = name;
        body.innerHTML = '<div class="ph-loading">Loading patient information…</div>';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        if (closeBtn) closeBtn.focus();

        var seq = ++detailRequestSeq;

        // Use this page's own JSON endpoint. It reads daily_records directly,
        // so the popup is independent of patient-consultation.php markup.
        var url = 'consultation-records.php?ajax=patient_detail&patient_name=' + encodeURIComponent(name);

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Request failed (' + response.status + ')');
            return response.json();
        })
        .then(function(data) {
            if (seq !== detailRequestSeq) return;

            if (!data || data.ok !== true) {
                throw new Error((data && data.message) || 'Unable to load patient information.');
            }

            // Do not infer an empty history from missing HTML. The PHP endpoint
            // explicitly determines whether consultation rows exist.
            body.innerHTML = data.html || '<div class="ph-error">No consultation history found for this patient.</div>';
        })
        .catch(function(err) {
            console.error('Patient detail error:', err);
            if (seq !== detailRequestSeq) return;
            body.innerHTML = '<div class="ph-error">Unable to load this patient information.<br><small>Please refresh and try again.</small></div>';
        });
    }

    window.openPatientDetail = openPatientDetail;

    function closePatientDetail() {
        if (modal) modal.hidden = true;
        document.body.style.overflow = '';
    }

    window.openPatientDetail = openPatientDetail;

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal || e.target.classList.contains('ph-backdrop')) {
                closePatientDetail();
            }
        });
    }

    if (closeBtn) closeBtn.addEventListener('click', closePatientDetail);
    if (footerCloseBtn) footerCloseBtn.addEventListener('click', closePatientDetail);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && !modal.hidden) closePatientDetail();
    });
})();
</script>

<?php include 'includes/footer.php'; ?>