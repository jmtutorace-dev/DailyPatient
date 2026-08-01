<?php
/**
 * YAKAP GAMOT SYSTEM - Authentication Check
 * Include this at the top of every protected page.
 * login.php sets: user_id, user_name, username, user_role
 * This file normalizes: role, full_name for backward compatibility.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Normalize session keys for consistency
// login.php sets 'user_role', some code may check 'role'
if (isset($_SESSION['user_role']) && !isset($_SESSION['role'])) {
    $_SESSION['role'] = $_SESSION['user_role'];
}
if (isset($_SESSION['role']) && !isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = $_SESSION['role'];
}
if (isset($_SESSION['full_name']) && !isset($_SESSION['user_name'])) {
    $_SESSION['user_name'] = $_SESSION['full_name'];
}
if (isset($_SESSION['user_name']) && !isset($_SESSION['full_name'])) {
    $_SESSION['full_name'] = $_SESSION['user_name'];
}

// Check if user is logged in — redirect to login if not
if (!isset($_SESSION['user_id'])) {
    $current_page = basename($_SERVER['PHP_SELF']);
    if ($current_page !== 'login.php') {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: login.php');
        exit;
    }
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function current_user() {
    if (isset($_SESSION['user_id'])) {
        return [
            'user_id'   => $_SESSION['user_id'],
            'username'  => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? '',
            'role'      => $_SESSION['role'] ?? '',
        ];
    }
    return null;
}

function require_admin() {
    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
}
