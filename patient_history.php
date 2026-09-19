<?php
/**
 * YAKAP GAMOT SYSTEM - Patient History (patient_history.php)
 *
 * Full standalone page (search + KPIs + visit history table + CSV export + Directory list).
 * Also usable as an embeddable fragment (?embed=1) for the Patient History
 * overlay on index.php — in that mode it renders only the KPI/visit-history
 * content for the requested patient, with no site chrome or directory list.
 * The sidebar "Patient History" navigation points to this file.
 *
 * ---------------------------------------------------------------------
 * FILE STRUCTURE (see accompanying audit notes for full rationale):
 *   1. Configuration / authentication
 *   2. AJAX autocomplete endpoint
 *   3. Helper functions
 *   4. CSV export handler
 *   5. Input validation / search parameter resolution
 *   6. Patient directory query
 *   7. Patient history query + aggregation
 *   8. Standalone styles
 *   9. Embed styles
 *  10. Page markup
 *  11. Standalone JavaScript
 *  12. Footer
 * ---------------------------------------------------------------------
 */

require_once 'config.php';
require_once 'includes/auth.php';

// =============================================================
// 3. HELPER FUNCTIONS
// (defined early because the AJAX + CSV handlers below use them)
// =============================================================

/**
 * Escape LIKE wildcard characters (\ % _) in user-supplied text so it is
 * matched literally. MySQL's default LIKE escape character is the
 * backslash, so no explicit ESCAPE clause is required as long as this
 * function is used consistently everywhere a search term is embedded in
 * a LIKE pattern (autocomplete, history lookup, CSV export).
 */
function escape_like_value($value) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value);
}

/**
 * Trim and collapse internal whitespace runs in a name/search term to
 * single spaces, so "John   Michael  Doe" behaves the same as
 * "John Michael Doe".
 */
function normalize_name_input($value) {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value);
    return $value === null ? '' : $value;
}

/**
 * Safely format a date/datetime value for display. Returns an em-dash
 * instead of silently rendering the Unix epoch when the value is
 * missing, zeroed-out, or fails to parse.
 */
function format_display_date($value, $format = 'M j, Y') {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '—';
    }
    return date($format, $ts);
}

/**
 * Normalize a record_date value (which may be a DATE or a full
 * DATETIME) down to its calendar date (YYYY-MM-DD). Used specifically
 * for "Unique Visit Dates" so that two records on the same calendar day
 * at different times are not counted as two visits. Returns null if the
 * value cannot be parsed.
 */
function normalize_to_calendar_date($value) {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/**
 * Parse patient-supplied "checkbox" style clinical data (medicines,
 * labs, Gamot meds) into a clean list of strings. Handles NULL, empty
 * strings, JSON arrays, comma-separated text, PHP arrays, malformed
 * JSON, whitespace, and duplicate entries — without discarding any
 * genuinely distinct recorded item.
 */
function format_checkbox_items($data) {
    if ($data === null) {
        return [];
    }

    if (is_array($data)) {
        $items = $data;
    } elseif (is_string($data)) {
        $trimmed = trim($data);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        // Only treat the value as JSON when it actually decodes to an
        // array. A bare string like "123" or "null" is valid JSON but
        // is not structured data, so it falls through to comma-split
        // handling instead of being silently discarded.
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $items = $decoded;
        } else {
            $items = explode(',', $trimmed);
        }
    } else {
        $items = [(string)$data];
    }

    $items = array_map(function ($item) {
        if (is_array($item)) {
            // Defensive: collapse unexpected nested arrays rather than
            // fatally erroring or silently dropping the row's data.
            return trim(implode(' ', array_map('strval', $item)));
        }
        return trim((string)$item);
    }, $items);

    $items = array_values(array_filter($items, function ($item) {
        return $item !== '';
    }));

    // Case-insensitive de-duplication while preserving the first-seen
    // original casing/spelling.
    $seen = [];
    $result = [];
    foreach ($items as $item) {
        $key = function_exists('mb_strtolower') ? mb_strtolower($item) : strtolower($item);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $item;
        }
    }
    return $result;
}

/**
 * Case-insensitive de-duplication + natural sort for the cumulative
 * "Total Meds/Labs/Gamot Availed" summary lists, without losing the
 * casing of the first occurrence.
 */
function dedupe_and_sort_items(array $items) {
    $seen = [];
    $out = [];
    foreach ($items as $item) {
        $key = function_exists('mb_strtolower') ? mb_strtolower($item) : strtolower($item);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = $item;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Render a compact, accessible list of item "pills" for a table cell.
 * Long lists collapse behind a native <details>/<summary> disclosure
 * ("N items") instead of stretching the row, while still allowing the
 * user to see every recorded item. All text is HTML-escaped via h().
 */
function render_item_badges(array $items, $inline_limit = 3) {
    global $is_embed, $EMBED_CSS;

    if (empty($items)) {
        return '';
    }

    $count = count($items);
    $list_attr = 'class="item-pills-list"' . ($is_embed ? ' style="' . $EMBED_CSS['pill_list'] . '"' : '');
    $pill_attr = 'class="item-sub-pill"' . ($is_embed ? ' style="' . $EMBED_CSS['sub_pill'] . '"' : '');
    $html = '<div ' . $list_attr . '>';

    if ($count > $inline_limit) {
        $summary_style = $is_embed ? ' style="cursor:pointer;font-size:13px;font-weight:700;color:#0f766e;"' : '';
        $html .= '<details class="item-details"><summary' . $summary_style . '>' . $count . ' items — click to view</summary><div class="item-pills-inner">';
        foreach ($items as $it) {
            $html .= '<span ' . $pill_attr . '>• ' . h($it) . '</span>';
        }
        $html .= '</div></details>';
    } else {
        foreach ($items as $it) {
            $html .= '<span ' . $pill_attr . '>• ' . h($it) . '</span>';
        }
    }

    $html .= '</div>';
    return $html;
}

// =============================================================
// 2. AJAX AUTOCOMPLETE ENDPOINT
// =============================================================
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');

    $term = normalize_name_input($_GET['term'] ?? '');
    $term = mb_substr($term, 0, 100);

    if ($term === '') {
        echo json_encode([]);
        exit;
    }

    try {
        $words = array_values(array_filter(explode(' ', $term), function ($w) {
            return $w !== '';
        }));

        $sql = "SELECT DISTINCT patient_name FROM daily_records WHERE patient_name IS NOT NULL AND patient_name != ''";

        $params = [];
        $types = '';
        $conditions = [];

        foreach ($words as $word) {
            $conditions[] = "patient_name LIKE ?";
            $params[] = '%' . escape_like_value($word) . '%';
            $types .= 's';
        }

        if (!empty($conditions)) {
            $sql .= " AND (" . implode(" AND ", $conditions) . ")";
        }

        $sql .= " ORDER BY patient_name ASC LIMIT 12";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Autocomplete query failed to prepare.');
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $results = [];
        while ($row = $res->fetch_assoc()) {
            $results[] = $row['patient_name'];
        }
        $stmt->close();

        // Kept as a bare JSON array for backward compatibility with any
        // existing consumer of this endpoint (e.g. index.php). Errors
        // are distinguished by returning an object instead — the
        // frontend checks Array.isArray() to tell the two apart.
        echo json_encode($results);
    } catch (Throwable $e) {
        error_log('patient_history.php autocomplete error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search is temporarily unavailable. Please try again.']);
    }
    exit;
}

