<?php
/*
 * modules/farmers.php
 * Farmers management: add, view, edit, delete (confirmation page),
 * search, filter by address, sort by column and pagination.
 * No JavaScript. Every form has a CSRF token.
 * Staff and Administrators can add and edit. Only Administrators can delete.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title    = 'Farmers Management';
$page_subtitle = 'Add, view, edit, search and manage registered farmers';
$active_page   = 'farmers';

$me         = current_user();
$can_delete = is_admin();
$error_msg  = '';

try {
    $pdo = (new Database())->connect();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    exit('The database could not be reached. Please try again later.');
}

// Values shown in the add/edit form
$form = [
    'farmer_id'       => 0,
    'first_name'      => '',
    'last_name'       => '',
    'contact_number'  => '',
    'address'         => '',
    'date_registered' => date('Y-m-d'),
];
$editing = false;

// ---------------------------------------------------------------
// POST: add, edit or delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Your page expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ----- DELETE (Administrator only) -----
        if ($action === 'delete') {
            if (!$can_delete) {
                set_flash('danger', 'Only an Administrator can delete records.');
                redirect('modules/farmers.php');
            }
            try {
                $stmt = $pdo->prepare("DELETE FROM farmer WHERE farmer_id = ?");
                $stmt->execute([(int) ($_POST['farmer_id'] ?? 0)]);
                set_flash('success', 'Farmer deleted successfully.');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23503') {   // foreign key violation
                    set_flash('danger', 'Cannot delete this farmer because they still own farm records.');
                } else {
                    error_log($ex->getMessage());
                    set_flash('danger', 'Database error while deleting the farmer.');
                }
            }
            redirect('modules/farmers.php');
        }

        // ----- ADD / EDIT -----
        if ($action === 'add' || $action === 'edit') {
            $farmer_id       = (int) ($_POST['farmer_id'] ?? 0);
            $first_name      = trim($_POST['first_name'] ?? '');
            $last_name       = trim($_POST['last_name'] ?? '');
            $contact_number  = trim($_POST['contact_number'] ?? '');
            $address         = trim($_POST['address'] ?? '');
            $date_registered = trim($_POST['date_registered'] ?? '');
            if ($date_registered === '') {
                $date_registered = date('Y-m-d');
            }

            $form = [
                'farmer_id' => $farmer_id, 'first_name' => $first_name, 'last_name' => $last_name,
                'contact_number' => $contact_number, 'address' => $address, 'date_registered' => $date_registered,
            ];
            $editing = ($action === 'edit');

            $date_obj = DateTime::createFromFormat('Y-m-d', $date_registered);
            $date_ok  = $date_obj && $date_obj->format('Y-m-d') === $date_registered;

            // Validation
            if ($first_name === '' || $last_name === '') {
                $error_msg = 'First name and last name are required.';
            } elseif (mb_strlen($first_name) > 50 || mb_strlen($last_name) > 50) {
                $error_msg = 'First name and last name can have at most 50 characters.';
            } elseif (!preg_match('/^(09|\+639)[0-9]{9}$/', $contact_number)) {
                $error_msg = 'Contact number must look like 09171234567 or +639171234567.';
            } elseif ($address === '' || mb_strlen($address) > 200) {
                $error_msg = 'Address is required (maximum 200 characters).';
            } elseif (!$date_ok) {
                $error_msg = 'Please enter a valid registration date.';
            } elseif ($date_registered > date('Y-m-d')) {
                $error_msg = 'Registration date cannot be in the future.';
            } elseif ($editing && $farmer_id <= 0) {
                $error_msg = 'The farmer to edit was not found.';
            } else {
                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("
                            INSERT INTO farmer (first_name, last_name, contact_number, address, date_registered, created_by)
                            VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$first_name, $last_name, $contact_number, $address, $date_registered, $me['user_id']]);
                        set_flash('success', 'Farmer added successfully.');
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE farmer
                            SET first_name = ?, last_name = ?, contact_number = ?, address = ?, date_registered = ?
                            WHERE farmer_id = ?");
                        $stmt->execute([$first_name, $last_name, $contact_number, $address, $date_registered, $farmer_id]);
                        set_flash('success', 'Farmer updated successfully.');
                    }
                    redirect('modules/farmers.php');

                } catch (PDOException $ex) {
                    if ($ex->getCode() === '23505') {   // unique violation
                        $error_msg = 'A farmer with the same name and contact number already exists.';
                    } else {
                        error_log($ex->getMessage());
                        $error_msg = 'Database error while saving the farmer.';
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------
// GET: edit form, view page and delete confirmation
// ---------------------------------------------------------------
if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT farmer_id, first_name, last_name, contact_number, address, date_registered
                           FROM farmer WHERE farmer_id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $row = $stmt->fetch();
    if ($row) {
        $form    = $row;
        $editing = true;
    } else {
        set_flash('danger', 'Farmer not found.');
        redirect('modules/farmers.php');
    }
}

$view_row   = null;
$view_farms = [];
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM farmer WHERE farmer_id = ?");
    $stmt->execute([(int) $_GET['view']]);
    $view_row = $stmt->fetch() ?: null;
    if ($view_row) {
        $stmt = $pdo->prepare("
            SELECT f.farm_name, f.location, f.area_hectares, i.irrigation_description
            FROM farm f
            LEFT JOIN irrigation_type i ON i.irrigation_id = f.irrigation_id
            WHERE f.farmer_id = ?
            ORDER BY f.farm_name");
        $stmt->execute([$view_row['farmer_id']]);
        $view_farms = $stmt->fetchAll();
    }
}

$delete_row   = null;
$delete_farms = 0;
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("SELECT farmer_id, first_name, last_name FROM farmer WHERE farmer_id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    $delete_row = $stmt->fetch() ?: null;
    if ($delete_row) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM farm WHERE farmer_id = ?");
        $stmt->execute([$delete_row['farmer_id']]);
        $delete_farms = (int) $stmt->fetchColumn();
    }
}

// ---------------------------------------------------------------
// Search, address filter, sorting and pagination
// ---------------------------------------------------------------
$search         = trim($_GET['search'] ?? '');
$address_filter = trim($_GET['address'] ?? '');

$sort_columns = [
    'farmer_id'       => 'ID',
    'first_name'      => 'First Name',
    'last_name'       => 'Last Name',
    'contact_number'  => 'Contact Number',
    'address'         => 'Address',
    'date_registered' => 'Registered',
];
$sort = $_GET['sort'] ?? 'farmer_id';
if (!array_key_exists($sort, $sort_columns)) {
    $sort = 'farmer_id';
}
$dir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = 10;

$conditions = [];
$params     = [];
if ($search !== '') {
    $conditions[] = "(first_name ILIKE ? OR last_name ILIKE ? OR contact_number ILIKE ? OR address ILIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($address_filter !== '') {
    $conditions[] = "address = ?";
    $params[]     = $address_filter;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM farmer $where");
$count_stmt->execute($params);
$total_records = (int) $count_stmt->fetchColumn();
$total_pages   = max(1, (int) ceil($total_records / $limit));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $limit;

// $sort and $dir were checked against a fixed list above, so they are safe here.
$stmt = $pdo->prepare("
    SELECT farmer_id, first_name, last_name, contact_number, address, date_registered
    FROM farmer
    $where
    ORDER BY $sort $dir, farmer_id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$farmers = $stmt->fetchAll();

$address_options = $pdo->query("SELECT DISTINCT address FROM farmer ORDER BY address")->fetchAll(PDO::FETCH_COLUMN);

// Builds a link that keeps the current search, filter and sort.
$base_query = ['search' => $search, 'address' => $address_filter, 'sort' => $sort, 'dir' => $dir, 'page' => $page];
$qs = function (array $override = []) use ($base_query): string {
    $q = array_filter(array_merge($base_query, $override), fn($v) => $v !== '' && $v !== null);
    return 'farmers.php?' . http_build_query($q);
};

// Clickable column header
$th = function (string $col) use ($sort_columns, $sort, $dir, $qs): string {
    $next  = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($sort === $col) {
        $arrow = $dir === 'asc' ? ' &#9650;' : ' &#9660;';
    }
    return '<a href="' . e($qs(['sort' => $col, 'dir' => $next, 'page' => 1])) . '" class="text-decoration-none text-reset">'
         . e($sort_columns[$col]) . $arrow . '</a>';
};

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
            <?php if (!$can_delete): ?>
                <p class="mb-3">Only an Administrator can delete farmers.</p>
                <a href="farmers.php" class="btn btn-secondary">Back</a>
            <?php elseif ($delete_farms > 0): ?>
                <p class="mb-3"><strong><?= e($delete_row['first_name'] . ' ' . $delete_row['last_name']) ?></strong>
                    owns <?= $delete_farms ?> farm record(s), so this farmer cannot be deleted.
                    Delete or reassign the farms first.</p>
                <a href="farmers.php" class="btn btn-secondary">Back</a>
            <?php else: ?>
                <p>Are you sure you want to delete the farmer
                    <strong><?= e($delete_row['first_name'] . ' ' . $delete_row['last_name']) ?></strong>?
                    This cannot be undone.</p>
                <form method="POST" action="farmers.php" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="farmer_id" value="<?= (int) $delete_row['farmer_id'] ?>">
                    <button type="submit" class="btn btn-danger">Yes, delete</button>
                    <a href="farmers.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($view_row): ?>
    <!-- View details -->
    <div class="card panel mb-4">
        <div class="card-header">Farmer #<?= (int) $view_row['farmer_id'] ?> &mdash;
            <?= e($view_row['first_name'] . ' ' . $view_row['last_name']) ?></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="text-muted small">Contact Number</div><?= e($view_row['contact_number']) ?></div>
                <div class="col-md-5"><div class="text-muted small">Address</div><?= e($view_row['address']) ?></div>
                <div class="col-md-2"><div class="text-muted small">Registered</div><?= e(date('M d, Y', strtotime($view_row['date_registered']))) ?></div>
                <div class="col-md-2"><div class="text-muted small">Last updated</div><?= e(date('M d, Y', strtotime($view_row['updated_at']))) ?></div>
            </div>

            <h6>Farms owned</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3">
                    <thead>
                        <tr><th>Farm</th><th>Location</th><th class="text-end">Area</th><th>Irrigation</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$view_farms): ?>
                            <tr><td colspan="4" class="text-muted text-center">This farmer has no farms yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($view_farms as $vf): ?>
                            <tr>
                                <td><?= e($vf['farm_name']) ?></td>
                                <td><?= e($vf['location']) ?></td>
                                <td class="text-end"><?= number_format((float) $vf['area_hectares'], 2) ?> ha</td>
                                <td><?= e($vf['irrigation_description'] ?? 'N/A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <a href="farmers.php" class="btn btn-secondary">Close</a>
            <a href="farmers.php?edit=<?= (int) $view_row['farmer_id'] ?>" class="btn btn-outline-primary">Edit</a>
        </div>
    </div>
<?php endif; ?>

<!-- Add / Edit form -->
<div class="card panel mb-4">
    <div class="card-header"><?= $editing ? 'Edit Farmer #' . (int) $form['farmer_id'] : 'Add New Farmer' ?></div>
    <div class="card-body">
        <form method="POST" action="farmers.php" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="farmer_id" value="<?= (int) $form['farmer_id'] ?>">
            <?php endif; ?>

            <div class="col-md-3">
                <label class="form-label">First Name *</label>
                <input type="text" name="first_name" class="form-control" maxlength="50"
                       value="<?= e($form['first_name']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Last Name *</label>
                <input type="text" name="last_name" class="form-control" maxlength="50"
                       value="<?= e($form['last_name']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Contact Number *</label>
                <input type="text" name="contact_number" class="form-control" maxlength="20"
                       pattern="(09|\+639)[0-9]{9}" placeholder="09171234567"
                       value="<?= e($form['contact_number']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Date Registered *</label>
                <input type="date" name="date_registered" class="form-control" max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($form['date_registered']) ?>" required>
            </div>
            <div class="col-md-9">
                <label class="form-label">Address *</label>
                <input type="text" name="address" class="form-control" maxlength="200"
                       value="<?= e($form['address']) ?>" required>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-success"><?= $editing ? 'Update Farmer' : 'Save Farmer' ?></button>
                <?php if ($editing): ?><a href="farmers.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Search and filter -->
<div class="card panel mb-3">
    <div class="card-body">
        <form method="GET" action="farmers.php" class="row g-2">
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control"
                       placeholder="Search name, contact number or address..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-4">
                <select name="address" class="form-select">
                    <option value="">All addresses</option>
                    <?php foreach ($address_options as $addr): ?>
                        <option value="<?= e($addr) ?>" <?= $address_filter === $addr ? 'selected' : '' ?>><?= e($addr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-secondary">Apply</button>
                <?php if ($search !== '' || $address_filter !== ''): ?>
                    <a href="farmers.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Farmers table -->
<div class="card panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= $th('farmer_id') ?></th>
                    <th><?= $th('first_name') ?></th>
                    <th><?= $th('last_name') ?></th>
                    <th><?= $th('contact_number') ?></th>
                    <th><?= $th('address') ?></th>
                    <th><?= $th('date_registered') ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$farmers): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No farmers found.</td></tr>
                <?php endif; ?>
                <?php foreach ($farmers as $f): ?>
                    <tr>
                        <td><?= e($f['farmer_id']) ?></td>
                        <td><?= e($f['first_name']) ?></td>
                        <td><?= e($f['last_name']) ?></td>
                        <td><?= e($f['contact_number']) ?></td>
                        <td><?= e($f['address']) ?></td>
                        <td><?= e(date('M d, Y', strtotime($f['date_registered']))) ?></td>
                        <td class="text-end">
                            <a href="farmers.php?view=<?= (int) $f['farmer_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <a href="farmers.php?edit=<?= (int) $f['farmer_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($can_delete): ?>
                                <a href="farmers.php?delete=<?= (int) $f['farmer_id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Showing <?= count($farmers) ?> of <?= number_format($total_records) ?> farmer(s)
    </div>
</div>

<?php if ($total_pages > 1): ?>
    <nav class="mt-3"><ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e($qs(['page' => $i])) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>