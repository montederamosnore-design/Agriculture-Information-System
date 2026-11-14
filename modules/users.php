<?php
/*
 * modules/users.php
 * User management. Administrator only (role_id 1).
 * Add, edit, delete (with a confirmation page), search and pagination.
 * No JavaScript. Every form has a CSRF token.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_role([1]);   // Staff get "Access denied"

$page_title    = 'User Management';
$page_subtitle = 'Create and manage system accounts and their roles';
$active_page   = 'users';

$me        = current_user();
$error_msg = '';

try {
    $pdo   = (new Database())->connect();
    $roles = $pdo->query("SELECT role_id, role_description FROM user_role ORDER BY role_id")->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    exit('The database could not be reached. Please try again later.');
}
$valid_roles = array_map('intval', array_column($roles, 'role_id'));

// Values shown in the add/edit form
$form = ['user_id' => 0, 'username' => '', 'full_name' => '', 'role_id' => 2];

// ---------------------------------------------------------------
// POST: add, edit or delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Your page expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ----- DELETE -----
        if ($action === 'delete') {
            $user_id = (int) ($_POST['user_id'] ?? 0);

            if ($user_id === $me['user_id']) {
                set_flash('danger', 'You cannot delete your own account.');
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    set_flash('success', 'User deleted successfully.');
                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    set_flash('danger', 'Cannot delete this user because it is linked to other records.');
                }
            }
            redirect('modules/users.php');
        }

        // ----- ADD / EDIT -----
        if ($action === 'add' || $action === 'edit') {
            $user_id   = (int) ($_POST['user_id'] ?? 0);
            $username  = trim($_POST['username'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            $role_id   = (int) ($_POST['role_id'] ?? 0);
            $password  = $_POST['password'] ?? '';

            $form = ['user_id' => $user_id, 'username' => $username, 'full_name' => $full_name, 'role_id' => $role_id];

            // An administrator cannot change their own role (avoids locking yourself out).
            if ($action === 'edit' && $user_id === $me['user_id']) {
                $role_id = $me['role_id'];
                $form['role_id'] = $role_id;
            }

            // Validation
            if ($action === 'add' && !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
                $error_msg = 'Username must be 3-50 characters: letters, numbers and underscore only.';
            } elseif ($full_name === '' || mb_strlen($full_name) > 100) {
                $error_msg = 'Full name is required (maximum 100 characters).';
            } elseif (!in_array($role_id, $valid_roles, true)) {
                $error_msg = 'Please choose a valid role.';
            } elseif ($action === 'add' && strlen($password) < 8) {
                $error_msg = 'Password is required and must be at least 8 characters.';
            } elseif ($action === 'edit' && $password !== '' && strlen($password) < 8) {
                $error_msg = 'A new password must be at least 8 characters.';
            } else {
                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $full_name, $role_id]);
                        set_flash('success', 'User added successfully.');
                    } else {
                        if ($password !== '') {
                            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, role_id = ?, password_hash = ? WHERE user_id = ?");
                            $stmt->execute([$full_name, $role_id, password_hash($password, PASSWORD_DEFAULT), $user_id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, role_id = ? WHERE user_id = ?");
                            $stmt->execute([$full_name, $role_id, $user_id]);
                        }
                        // Keep the session in sync if you edited yourself.
                        if ($user_id === $me['user_id']) {
                            $_SESSION['user']['full_name'] = $full_name;
                        }
                        set_flash('success', 'User updated successfully.');
                    }
                    redirect('modules/users.php');

                } catch (PDOException $ex) {
                    if ($ex->getCode() === '23505') {
                        $error_msg = 'That username is already taken.';
                    } else {
                        error_log($ex->getMessage());
                        $error_msg = 'Database error while saving the user.';
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------
// GET: edit form and delete confirmation
// ---------------------------------------------------------------
$edit_row = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT user_id, username, full_name, role_id FROM users WHERE user_id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $edit_row = $stmt->fetch() ?: null;
    if ($edit_row && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $form = $edit_row;
    }
}
// After a failed edit, keep showing the edit form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit' && $error_msg !== '') {
    $edit_row = $form;
}

$delete_row = null;
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("SELECT user_id, username, full_name FROM users WHERE user_id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    $delete_row = $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------
// Search and pagination
// ---------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$where  = '';
$params = [];
if ($search !== '') {
    $where  = "WHERE u.username ILIKE ? OR u.full_name ILIKE ?";
    $params = ["%$search%", "%$search%"];
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$count_stmt->execute($params);
$total_pages = (int) ceil($count_stmt->fetchColumn() / $limit);

$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.full_name, u.role_id, r.role_description, u.created_at
    FROM users u
    JOIN user_role r ON r.role_id = u.role_id
    $where
    ORDER BY u.user_id
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<?php if ($error_msg !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($error_msg) ?></div>
<?php endif; ?>

<?php if ($delete_row): ?>
    <!-- Delete confirmation (no JavaScript) -->
    <div class="card panel border-danger mb-4">
        <div class="card-header text-danger">Confirm delete</div>
        <div class="card-body">
            <?php if ((int) $delete_row['user_id'] === $me['user_id']): ?>
                <p class="mb-3">You cannot delete your own account.</p>
                <a href="users.php" class="btn btn-secondary">Back</a>
            <?php else: ?>
                <p>Are you sure you want to delete the user
                    <strong><?= e($delete_row['full_name']) ?></strong> (<?= e($delete_row['username']) ?>)?
                    This cannot be undone.</p>
                <form method="POST" action="users.php" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= e($delete_row['user_id']) ?>">
                    <button type="submit" class="btn btn-danger">Yes, delete</button>
                    <a href="users.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Add / Edit form -->
<div class="card panel mb-4">
    <div class="card-header"><?= $edit_row ? 'Edit User #' . e($edit_row['user_id']) : 'Add New User' ?></div>
    <div class="card-body">
        <form method="POST" action="users.php" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $edit_row ? 'edit' : 'add' ?>">
            <?php if ($edit_row): ?>
                <input type="hidden" name="user_id" value="<?= e($edit_row['user_id']) ?>">
            <?php endif; ?>

            <div class="col-md-3">
                <label class="form-label">Username *</label>
                <input type="text" name="username" class="form-control" maxlength="50"
                       value="<?= e($form['username']) ?>" <?= $edit_row ? 'readonly' : 'required' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Full Name *</label>
                <input type="text" name="full_name" class="form-control" maxlength="100"
                       value="<?= e($form['full_name']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Role *</label>
                <?php $own_edit = $edit_row && (int) $edit_row['user_id'] === $me['user_id']; ?>
                <select name="role_id" class="form-select" <?= $own_edit ? 'disabled' : '' ?> required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r['role_id']) ?>" <?= (int) $form['role_id'] === (int) $r['role_id'] ? 'selected' : '' ?>>
                            <?= e($r['role_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($own_edit): ?>
                    <div class="form-text">You cannot change your own role.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= $edit_row ? 'New Password' : 'Password *' ?></label>
                <input type="password" name="password" class="form-control" minlength="8" maxlength="100"
                       autocomplete="new-password" <?= $edit_row ? '' : 'required' ?>>
                <div class="form-text"><?= $edit_row ? 'Leave blank to keep the current password.' : 'At least 8 characters.' ?></div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success"><?= $edit_row ? 'Update User' : 'Save User' ?></button>
                <?php if ($edit_row): ?><a href="users.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Search -->
<div class="card panel mb-3">
    <div class="card-body">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control" placeholder="Search username or full name..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-secondary">Search</button>
            <?php if ($search !== ''): ?><a href="users.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
        </form>
    </div>
</div>

<!-- Users table -->
<div class="card panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= e($u['user_id']) ?></td>
                        <td><?= e($u['username']) ?></td>
                        <td><?= e($u['full_name']) ?></td>
                        <td><span class="badge text-bg-<?= (int) $u['role_id'] === 1 ? 'success' : 'secondary' ?>"><?= e($u['role_description']) ?></span></td>
                        <td><?= e(date('M d, Y', strtotime($u['created_at']))) ?></td>
                        <td class="text-end">
                            <a href="users.php?edit=<?= (int) $u['user_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ((int) $u['user_id'] !== $me['user_id']): ?>
                                <a href="users.php?delete=<?= (int) $u['user_id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($total_pages > 1): ?>
    <nav class="mt-3"><ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="users.php?search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>