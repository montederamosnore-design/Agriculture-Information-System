<?php
/*
 * login.php
 * Shows the login form and checks the username and password.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

// Already logged in? Go straight to the dashboard.
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error    = '';
$username = '';
$flash    = get_flash();   // e.g. "You have been logged out."

// This part runs only when the form is submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error = 'Your page expired. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            try {
                $pdo = (new Database())->connect();

                // Find the user and the role name. The :username placeholder
                // keeps the query safe from SQL injection.
                $sql = 'SELECT u.user_id, u.username, u.password_hash, u.full_name,
                               u.role_id, r.role_description
                        FROM users u
                        JOIN user_role r ON r.role_id = u.role_id
                        WHERE u.username = :username';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['username' => $username]);
                $user = $stmt->fetch();

                // password_verify() compares the typed password with the saved hash.
                if ($user && password_verify($password, $user['password_hash'])) {

                    // New session id after login (protects against session theft).
                    session_regenerate_id(true);

                    // Remember the user. Never store the password or hash here.
                    $_SESSION['user'] = [
                        'user_id'   => (int) $user['user_id'],
                        'username'  => $user['username'],
                        'full_name' => $user['full_name'],
                        'role_id'   => (int) $user['role_id'],
                        'role_name' => $user['role_description'],
                    ];

                    redirect('dashboard.php');
                }

                // Wrong login: short pause makes password guessing slower.
                usleep(500000);
                $error = 'Wrong username or password.';

            } catch (PDOException $ex) {
                // The real error goes to the server log, not to the screen.
                error_log($ex->getMessage());
                $error = 'Something went wrong. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Agriculture Information System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
    <main class="login-wrap">
        <div class="card login-card shadow-lg">

            <div class="login-brand">
                <h1>Agriculture Information System</h1>
                <p class="mb-0">Please log in to continue</p>
            </div>

            <div class="card-body p-4">

                <?php if ($flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?>" role="alert">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= BASE_URL ?>/login.php" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" id="username" name="username"
                               class="form-control form-control-lg"
                               value="<?= e($username) ?>" maxlength="50" required autofocus>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" id="password" name="password"
                               class="form-control form-control-lg"
                               maxlength="100" required>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100">Log In</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>