// =============================================================
// 4. CSV EXPORT HANDLER
// =============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($_GET['patient'])) {
    $patient_name = normalize_name_input($_GET['patient']);
    $patient_name = mb_substr($patient_name, 0, 190);

    if ($patient_name === '') {
        http_response_code(400);
        exit('Invalid patient parameter.');
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                dr.record_date,
                dr.patient_name,
                COALESCE(p.physician_name, '') AS physician,
                COALESCE(mt.meds_type_name, '') AS meds_type,
                dr.has_meds,
                dr.has_labs,
                dr.has_gamot_meds,
                COALESCE(dr.meds, dr.medications, '') AS meds,
                COALESCE(dr.labs, dr.laboratory, '') AS labs,
                COALESCE(dr.gamot, dr.gamot_meds, '') AS gamot,
                dr.remarks,
                dr.created_at
            FROM daily_records dr
            LEFT JOIN physicians p ON dr.physician_id = p.physician_id
            LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
            WHERE LOWER(TRIM(dr.patient_name)) LIKE LOWER(TRIM(?))
            ORDER BY dr.record_date DESC
        ");
        if ($stmt === false) {
            throw new Exception('CSV export query failed to prepare.');
        }
        // Uses the exact same partial, case-insensitive, whitespace-
        // tolerant matching as the on-screen history query below, so the
        // exported rows always match what the user is currently viewing.
        $search = '%' . escape_like_value($patient_name) . '%';
        $stmt->bind_param("s", $search);
        $stmt->execute();
        $result = $stmt->get_result();

        // NOTE (security): filename is stripped to alphanumerics only.
        // Cell-level CSV/formula-injection escaping (values beginning
        // with = + - @) and quote/newline handling are the
        // responsibility of the existing export_csv() helper in
        // config.php, which is outside this file's scope per the
        // "no changes to other files" constraint — see the audit notes
        // for a flagged recommendation to verify/harden it there.
        $clean_filename = 'patient_history_' . preg_replace('/[^a-zA-Z0-9]/', '_', $patient_name) . '.csv';
        export_csv($clean_filename, ['Record Date', 'Patient Name', 'Physician', 'Meds Type', 'Has Meds', 'Has Labs', 'Has Gamot Meds', 'Meds', 'Labs', 'Gamot', 'Remarks', 'Created At'], $result);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('patient_history.php CSV export error: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to generate export at this time. Please try again later.');
    }
    exit;
}

// =============================================================
// 5. SEARCH PARAMETER RESOLUTION
// =============================================================
$patient_name = normalize_name_input($_GET['patient_name'] ?? $_GET['patient'] ?? $_GET['name'] ?? $_POST['patient_name'] ?? '');
$patient_name = mb_substr($patient_name, 0, 190);

// Embed mode: this page is also fetched as a fragment inside the Patient
// History overlay on index.php. In that context we skip the site chrome
// (header/footer/nav/full-page styles), the search box, and the full
// patient directory — the overlay already knows which patient it wants
// and supplies its own container/styling.
$is_embed = isset($_GET['embed']) && $_GET['embed'] == '1';
$embed_link_attrs = $is_embed ? ' target="_blank" rel="noopener"' : '';

/**
 * Embed mode is injected into index.php's overlay via a JS fetch(), and
 * in that environment the <style> block below is unreliable — it can be
 * stripped by the host's own HTML sanitizer, or overridden by the
 * host's global CSS (this is why earlier class-only styling looked
 * flat/unstyled in the overlay). To make the embedded view visually
 * solid regardless of the host page's CSS pipeline, embed mode renders
 * every card/table/pill with inline styles instead of relying purely on
 * classes. es() returns a ready-to-use style="" attribute only when in
 * embed mode; standalone mode keeps using the class-based stylesheet.
 */
function es($css) {
    global $is_embed;
    return $is_embed ? ' style="' . $css . '"' : '';
}

// The overlay in index.php lays its injected content out using either a
// CSS multi-column layout (`columns`/`column-count`) or a multi-column
// CSS grid — either way, everything we render gets sliced into
// side-by-side newspaper-style columns instead of stacking top to
// bottom, and overflows the narrow panel horizontally. ESCAPE_COLUMNS
// is applied to the outer wrapper AND every top-level block (each KPI
// card, each panel) so each one is forced to behave as a normal,
// full-width, single block regardless of which column mechanism the
// host is using.
$ESCAPE_COLUMNS = 'column-span:all;-webkit-column-span:all;break-inside:avoid-column;page-break-inside:avoid;grid-column:1/-1;float:none;clear:both;width:100%;max-width:100%;box-sizing:border-box;';

