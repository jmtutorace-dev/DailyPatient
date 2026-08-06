<?php
/**
 * YAKAP GAMOT SYSTEM - Header
 * Sidebar + topbar admin dashboard shell with session-based flash messages.
 */
$current_page = basename($_SERVER['PHP_SELF']);

function nav_active($page) {
    global $current_page;
    return $current_page === $page ? 'active' : '';
}

function user_initial($name) {
    return strtoupper(substr($name, 0, 1));
}

$auth_user = $_SESSION['user_name'] ?? 'User';
$auth_role = $_SESSION['user_role'] ?? 'viewer';
$show_physicians = ($auth_role === 'admin');

$page_titles = [
    'dashboard.php' => 'Dashboard',
    'index.php'     => 'Daily Log',
'patient-consultation.php' => 'Patient Consultations',
    'consultation-records.php' => 'Consultation Records',
    'consultation.php' => 'Consultation Summary',
    'transmit.php'  => 'Transmit Tracker',
    'physicians.php' => 'Physicians & Rates',
'patient_history.php' => 'Patient History',
];
$page_title_text = $page_titles[$current_page] ?? 'YAKAP GAMOT';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title_text) ?> &mdash; YAKAP GAMOT SYSTEM</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle navigation menu">
    &#9776;
</button>

<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="sidebar-logo">YG</div>
        <div class="sidebar-brand-text">
            <span class="brand-name">YAKAP GAMOT</span>
            <span class="brand-sub">Patient &amp; Meds Tracker</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="<?= nav_active('dashboard.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="4" rx="1"/><rect x="13" y="9" width="8" height="12" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg></span>
            Dashboard
        </a>
<a href="index.php" class="<?= nav_active('index.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="12" y2="17"/><line x1="3" y1="7" x2="21" y2="7"/></svg></span>
            Daily Log
        </a>
<a href="patient-consultation.php" class="<?= nav_active('patient-consultation.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="14" y2="13"/></svg></span>
            Patient Consultations
        </a>
        <a href="consultation-records.php" class="<?= nav_active('consultation-records.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M15.5 12.5a4 4 0 0 1-4 4"/><path d="M6.5 12.5a4 4 0 0 0 4 4"/><path d="M10 2v3"/><path d="M10 5h1.5a2 2 0 0 1 0 4H10"/><path d="M10 9h-1.5a2 2 0 0 0 0 4H10"/></svg></span>
            Consultation Records
        </a>
        <a href="consultation.php" class="<?= nav_active('consultation.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="14" y2="13"/></svg></span>
            Consultation Summary
        </a>
        <a href="transmit.php" class="<?= nav_active('transmit.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 10H3l1-4h16l1 4z"/><path d="M21 14v4H3v-4"/><path d="M12 2L8 6h8l-4-4z"/><line x1="12" y1="14" x2="12" y2="18"/></svg></span>
            Transmit Tracker
        </a>
        <?php if ($show_physicians): ?>
        <a href="physicians.php" class="<?= nav_active('physicians.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33-1.82 8 8 0 0 0-14.46 0A1.65 1.65 0 0 0 5.6 15"/><path d="M2 21a8 8 0 0 1 20 0"/></svg></span>
            Physicians &amp; Rates
        </a>
        <?php endif; ?>
<a href="patient_history_full.php" class="<?= nav_active('patient_history_full.php') ?>">
            <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33-1.82 8 8 0 0 0-14.46 0A1.65 1.65 0 0 0 5.6 15"/><path d="M2 21a8 8 0 0 1 20 0"/></svg></span>
            Patient History
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="user-info">
            <div class="user-avatar"><?= h(user_initial($auth_user)) ?></div>
            <div class="user-details">
                <span class="user-name"><?= h($auth_user) ?></span>
                <span class="badge <?= $auth_role === 'admin' ? 'badge-admin' : 'badge-viewer' ?>" style="width:fit-content;"><?= h(ucfirst($auth_role)) ?></span>
            </div>
        </div>
        <a href="logout.php" class="logout-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>

</aside>

<div class="main-content" id="mainContent">

<header class="topbar">
    <div class="topbar-left">
        <h1 class="page-title"><?= h($page_title_text) ?></h1>
        <span class="page-breadcrumb">YAKAP GAMOT SYSTEM</span>
    </div>
    <div class="topbar-right">
        <span class="today-date"><?= h(date('l, F j, Y')) ?></span>
    </div>
</header>

<?php
$flash = get_flash();
if ($flash): ?>
    <div style="padding:0 1.5rem;padding-top:1rem;">
        <div class="flash-message flash-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div>
    </div>
<?php endif; ?>

<div class="content-area">