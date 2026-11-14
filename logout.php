<?php
/*
 * logout.php
 * Ends the session. It only works from the Logout button (a POST form
 * with a CSRF token), so other websites cannot log you out by accident.
 */
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf_token'] ?? null)) {

    // 1. Clear all session data
    $_SESSION = [];

    // 2. Delete the session cookie in the browser
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    // 3. Destroy the session on the server
    session_destroy();

    // 4. Start a fresh session just to show the "logged out" message
    session_start();
    set_flash('success', 'You have been logged out.');
}

redirect('login.php');