$EMBED_CSS = [
    'container'      => $ESCAPE_COLUMNS . 'display:block;columns:1;column-count:1;padding:10px;overflow-x:hidden;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1e293b;',
    'kpi_grid'       => $ESCAPE_COLUMNS . 'display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px;',
    'kpi_card'       => 'background:#ffffff;border:1px solid #dbe3ec;border-left:4px solid %ACCENT%;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(15,23,42,0.08);box-sizing:border-box;break-inside:avoid;',
    'kpi_title'      => 'font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:#64748b;margin:0;',
    'kpi_value'      => 'font-size:26px;font-weight:800;color:#0f172a;margin:6px 0 0;line-height:1.1;',
    'card_panel'     => $ESCAPE_COLUMNS . 'display:block;background:#ffffff;border:1px solid #dbe3ec;border-radius:10px;padding:18px;margin:0 0 18px;box-shadow:0 1px 3px rgba(15,23,42,0.06);',
    'card_h3'        => 'font-size:16px;font-weight:700;color:#0f172a;margin:0 0 14px;',
    'note'           => 'font-size:13px;color:#64748b;margin:0 0 14px;line-height:1.55;',
    'overview_grid'  => 'display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;',
    'overview_box'   => 'background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;box-sizing:border-box;break-inside:avoid;',
    'overview_label' => 'display:block;font-size:11px;text-transform:uppercase;letter-spacing:0.03em;color:#64748b;margin:0 0 8px;font-weight:700;',
    'overview_list'  => 'font-size:14px;white-space:pre-wrap;word-break:break-word;margin:0;color:#334155;line-height:1.6;',
    'status_yes'     => 'display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#d1fae5;color:#065f46;',
    'status_no'      => 'display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;',
    'meds_tag'       => 'display:inline-block;background:#f0fdfa;color:#0f766e;font-weight:700;padding:4px 10px;border-radius:4px;font-size:13px;',
    'pill_list'      => 'display:flex;flex-direction:column;gap:5px;margin-top:8px;align-items:flex-start;',
    'sub_pill'       => 'font-size:13px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;padding:4px 9px;border-radius:4px;white-space:normal;text-align:left;',
    'physician_pills'=> 'display:flex;flex-wrap:wrap;gap:8px;',
    'table_wrapper'  => 'overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #dbe3ec;border-radius:8px;max-width:100%;',
    'table'          => 'width:100%;border-collapse:collapse;font-size:14px;text-align:left;',
    'th'             => 'background:#f8fafc;padding:12px 14px;color:#64748b;font-weight:700;border-bottom:1px solid #e2e8f0;font-size:12px;text-transform:uppercase;letter-spacing:0.02em;white-space:nowrap;',
    'td'             => 'padding:14px;border-bottom:1px solid #e2e8f0;vertical-align:middle;',
    'empty'          => 'padding:36px 18px;text-align:center;color:#64748b;font-size:14px;',
];

function ekpi_card($accent) {
    global $EMBED_CSS;
    return str_replace('%ACCENT%', $accent, $EMBED_CSS['kpi_card']);
}

// =============================================================
// 6. PATIENT DIRECTORY QUERY
// =============================================================
// Only needed in standalone mode (the directory is intentionally
// omitted from the embed fragment), so we skip it entirely for embed
// requests to save a query on every overlay open.
//
// The directory is capped to the most recently active patients so it
// stays fast and renders quickly even as daily_records grows into the
// tens/hundreds of thousands of rows. Any patient can still be found
// via the search box / autocomplete above regardless of this cap — see
// the "Business-Rule Assumptions" notes for why this cap was added.
$all_patients_result = null;
$directory_capped = false;
$directory_limit = 300;
if (!$is_embed) {
    $all_patients_query = "
        SELECT patient_name,
               MAX(record_date) AS last_visit,
               COUNT(*) AS visit_count
        FROM daily_records
        WHERE patient_name IS NOT NULL AND patient_name != ''
        GROUP BY patient_name
        ORDER BY last_visit DESC, patient_name ASC
        LIMIT " . ((int)$directory_limit + 1) . "
    ";
    $all_patients_result = $conn->query($all_patients_query);
}

if (!$is_embed) include 'includes/header.php';
?>

<?php if (!$is_embed): ?>
<style>
/* Modern Healthcare Dashboard Design System */
:root {
    --primary: #0f766e;          /* Clinical Teal */
    --primary-hover: #115e59;
    --primary-light: #f0fdfa;
    --bg-main: #f8fafc;
    --bg-card: #ffffff;
    --border-subtle: #e2e8f0;
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --success: #059669;
    --danger: #dc2626;
    --warning: #d97706;
    --radius-sm: 6px;
    --radius-md: 10px;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}

body {
    background-color: var(--bg-main);
    color: var(--text-primary);
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.dashboard-container {
    max-width: 1320px;
    margin: 0 auto;
    padding: 1.5rem 1rem;
}

/* Header Bar */
.header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    gap: 1rem;
    flex-wrap: wrap;
}

.header-title-wrapper h2 {
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.01em;
}

.header-title-wrapper p {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin: 0;
}

/* Modern Minimalist Buttons */
.btn-custom {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.875rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-subtle);
    background: var(--bg-card);
    color: var(--text-primary);
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-custom:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: var(--text-primary);
}

.btn-custom.btn-primary-custom {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-custom.btn-primary-custom:hover {
    background: var(--primary-hover);
    border-color: var(--primary-hover);
    color: white;
}

.btn-custom:focus-visible,
.modern-input:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.btn-custom.exporting {
    opacity: 0.7;
    pointer-events: none;
}

/* Filter Card & Autocomplete */
.control-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.filter-form {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filter-form label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.autocomplete-wrapper {
    position: relative;
    display: inline-block;
    flex: 1;
    min-width: 280px;
}

.modern-input {
    width: 100%;
    padding: 0.5rem 0.875rem;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    color: var(--text-primary);
    background: #ffffff;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.modern-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.1);
}

/* Autocomplete Dropdown Menu */
.autocomplete-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    max-height: 250px;
    overflow-y: auto;
    display: none;
}

.autocomplete-item {
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-primary);
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.1s ease;
}

.autocomplete-item:last-child {
    border-bottom: none;
}

.autocomplete-item:hover, .autocomplete-item.selected {
    background-color: var(--primary-light);
    color: var(--primary);
}

.autocomplete-item strong {
    font-weight: 700;
    color: var(--primary);
}

.autocomplete-message {
    padding: 0.75rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-secondary);
    text-align: center;
}

/* KPI Summary Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.kpi-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--border-subtle);
}

.kpi-card.accent-primary::before { background: var(--primary); }
.kpi-card.accent-teal::before { background: #0d9488; }
.kpi-card.accent-warning::before { background: var(--warning); }
.kpi-card.accent-info::before { background: #0369a1; }
.kpi-card.accent-danger::before { background: var(--danger); }

.kpi-card .kpi-title {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 0.04em;
}

.kpi-card .kpi-value {
    font-size: 1.625rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 0.35rem;
    line-height: 1;
}

/* Card Panels */
.card-panel {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-md);
    padding: 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.card-panel h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-top: 0;
    margin-bottom: 1rem;
}

.match-note {
    margin: 0 0 1rem;
    font-size: 0.78rem;
    color: var(--text-secondary);
}

.visit-meta-line {
    margin: 0 0 1rem;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.visit-meta-line strong {
    color: var(--text-primary);
}

/* Overview Summary Box Grid */
.overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
}

.overview-box {
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    padding: 1rem;
}

.overview-box strong {
    display: block;
    font-size: 0.775rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.overview-list {
    font-size: 0.875rem;
    color: var(--text-primary);
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
}

/* Physician summary pills */
.physician-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

/* Modern Data Table Styling */
.table-wrapper {
    overflow-x: auto;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
}

.modern-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    text-align: left;
    white-space: nowrap;
}

