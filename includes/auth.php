<?php
/*
 * includes/auth.php
 * Session, login check, role check, CSRF protection and small helpers.
 * Include this file at the top of EVERY page.
 */

// If you rename the project folder, change this line too.
const BASE_URL = '/agri-system';

// ---------------------------------------------------------------
// 1. Start the session (this is how PHP remembers who is logged in)
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // Own session name, so other projects inside htdocs do not mix with this one.
    session_name('agri_session');
    session_set_cookie_params([
        'lifetime' => 0,        // cookie ends when the browser is closed
        'path'     => '/',
        'httponly' => true,     // the cookie cannot be read by scripts
        'samesite' => 'Lax',    // helps block requests coming from other websites
    ]);
    session_start();
}

// ---------------------------------------------------------------
// 2. Small helpers
// ---------------------------------------------------------------

/** Safely print text inside HTML. Always use this for data from the database or forms. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Go to another page of this project and stop running the current page. */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

// ---------------------------------------------------------------
// 3. Login and role checks
// ---------------------------------------------------------------

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

/** Returns the logged-in user's info (user_id, username, full_name, role_id, role_name) or null. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** Put this at the top of every page that needs a logged-in user. */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/**
 * Put this at the top of pages that only some roles may open.
 * Example: require_role([1]);   // Administrator only
 */
function require_role(array $allowed_role_ids): void
{
    require_login();
    if (!in_array($_SESSION['user']['role_id'], $allowed_role_ids, true)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to open this page.');
    }
}

/** True when the logged-in user is an Administrator (role_id 1). */
function is_admin(): bool
{
    return is_logged_in() && $_SESSION['user']['role_id'] === 1;
}

// ---------------------------------------------------------------
// 4. CSRF protection (stops other websites from submitting your forms)
// ---------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Put <?= csrf_field() ?> inside every <form method="POST">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Returns true only when the token sent by the form matches the one in the session. */
function csrf_check($token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------------------------------------------------------
// 5. Flash messages (a message shown once after a redirect)
// ---------------------------------------------------------------

/** $type is a Bootstrap color: success, danger, warning or info. */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Gets the message and removes it, so it shows only one time. */
function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}