.modern-table th {
    background: #f8fafc;
    padding: 0.75rem 1rem;
    color: var(--text-secondary);
    font-weight: 600;
    border-bottom: 1px solid var(--border-subtle);
}

.modern-table td {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--border-subtle);
    color: var(--text-primary);
    vertical-align: middle;
}

.modern-table tbody tr:last-child td {
    border-bottom: none;
}

.modern-table tbody tr:hover {
    background-color: #f8fafc;
}

/* Badges & Tags */
.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.status-pill.yes { background: #d1fae5; color: #065f46; }
.status-pill.no { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

.meds-type-tag {
    display: inline-block;
    background: var(--primary-light);
    color: var(--primary);
    font-weight: 600;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
}

.item-pills-list {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-top: 0.35rem;
    align-items: flex-start;
}

.item-sub-pill {
    font-size: 0.75rem;
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    color: var(--text-secondary);
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    white-space: normal;
    text-align: left;
}

.item-details summary {
    cursor: pointer;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--primary);
    list-style: none;
}
.item-details summary::-webkit-details-marker { display: none; }
.item-details summary::before { content: "▸ "; }
.item-details[open] summary::before { content: "▾ "; }
.item-details-inner,
.item-pills-inner {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-top: 0.3rem;
}

.empty-state {
    padding: 2.5rem 1rem;
    text-align: center;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

/* Timeline Badges */
.timeline-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.visit-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.65rem;
    background-color: #ffffff;
    border: 1px solid var(--border-subtle);
    color: var(--primary);
    border-radius: var(--radius-sm);
    font-weight: 500;
    font-size: 0.8rem;
    text-decoration: none;
    transition: all 0.15s ease;
}

.visit-badge:hover {
    background-color: var(--primary-light);
    border-color: var(--primary);
}

/* Patient Directory Grid */
.directory-filter {
    margin-bottom: 0.75rem;
    max-width: 320px;
}

.directory-note {
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin: -0.5rem 0 0.75rem;
}

.patient-directory-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 0.75rem;
    max-height: 320px;
    overflow-y: auto;
    padding: 0.25rem;
}

.patient-directory-item {
    display: flex;
    flex-direction: column;
    padding: 0.75rem;
    background: var(--bg-main);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    text-decoration: none;
    color: var(--text-primary);
    transition: all 0.15s ease;
}

.patient-directory-item:hover {
    border-color: var(--primary);
    background: var(--primary-light);
}

.patient-directory-item .name {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
    overflow-wrap: anywhere;
}

.patient-directory-item .meta {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 0.2rem;
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

/* Mobile responsiveness */
@media (max-width: 640px) {
    .header-bar { flex-direction: column; align-items: flex-start; }
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-form label { margin-bottom: -0.25rem; }
    .autocomplete-wrapper { min-width: 0; width: 100%; }
    .filter-form .btn-custom { width: 100%; justify-content: center; }
    .overview-grid { grid-template-columns: 1fr; }
    .patient-directory-grid { grid-template-columns: 1fr; max-height: 280px; }
    .directory-filter { max-width: none; }
}

@media print {
    .header-bar, .control-card, .visit-calendar-card, .directory-card, .export-csv-link { display: none !important; }
    .card-panel { border: none; box-shadow: none; padding: 0; }
    body { background: #fff; }
}
</style>
<?php endif; ?>

<?php if ($is_embed): ?>
<style>
/* Compact, scoped embed styling. Everything is namespaced under
   .patient-history-app so it can never leak onto (or be overridden by)
   the host page's own global styles, per the embed-isolation
   requirements. It reuses the host page's own CSS custom properties
   (--primary, --border-color, etc. from index.php's :root) where
   available, falling back to sensible defaults otherwise. */
.patient-history-app { padding: 0.25rem; max-width: none; width: 100%; box-sizing: border-box; font-size: 1rem; }
.patient-history-app * { box-sizing: border-box; }
.patient-history-app .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.patient-history-app .kpi-card { background: var(--bg-surface, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: var(--radius-md, 10px); padding: 1.15rem; position: relative; overflow: hidden; }
.patient-history-app .kpi-card::before { content: ""; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--primary, #2563eb); opacity: 0.6; }
.patient-history-app .kpi-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted, #64748b); }
.patient-history-app .kpi-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; margin-top: 0.35rem; line-height: 1.1; }
.patient-history-app .card-panel { background: var(--bg-surface, #fff); border: 1px solid var(--border-color, #e2e8f0); border-radius: var(--radius-md, 10px); padding: 1.5rem; margin-bottom: 1.5rem; }
.patient-history-app .card-panel h3 { font-size: 1.1rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem; }
.patient-history-app .match-note,
.patient-history-app .visit-meta-line { font-size: 0.85rem; color: var(--text-muted, #64748b); margin: 0 0 1rem; line-height: 1.5; }
.patient-history-app .overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.patient-history-app .overview-box { background: var(--bg-body, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 1.1rem; }
.patient-history-app .overview-box strong { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--text-muted, #64748b); margin-bottom: 0.5rem; }
.patient-history-app .overview-list { font-size: 0.9rem; white-space: pre-wrap; word-break: break-word; margin: 0; color: #334155; line-height: 1.6; }
.patient-history-app .status-pill { display: inline-flex; align-items: center; padding: 0.25rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.02em; }
.patient-history-app .status-pill.yes { background: #d1fae5; color: #065f46; }
.patient-history-app .status-pill.no { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
.patient-history-app .meds-type-tag { display: inline-block; background: var(--primary-light, #eff6ff); color: var(--primary, #2563eb); font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.85rem; }
.patient-history-app .item-pills-list { display: flex; flex-direction: column; gap: 0.3rem; margin-top: 0.5rem; align-items: flex-start; }
.patient-history-app .item-sub-pill { font-size: 0.82rem; background: var(--bg-body, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); color: var(--text-muted, #64748b); padding: 0.25rem 0.6rem; border-radius: 4px; white-space: normal; text-align: left; }
.patient-history-app .item-details summary { cursor: pointer; font-size: 0.8rem; font-weight: 700; color: var(--primary, #2563eb); list-style: none; padding: 0.1rem 0; }
.patient-history-app .item-details summary::-webkit-details-marker { display: none; }
.patient-history-app .item-details summary::before { content: "▸ "; }
.patient-history-app .item-details[open] summary::before { content: "▾ "; }
.patient-history-app .physician-pills { display: flex; flex-wrap: wrap; gap: 0.6rem; }
.patient-history-app .table-wrapper { overflow-x: auto; border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; }
.patient-history-app .modern-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; text-align: left; white-space: nowrap; }
.patient-history-app .modern-table th { background: var(--bg-body, #f8fafc); padding: 0.9rem 1.1rem; color: var(--text-muted, #64748b); font-weight: 700; border-bottom: 1px solid var(--border-color, #e2e8f0); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.02em; }
.patient-history-app .modern-table td { padding: 1rem 1.1rem; border-bottom: 1px solid var(--border-color, #e2e8f0); vertical-align: middle; }
.patient-history-app .empty-state { padding: 2.5rem 1.25rem; text-align: center; color: var(--text-muted, #64748b); font-size: 0.9rem; }
.patient-history-app .header-title-wrapper h2 { font-size: 1.2rem; margin: 0 0 0.5rem; }
.patient-history-app .directory-note { font-size: 0.82rem; }
</style>
<?php endif; ?>

<div class="dashboard-container patient-history-app"<?= es($EMBED_CSS['container']) ?>>
    <!-- Header -->
    <?php if (!$is_embed): ?>
    <header class="header-bar">
        <div class="header-title-wrapper">
            <h2>Patient History Tracker</h2>
            <p>Comprehensive clinical encounter lookup and longitudinal patient record auditing.</p>
        </div>
        <?php if ($patient_name !== ''): ?>
            <a href="patient_history.php" class="btn-custom">⬅️ Back to Directory</a>
        <?php endif; ?>
    </header>

    <!-- Search Card with AJAX Autocomplete -->
    <section class="control-card">
        <form class="filter-form" method="get" action="patient_history.php" id="searchForm" autocomplete="off" role="search">
            <label for="patient">Patient Name</label>
            <div class="autocomplete-wrapper">
                <input
                    type="text"
                    name="patient"
                    id="patient"
                    class="modern-input"
                    value="<?= h($patient_name) ?>"
                    placeholder="Type patient name to search records..."
                    required
                    role="combobox"
                    aria-expanded="false"
                    aria-autocomplete="list"
                    aria-controls="autocompleteDropdown"
                    aria-label="Patient name search with autocomplete suggestions"
                >
                <div id="autocompleteDropdown" class="autocomplete-dropdown" role="listbox" aria-live="polite"></div>
            </div>
            <button type="submit" class="btn-custom btn-primary-custom">Search Records</button>
            <?php if ($patient_name !== ''): ?>
                <a href="patient_history.php" class="btn-custom">Clear</a>
            <?php endif; ?>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($patient_name !== ''):
        $history_error = null;
        $records = null;

        try {
            $stmt = $conn->prepare("
                SELECT
                    dr.*,
                    p.physician_name,
                    mt.meds_type_name,
                    mt.is_consultation
                FROM daily_records dr
                LEFT JOIN physicians p ON dr.physician_id = p.physician_id
                LEFT JOIN meds_types mt ON dr.meds_type_id = mt.meds_type_id
                WHERE LOWER(TRIM(dr.patient_name)) LIKE LOWER(TRIM(?))
                ORDER BY dr.record_date DESC, dr.created_at DESC
            ");
            if ($stmt === false) {
                throw new Exception('History query failed to prepare.');
            }
            // physician_id / meds_type_id are looked up by their primary
            // key, so each LEFT JOIN can add at most one matching row —
            // these joins cannot multiply or duplicate a daily_records row.
            $search = '%' . escape_like_value($patient_name) . '%';
            $stmt->bind_param("s", $search);
            $stmt->execute();
            $records = $stmt->get_result();
        } catch (Throwable $e) {
            error_log('patient_history.php history query error: ' . $e->getMessage());
            $history_error = true;
        }
    ?>

    <?php if ($history_error): ?>
        <section class="card-panel"<?= es($EMBED_CSS['card_panel']) ?>>
            <div class="empty-state"<?= es($EMBED_CSS['empty']) ?>>We couldn't load this patient's history right now. Please try again in a moment.</div>
        </section>
    <?php else:
        // Total Visits = total matching daily_records rows (one row per
        // consultation/record). This intentionally stays distinct from
        // "Unique Visit Dates" below — see audit notes.
        $total_visits     = $records->num_rows;
        $has_meds_count   = 0;
        $has_labs_count   = 0;
        $has_gamot_count  = 0;
        $all_meds_list    = [];
        $all_labs_list    = [];
        $all_gamot_list   = [];
        $dates_visited    = []; // calendar_date (Y-m-d) => true
        $physician_counts = []; // physician_name => record count
        $first_visit_ts   = null;
        $last_visit_ts    = null;

        while ($r = $records->fetch_assoc()) {
            $calendar_date = normalize_to_calendar_date($r['record_date']);
            if ($calendar_date !== null) {
                $dates_visited[$calendar_date] = true;
                $ts = strtotime($calendar_date);
                if ($first_visit_ts === null || $ts < $first_visit_ts) $first_visit_ts = $ts;
                if ($last_visit_ts === null || $ts > $last_visit_ts) $last_visit_ts = $ts;
            }

            $raw_meds  = $r['meds'] ?? $r['medications'] ?? $r['record_medications'] ?? '';
            $raw_labs  = $r['labs'] ?? $r['laboratory'] ?? $r['record_labs'] ?? '';
            $raw_gamot = $r['gamot'] ?? $r['gamot_meds'] ?? $r['record_gamot'] ?? '';

            $row_meds  = format_checkbox_items($raw_meds);
            $row_labs  = format_checkbox_items($raw_labs);
            $row_gamot = format_checkbox_items($raw_gamot);

            if (!empty($r['has_meds']) || !empty($row_meds)) {
                $has_meds_count++;
                foreach ($row_meds as $m) $all_meds_list[] = $m;
            }
            if (!empty($r['has_labs']) || !empty($row_labs)) {
                $has_labs_count++;
                foreach ($row_labs as $l) $all_labs_list[] = $l;
            }
            if (!empty($r['has_gamot_meds']) || !empty($row_gamot)) {
                $has_gamot_count++;
                foreach ($row_gamot as $g) $all_gamot_list[] = $g;
            }

            $phys_name = trim((string)($r['physician_name'] ?? ''));
            if ($phys_name !== '') {
                $physician_counts[$phys_name] = ($physician_counts[$phys_name] ?? 0) + 1;
            }
        }

        $distinct_dates = count($dates_visited);
        krsort($dates_visited); // newest calendar date first, for the timeline
        arsort($physician_counts); // most-seen physician first (factual count only, no ranking implied)
        $records->data_seek(0);
    ?>

        <!-- KPI Metrics Grid -->
        <section class="kpi-grid"<?= es($EMBED_CSS['kpi_grid']) ?>>
            <div class="kpi-card accent-primary"<?= es(ekpi_card('#0f766e')) ?>>
                <div class="kpi-title"<?= es($EMBED_CSS['kpi_title']) ?>>Total Records</div>
                <div class="kpi-value"<?= es($EMBED_CSS['kpi_value']) ?>><?= (int)$total_visits ?></div>
            </div>
            <div class="kpi-card accent-teal"<?= es(ekpi_card('#0d9488')) ?>>
                <div class="kpi-title"<?= es($EMBED_CSS['kpi_title']) ?>>Unique Visit Dates</div>
                <div class="kpi-value"<?= es($EMBED_CSS['kpi_value']) ?>><?= (int)$distinct_dates ?></div>
            </div>
            <div class="kpi-card accent-warning"<?= es(ekpi_card('#d97706')) ?>>
                <div class="kpi-title"<?= es($EMBED_CSS['kpi_title']) ?>>Records with Meds</div>
                <div class="kpi-value"<?= es($EMBED_CSS['kpi_value']) ?>><?= (int)$has_meds_count ?></div>
            </div>
            <div class="kpi-card accent-info"<?= es(ekpi_card('#0369a1')) ?>>
                <div class="kpi-title"<?= es($EMBED_CSS['kpi_title']) ?>>Records with Labs</div>
                <div class="kpi-value"<?= es($EMBED_CSS['kpi_value']) ?>><?= (int)$has_labs_count ?></div>
            </div>
            <div class="kpi-card accent-danger"<?= es(ekpi_card('#dc2626')) ?>>
                <div class="kpi-title"<?= es($EMBED_CSS['kpi_title']) ?>>Records with Gamot Meds</div>
                <div class="kpi-value"<?= es($EMBED_CSS['kpi_value']) ?>><?= (int)$has_gamot_count ?></div>
            </div>
        </section>

        <!-- Cumulative Summary Panel -->
        <section class="card-panel"<?= es($EMBED_CSS['card_panel']) ?>>
            <h3<?= es($EMBED_CSS['card_h3']) ?>>Comprehensive Overview of Availed Items</h3>
            <div class="overview-grid"<?= es($EMBED_CSS['overview_grid']) ?>>
                <div class="overview-box"<?= es($EMBED_CSS['overview_box']) ?>>
                    <strong<?= es($EMBED_CSS['overview_label']) ?>>Total Meds Availed</strong>
                    <p class="overview-list"<?= es($EMBED_CSS['overview_list']) ?>><?php
                        $unique_meds = dedupe_and_sort_items($all_meds_list);
                        echo !empty($unique_meds) ? '• ' . h(implode("\n• ", $unique_meds)) : 'None recorded';
                    ?></p>
                </div>
                <div class="overview-box"<?= es($EMBED_CSS['overview_box']) ?>>
                    <strong<?= es($EMBED_CSS['overview_label']) ?>>Total Labs Availed</strong>
                    <p class="overview-list"<?= es($EMBED_CSS['overview_list']) ?>><?php
                        $unique_labs = dedupe_and_sort_items($all_labs_list);
                        echo !empty($unique_labs) ? '• ' . h(implode("\n• ", $unique_labs)) : 'None recorded';
                    ?></p>
                </div>
                <div class="overview-box"<?= es($EMBED_CSS['overview_box']) ?>>
                    <strong<?= es($EMBED_CSS['overview_label']) ?>>Total Gamot Meds Availed</strong>
                    <p class="overview-list"<?= es($EMBED_CSS['overview_list']) ?>><?php
                        $unique_gamot = dedupe_and_sort_items($all_gamot_list);
                        echo !empty($unique_gamot) ? '• ' . h(implode("\n• ", $unique_gamot)) : 'None recorded';
                    ?></p>
                </div>
            </div>
        </section>

        <?php if (!empty($physician_counts)): ?>
        <section class="card-panel"<?= es($EMBED_CSS['card_panel']) ?>>
            <h3<?= es($EMBED_CSS['card_h3']) ?>>Physicians Involved</h3>
            <div class="physician-pills"<?= es($EMBED_CSS['physician_pills']) ?>>
                <?php foreach ($physician_counts as $pname => $pcount): ?>
                    <span class="item-sub-pill"<?= es($EMBED_CSS['sub_pill']) ?>>🩺 <?= h($pname) ?> — <?= (int)$pcount ?> record<?= $pcount === 1 ? '' : 's' ?></span>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Detailed Visit History Table Panel -->
        <?php
            $embed_primary = '#0f766e';
            $link_style = $is_embed
                ? 'color:' . $embed_primary . ';font-weight:600;text-decoration:none;'
                : 'color: var(--primary); font-weight: 600; text-decoration: none;';
        ?>
        <section class="card-panel"<?= es($EMBED_CSS['card_panel']) ?>>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom: 0.5rem;">
                <h3 style="margin:0;<?= $is_embed ? 'font-size:16px;font-weight:700;color:#0f172a;' : '' ?>">Visit History for <span style="color: <?= $is_embed ? $embed_primary : 'var(--primary)' ?>;"><?= h($patient_name) ?></span></h3>
                <a
                    href="?patient=<?= h(urlencode($patient_name)) ?>&export=csv"
                    class="btn-custom export-csv-link"
                    <?= $embed_link_attrs ?>
                    aria-label="Export this patient's visit history as CSV"
                    <?= es('display:inline-flex;align-items:center;gap:6px;padding:8px 14px;font-size:13px;font-weight:600;border-radius:6px;border:1px solid ' . $embed_primary . ';background:#ffffff;color:' . $embed_primary . ';text-decoration:none;') ?>
                ><span>📥</span> Export CSV</a>
            </div>

            <p class="match-note"<?= es($EMBED_CSS['note']) ?>>Matched by patient name (partial, case-insensitive, whitespace-tolerant). If two different people share this name, their records will appear together below.</p>

            <?php if ($first_visit_ts && $last_visit_ts): ?>
                <p class="visit-meta-line"<?= es($EMBED_CSS['note']) ?>>
                    First recorded visit: <strong><?= h(date('M j, Y', $first_visit_ts)) ?></strong>
                    &nbsp;·&nbsp; Most recent visit: <strong><?= h(date('M j, Y', $last_visit_ts)) ?></strong>
                    &nbsp;·&nbsp; Physicians seen: <strong><?= count($physician_counts) ?></strong>
                </p>
            <?php endif; ?>

            <?php if ($total_visits === 0): ?>
                <div class="empty-state"<?= es($EMBED_CSS['empty']) ?>>No medical visit records found matching "<strong><?= h($patient_name) ?></strong>". Try checking the directory below or refining your search.</div>
            <?php else: ?>
                <div class="table-wrapper"<?= es($EMBED_CSS['table_wrapper']) ?>>
                    <table class="modern-table"<?= es($EMBED_CSS['table']) ?>>
                        <caption class="sr-only" style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0);">Visit history records for <?= h($patient_name) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"<?= es($EMBED_CSS['th']) ?>>Record Date</th>
                                <th scope="col"<?= es($EMBED_CSS['th']) ?>>Attending Physician</th>
                                <th scope="col"<?= es($EMBED_CSS['th']) ?>>Meds Type</th>
                                <th scope="col" style="text-align: center;<?= $is_embed ? $EMBED_CSS['th'] : '' ?>">Meds Status &amp; Input</th>
                                <th scope="col" style="text-align: center;<?= $is_embed ? $EMBED_CSS['th'] : '' ?>">Labs Status &amp; Input</th>
                                <th scope="col" style="text-align: center;<?= $is_embed ? $EMBED_CSS['th'] : '' ?>">Gamot Status &amp; Input</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($r = $records->fetch_assoc()):
                                $raw_meds  = $r['meds'] ?? $r['medications'] ?? $r['record_medications'] ?? '';
                                $raw_labs  = $r['labs'] ?? $r['laboratory'] ?? $r['record_labs'] ?? '';
                                $raw_gamot = $r['gamot'] ?? $r['gamot_meds'] ?? $r['record_gamot'] ?? '';

                                $row_meds  = format_checkbox_items($raw_meds);
                                $row_labs  = format_checkbox_items($raw_labs);
                                $row_gamot = format_checkbox_items($raw_gamot);

                                $row_calendar_date = normalize_to_calendar_date($r['record_date']);
                            ?>
                                <?php
                                    $meds_yn  = (!empty($r['has_meds']) || !empty($row_meds));
                                    $labs_yn  = (!empty($r['has_labs']) || !empty($row_labs));
                                    $gamot_yn = (!empty($r['has_gamot_meds']) || !empty($row_gamot));
                                    $td_attr  = es($EMBED_CSS['td']);
                                ?>
                                <tr>
                                    <td<?= $td_attr ?>>
                                        <?php if ($row_calendar_date !== null): ?>
                                            <a href="index.php?date=<?= h($row_calendar_date) ?>" style="<?= $link_style ?>"<?= $embed_link_attrs ?>>
                                                📅 <?= h(format_display_date($r['record_date'])) ?>
                                            </a>
                                        <?php else: ?>
                                            <span>—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td<?= $td_attr ?>><span style="font-weight: 600;"><?= h($r['physician_name'] ?? '—') ?></span></td>
                                    <td<?= $td_attr ?>>
                                        <?php if ($r['meds_type_name']): ?>
                                            <span class="meds-type-tag"<?= es($EMBED_CSS['meds_tag']) ?>><?= h($r['meds_type_name']) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;<?= $is_embed ? $EMBED_CSS['td'] : '' ?>">
                                        <span class="status-pill <?= $meds_yn ? 'yes' : 'no' ?>"<?= es($meds_yn ? $EMBED_CSS['status_yes'] : $EMBED_CSS['status_no']) ?>>
                                            <?= $meds_yn ? 'YES' : 'NO' ?>
                                        </span>
                                        <?= render_item_badges($row_meds) ?>
                                    </td>
                                    <td style="text-align: center;<?= $is_embed ? $EMBED_CSS['td'] : '' ?>">
                                        <span class="status-pill <?= $labs_yn ? 'yes' : 'no' ?>"<?= es($labs_yn ? $EMBED_CSS['status_yes'] : $EMBED_CSS['status_no']) ?>>
                                            <?= $labs_yn ? 'YES' : 'NO' ?>
                                        </span>
                                        <?= render_item_badges($row_labs) ?>
                                    </td>
                                    <td style="text-align: center;<?= $is_embed ? $EMBED_CSS['td'] : '' ?>">
                                        <span class="status-pill <?= $gamot_yn ? 'yes' : 'no' ?>"<?= es($gamot_yn ? $EMBED_CSS['status_yes'] : $EMBED_CSS['status_no']) ?>>
                                            <?= $gamot_yn ? 'YES' : 'NO' ?>
                                        </span>
                                        <?= render_item_badges($row_gamot) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Rapid Visit Timeline Panel (unique visit dates, newest first) -->
        <?php if (!$is_embed): ?>
        <section class="card-panel visit-calendar-card">
            <h3>Rapid Visit Timeline</h3>
            <?php if ($distinct_dates === 0): ?>
                <div class="empty-state" style="padding: 0.5rem;">No registered visit dates available.</div>
            <?php else: ?>
                <div class="timeline-container">
                    <?php foreach (array_keys($dates_visited) as $dv): ?>
                        <a href="index.php?date=<?= h($dv) ?>" class="visit-badge"<?= $embed_link_attrs ?>>
                            📅 <?= h(format_display_date($dv)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

    <?php endif; // history_error ?>

        <?php if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close(); ?>

    <?php else: ?>
        <section class="card-panel"<?= es($EMBED_CSS['card_panel']) ?>>
            <div class="empty-state"<?= es($EMBED_CSS['empty']) ?>>
                <?= $is_embed
                    ? 'No visits recorded yet for this patient.'
                    : 'Select a patient from the directory below or type a name above to generate their complete visit history, metrics, and records.' ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Patient Directory Section (Showing all patients encoded in the system sorted newest first) -->
    <?php if (!$is_embed): ?>
    <section class="card-panel directory-card">
        <h3>Patient Directory (Latest Encounters First)</h3>
        <?php if ($all_patients_result && $all_patients_result->num_rows > 0):
            $directory_rows = [];
            $row_i = 0;
            while ($pat = $all_patients_result->fetch_assoc()) {
                $row_i++;
                if ($row_i > $directory_limit) { $directory_capped = true; break; }
                $directory_rows[] = $pat;
            }
        ?>
            <div class="directory-filter">
                <input type="text" id="directoryFilter" class="modern-input" placeholder="Filter directory by name…" aria-label="Filter patient directory by name">
            </div>
            <?php if ($directory_capped): ?>
                <p class="directory-note">Showing the <?= (int)$directory_limit ?> most recently active patients. Use the search box above to find any patient not listed here.</p>
            <?php endif; ?>
            <div class="patient-directory-grid" id="patientDirectoryGrid">
                <?php foreach ($directory_rows as $pat): ?>
                    <a href="patient_history.php?patient=<?= h(urlencode($pat['patient_name'])) ?>" class="patient-directory-item">
                        <span class="name"><?= h($pat['patient_name']) ?></span>
                        <span class="meta">
                            <span>Visits: <strong><?= (int)$pat['visit_count'] ?></strong></span>
                            <span>Last: <?= h(format_display_date($pat['last_visit'])) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No patients currently encoded in the database records.</div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php if (!$is_embed): ?>
<!-- AJAX Autocomplete, Keyboard Navigation & Directory Filter Script -->
<script>
(function initPatientHistorySearch() {
    // Guards against duplicate initialization if this script were ever
    // injected more than once into the same document.
    if (window.__patientHistorySearchInit) return;
    window.__patientHistorySearchInit = true;

    function run() {
        var input = document.getElementById('patient');
        var dropdown = document.getElementById('autocompleteDropdown');
        var form = document.getElementById('searchForm');
        if (!input || !dropdown || !form) return;

        var debounceTimer = null;
        var currentFocus = -1;
        var activeController = null;
        var latestQuery = '';

        function closeDropdown() {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            currentFocus = -1;
        }

        function renderMessage(text) {
            dropdown.innerHTML = '';
            var div = document.createElement('div');
            div.className = 'autocomplete-message';
            div.textContent = text;
            dropdown.appendChild(div);
            dropdown.style.display = 'block';
        }

        // Builds the highlighted suggestion label using safe DOM APIs
        // only (textContent / createTextNode) — never innerHTML with
        // unescaped patient-controlled text, to prevent stored-XSS via
        // patient names.
        function buildHighlightedLabel(name, query) {
            var frag = document.createDocumentFragment();
            var lowerName = name.toLowerCase();
            var lowerQuery = query.toLowerCase();
            var idx = lowerQuery ? lowerName.indexOf(lowerQuery) : -1;

            if (idx === -1) {
                frag.appendChild(document.createTextNode(name));
                return frag;
            }

            frag.appendChild(document.createTextNode(name.slice(0, idx)));
            var strong = document.createElement('strong');
            strong.textContent = name.slice(idx, idx + query.length);
            frag.appendChild(strong);
            frag.appendChild(document.createTextNode(name.slice(idx + query.length)));
            return frag;
        }

        function selectName(name) {
            input.value = name;
            closeDropdown();
            form.submit();
        }

        function highlightFocused(items) {
            for (var i = 0; i < items.length; i++) items[i].classList.remove('selected');
            var active = items[currentFocus];
            if (!active) return;
            active.classList.add('selected');
            active.scrollIntoView({ block: 'nearest' });
            input.setAttribute('aria-activedescendant', active.id);
        }

        input.addEventListener('input', function () {
            var query = this.value.trim();
            latestQuery = query;
            clearTimeout(debounceTimer);

            if (activeController) {
                activeController.abort();
                activeController = null;
            }

            if (query.length < 1) {
                closeDropdown();
                return;
            }

            renderMessage('Searching…');
            input.setAttribute('aria-expanded', 'true');

            debounceTimer = setTimeout(function () {
                var controller = ('AbortController' in window) ? new AbortController() : null;
                activeController = controller;
                var requestedQuery = query;

                fetch('patient_history.php?ajax_search=1&term=' + encodeURIComponent(query), {
                    signal: controller ? controller.signal : undefined
                })
                    .then(function (response) {
                        if (!response.ok) throw new Error('network');
                        return response.json();
                    })
                    .then(function (data) {
                        // Ignore stale responses if the user kept typing —
                        // prevents an older result from overwriting a newer one.
                        if (requestedQuery !== latestQuery) return;

                        dropdown.innerHTML = '';
                        currentFocus = -1;

                        if (!Array.isArray(data)) {
                            renderMessage((data && data.error) ? data.error : 'Search is temporarily unavailable.');
                            return;
                        }

                        if (data.length === 0) {
                            renderMessage('No patients found.');
                            return;
                        }

                        data.forEach(function (name, i) {
                            var div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            div.id = 'ac-item-' + i;
                            div.setAttribute('role', 'option');
                            div.appendChild(buildHighlightedLabel(name, requestedQuery));
                            div.addEventListener('click', function () { selectName(name); });
                            dropdown.appendChild(div);
                        });

                        dropdown.style.display = 'block';
                        input.setAttribute('aria-expanded', 'true');
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') return;
                        // Deliberately generic — never log patient search
                        // terms or results to the console.
                        console.error('Autocomplete request failed.');
                        if (requestedQuery === latestQuery) {
                            renderMessage('Search is temporarily unavailable.');
                        }
                    });
            }, 280);
        });

        input.addEventListener('keydown', function (e) {
            var items = dropdown.getElementsByClassName('autocomplete-item');

            if (e.key === 'ArrowDown') {
                if (!items.length) return;
                currentFocus = (currentFocus + 1) % items.length;
                highlightFocused(items);
                e.preventDefault();
            } else if (e.key === 'ArrowUp') {
                if (!items.length) return;
                currentFocus = (currentFocus - 1 + items.length) % items.length;
                highlightFocused(items);
                e.preventDefault();
            } else if (e.key === 'Enter') {
                if (currentFocus > -1 && items[currentFocus]) {
                    e.preventDefault();
                    items[currentFocus].click();
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
            }
        });
    }

    function initDirectoryFilter() {
        var filterInput = document.getElementById('directoryFilter');
        var grid = document.getElementById('patientDirectoryGrid');
        if (!filterInput || !grid) return;

        filterInput.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            var items = grid.getElementsByClassName('patient-directory-item');
            for (var i = 0; i < items.length; i++) {
                var nameEl = items[i].querySelector('.name');
                var name = nameEl ? nameEl.textContent.toLowerCase() : '';
                items[i].style.display = (!q || name.indexOf(q) !== -1) ? '' : 'none';
            }
        });
    }

    function initExportButtons() {
        var links = document.querySelectorAll('.export-csv-link');
        links.forEach(function (a) {
            a.addEventListener('click', function () {
                if (a.classList.contains('exporting')) return;
                a.classList.add('exporting');
                var original = a.innerHTML;
                a.setAttribute('aria-busy', 'true');
                a.innerHTML = '<span>⏳</span> Exporting…';
                // The CSV download itself happens via normal browser
                // navigation; this just prevents rapid double-clicks and
                // gives brief visual feedback without reloading the page.
                setTimeout(function () {
                    a.classList.remove('exporting');
                    a.removeAttribute('aria-busy');
                    a.innerHTML = original;
                }, 2000);
            });
        });
    }

    function runAll() {
        run();
        initDirectoryFilter();
        initExportButtons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runAll);
    } else {
        // Defensive: handles the case where this script is injected
        // dynamically after DOMContentLoaded has already fired.
        runAll();
    }
})();
</script>
<?php endif; ?>

<?php if (!$is_embed) include 'includes/footer.php'; ?>