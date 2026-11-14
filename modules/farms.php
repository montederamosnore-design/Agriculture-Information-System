<?php
/*
 * modules/farms.php
 * Farms management: add, view, edit, delete (confirmation page),
 * search, filter by irrigation type and soil type, sort by column, pagination.
 * No JavaScript. Every form has a CSRF token.
 * Staff and Administrators can add and edit. Only Administrators can delete.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title    = 'Farms Management';
$page_subtitle = 'Add, view, edit, search and manage agricultural lands';
$active_page   = 'farms';

$me         = current_user();
$can_delete = is_admin();
$error_msg  = '';

try {
    $pdo = (new Database())->connect();

    // Dropdown data
    $farmers_list    = $pdo->query("SELECT farmer_id, first_name, last_name FROM farmer ORDER BY last_name, first_name")->fetchAll();
    $irrigation_list = $pdo->query("SELECT irrigation_id, irrigation_description FROM irrigation_type ORDER BY irrigation_id")->fetchAll();
    $soil_list       = $pdo->query("SELECT soil_id, soil_description FROM soil_type ORDER BY soil_id")->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    exit('The database could not be reached. Please try again later.');
}

$valid_farmers    = array_map('intval', array_column($farmers_list, 'farmer_id'));
$valid_irrigation = array_map('intval', array_column($irrigation_list, 'irrigation_id'));
$valid_soil       = array_map('intval', array_column($soil_list, 'soil_id'));

// Values shown in the add/edit form
$form = [
    'farm_id' => 0, 'farmer_id' => 0, 'farm_name' => '', 'location' => '',
    'area_hectares' => '', 'irrigation_id' => 0, 'soil_id' => 0,
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
                redirect('modules/farms.php');
            }
            try {
                $stmt = $pdo->prepare("DELETE FROM farm WHERE farm_id = ?");
                $stmt->execute([(int) ($_POST['farm_id'] ?? 0)]);
                set_flash('success', 'Farm deleted successfully.');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23503') {   // foreign key violation
                    set_flash('danger', 'Cannot delete this farm because it has planting records.');
                } else {
                    error_log($ex->getMessage());
                    set_flash('danger', 'Database error while deleting the farm.');
                }
            }
            redirect('modules/farms.php');
        }

        // ----- ADD / EDIT -----
        if ($action === 'add' || $action === 'edit') {
            $farm_id       = (int) ($_POST['farm_id'] ?? 0);
            $farmer_id     = (int) ($_POST['farmer_id'] ?? 0);
            $farm_name     = trim($_POST['farm_name'] ?? '');
            $location      = trim($_POST['location'] ?? '');
            $area_hectares = trim($_POST['area_hectares'] ?? '');
            $irrigation_id = (int) ($_POST['irrigation_id'] ?? 0);
            $soil_id       = (int) ($_POST['soil_id'] ?? 0);   // 0 = not specified

            $form = [
                'farm_id' => $farm_id, 'farmer_id' => $farmer_id, 'farm_name' => $farm_name,
                'location' => $location, 'area_hectares' => $area_hectares,
                'irrigation_id' => $irrigation_id, 'soil_id' => $soil_id,
            ];
            $editing = ($action === 'edit');

            // Validation
            if ($farm_name === '' || mb_strlen($farm_name) > 100) {
                $error_msg = 'Farm name is required (maximum 100 characters).';
            } elseif (!in_array($farmer_id, $valid_farmers, true)) {
                $error_msg = 'Please choose a valid owner (farmer).';
            } elseif ($location === '' || mb_strlen($location) > 200) {
                $error_msg = 'Location is required (maximum 200 characters).';
            } elseif (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $area_hectares) || (float) $area_hectares <= 0) {
                $error_msg = 'Area must be a number greater than 0, with at most 2 decimal places.';
            } elseif (!in_array($irrigation_id, $valid_irrigation, true)) {
                $error_msg = 'Please choose an irrigation type.';
            } elseif ($soil_id !== 0 && !in_array($soil_id, $valid_soil, true)) {
                $error_msg = 'Please choose a valid soil type.';
            } elseif ($editing && $farm_id <= 0) {
                $error_msg = 'The farm to edit was not found.';
            } else {
                try {
                    $soil_value = $soil_id ?: null;
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("
                            INSERT INTO farm (farmer_id, farm_name, location, area_hectares, irrigation_id, soil_id, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$farmer_id, $farm_name, $location, $area_hectares, $irrigation_id, $soil_value, $me['user_id']]);
                        set_flash('success', 'Farm added successfully.');
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE farm
                            SET farmer_id = ?, farm_name = ?, location = ?, area_hectares = ?, irrigation_id = ?, soil_id = ?
                            WHERE farm_id = ?");
                        $stmt->execute([$farmer_id, $farm_name, $location, $area_hectares, $irrigation_id, $soil_value, $farm_id]);
                        set_flash('success', 'Farm updated successfully.');
                    }
                    redirect('modules/farms.php');

                } catch (PDOException $ex) {
                    if ($ex->getCode() === '23505') {   // unique violation
                        $error_msg = 'This farmer already has a farm with that name.';
                    } else {
                        error_log($ex->getMessage());
                        $error_msg = 'Database error while saving the farm.';
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
    $stmt = $pdo->prepare("SELECT farm_id, farmer_id, farm_name, location, area_hectares, irrigation_id, soil_id
                           FROM farm WHERE farm_id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $row = $stmt->fetch();
    if ($row) {
        $row['soil_id'] = (int) $row['soil_id'];
        $form    = $row;
        $editing = true;
    } else {
        set_flash('danger', 'Farm not found.');
        redirect('modules/farms.php');
    }
}

$view_row       = null;
$view_plantings = [];
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT f.*, fr.first_name, fr.last_name, i.irrigation_description, s.soil_description
        FROM farm f
        JOIN farmer fr ON fr.farmer_id = f.farmer_id
        LEFT JOIN irrigation_type i ON i.irrigation_id = f.irrigation_id
        LEFT JOIN soil_type s ON s.soil_id = f.soil_id
        WHERE f.farm_id = ?");
    $stmt->execute([(int) $_GET['view']]);
    $view_row = $stmt->fetch() ?: null;
    if ($view_row) {
        $stmt = $pdo->prepare("
            SELECT p.planting_date, c.crop_name, p.area_planted, st.status_description
            FROM planting p
            JOIN crop c ON c.crop_id = p.crop_id
            JOIN planting_status st ON st.status_id = p.status_id
            WHERE p.farm_id = ?
            ORDER BY p.planting_date DESC");
        $stmt->execute([$view_row['farm_id']]);
        $view_plantings = $stmt->fetchAll();
    }
}

$delete_row       = null;
$delete_plantings = 0;
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("SELECT farm_id, farm_name FROM farm WHERE farm_id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    $delete_row = $stmt->fetch() ?: null;
    if ($delete_row) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM planting WHERE farm_id = ?");
        $stmt->execute([$delete_row['farm_id']]);
        $delete_plantings = (int) $stmt->fetchColumn();
    }
}

// ---------------------------------------------------------------
// Search, filters, sorting and pagination
// ---------------------------------------------------------------
$search            = trim($_GET['search'] ?? '');
$irrigation_filter = (int) ($_GET['irrigation'] ?? 0);
$soil_filter       = (int) ($_GET['soil'] ?? 0);

// Allowed sort columns: label and the SQL used for ordering (fixed list = safe)
$sort_columns = [
    'farm_id'       => ['label' => 'ID',         'sql' => ['f.farm_id']],
    'farm_name'     => ['label' => 'Farm Name',  'sql' => ['f.farm_name']],
    'owner'         => ['label' => 'Owner',      'sql' => ['fr.last_name', 'fr.first_name']],
    'location'      => ['label' => 'Location',   'sql' => ['f.location']],
    'area_hectares' => ['label' => 'Area',       'sql' => ['f.area_hectares']],
    'irrigation'    => ['label' => 'Irrigation', 'sql' => ['i.irrigation_description']],
    'soil'          => ['label' => 'Soil',       'sql' => ['s.soil_description']],
];
$sort = $_GET['sort'] ?? 'farm_id';
if (!array_key_exists($sort, $sort_columns)) {
    $sort = 'farm_id';
}
$dir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
$order_by = implode(', ', array_map(fn($x) => "$x $dir", $sort_columns[$sort]['sql'])) . ', f.farm_id DESC';

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;

$conditions = [];
$params     = [];
if ($search !== '') {
    $conditions[] = "(f.farm_name ILIKE ? OR f.location ILIKE ? OR fr.first_name ILIKE ? OR fr.last_name ILIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($irrigation_filter > 0) {
    $conditions[] = "f.irrigation_id = ?";
    $params[]     = $irrigation_filter;
}
if ($soil_filter > 0) {
    $conditions[] = "f.soil_id = ?";
    $params[]     = $soil_filter;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$joins = "
    FROM farm f
    JOIN farmer fr ON fr.farmer_id = f.farmer_id
    LEFT JOIN irrigation_type i ON i.irrigation_id = f.irrigation_id
    LEFT JOIN soil_type s ON s.soil_id = f.soil_id";

$count_stmt = $pdo->prepare("SELECT COUNT(*) $joins $where");
$count_stmt->execute($params);
$total_records = (int) $count_stmt->fetchColumn();
$total_pages   = max(1, (int) ceil($total_records / $limit));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT f.farm_id, f.farm_name, f.location, f.area_hectares,
           fr.first_name, fr.last_name,
           i.irrigation_description, s.soil_description
    $joins
    $where
    ORDER BY $order_by
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$farms = $stmt->fetchAll();

// Link builder that keeps the current search, filters and sort
$base_query = [
    'search' => $search, 'irrigation' => $irrigation_filter ?: '', 'soil' => $soil_filter ?: '',
    'sort' => $sort, 'dir' => $dir, 'page' => $page,
];
$qs = function (array $override = []) use ($base_query): string {
    $q = array_filter(array_merge($base_query, $override), fn($v) => $v !== '' && $v !== null);
    return 'farms.php?' . http_build_query($q);
};

// Clickable column header
$th = function (string $col) use ($sort_columns, $sort, $dir, $qs): string {
    $next  = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($sort === $col) {
        $arrow = $dir === 'asc' ? ' &#9650;' : ' &#9660;';
    }
    return '<a href="' . e($qs(['sort' => $col, 'dir' => $next, 'page' => 1])) . '" class="text-decoration-none text-reset">'
         . e($sort_columns[$col]['label']) . $arrow . '</a>';
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
                <p class="mb-3">Only an Administrator can delete farms.</p>
                <a href="farms.php" class="btn btn-secondary">Back</a>
            <?php elseif ($delete_plantings > 0): ?>
                <p class="mb-3"><strong><?= e($delete_row['farm_name']) ?></strong> has
                    <?= $delete_plantings ?> planting record(s), so it cannot be deleted.
                    Delete those plantings first.</p>
                <a href="farms.php" class="btn btn-secondary">Back</a>
            <?php else: ?>
                <p>Are you sure you want to delete the farm
                    <strong><?= e($delete_row['farm_name']) ?></strong>? This cannot be undone.</p>
                <form method="POST" action="farms.php" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="farm_id" value="<?= (int) $delete_row['farm_id'] ?>">
                    <button type="submit" class="btn btn-danger">Yes, delete</button>
                    <a href="farms.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($view_row): ?>
    <!-- View details -->
    <div class="card panel mb-4">
        <div class="card-header">Farm #<?= (int) $view_row['farm_id'] ?> &mdash; <?= e($view_row['farm_name']) ?></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="text-muted small">Owner</div><?= e($view_row['first_name'] . ' ' . $view_row['last_name']) ?></div>
                <div class="col-md-3"><div class="text-muted small">Location</div><?= e($view_row['location']) ?></div>
                <div class="col-md-2"><div class="text-muted small">Area</div><?= number_format((float) $view_row['area_hectares'], 2) ?> ha</div>
                <div class="col-md-2"><div class="text-muted small">Irrigation</div><?= e($view_row['irrigation_description'] ?? 'N/A') ?></div>
                <div class="col-md-2"><div class="text-muted small">Soil</div><?= e($view_row['soil_description'] ?? 'N/A') ?></div>
            </div>

            <h6>Planting records</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3">
                    <thead>
                        <tr><th>Planting Date</th><th>Crop</th><th class="text-end">Area Planted</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$view_plantings): ?>
                            <tr><td colspan="4" class="text-muted text-center">No planting records for this farm yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($view_plantings as $vp): ?>
                            <tr>
                                <td><?= e(date('M d, Y', strtotime($vp['planting_date']))) ?></td>
                                <td><?= e($vp['crop_name']) ?></td>
                                <td class="text-end"><?= number_format((float) $vp['area_planted'], 2) ?> ha</td>
                                <td><?= e($vp['status_description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <a href="farms.php" class="btn btn-secondary">Close</a>
            <a href="farms.php?edit=<?= (int) $view_row['farm_id'] ?>" class="btn btn-outline-primary">Edit</a>
        </div>
    </div>
<?php endif; ?>

<!-- Add / Edit form -->
<div class="card panel mb-4">
    <div class="card-header"><?= $editing ? 'Edit Farm #' . (int) $form['farm_id'] : 'Add New Farm' ?></div>
    <div class="card-body">
        <form method="POST" action="farms.php" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="farm_id" value="<?= (int) $form['farm_id'] ?>">
            <?php endif; ?>

            <div class="col-md-4">
                <label class="form-label">Farm Name *</label>
                <input type="text" name="farm_name" class="form-control" maxlength="100"
                       value="<?= e($form['farm_name']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Owner (Farmer) *</label>
                <select name="farmer_id" class="form-select" required>
                    <option value="">Select Farmer...</option>
                    <?php foreach ($farmers_list as $fr): ?>
                        <option value="<?= (int) $fr['farmer_id'] ?>" <?= (int) $form['farmer_id'] === (int) $fr['farmer_id'] ? 'selected' : '' ?>>
                            <?= e($fr['last_name'] . ', ' . $fr['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Location *</label>
                <input type="text" name="location" class="form-control" maxlength="200"
                       value="<?= e($form['location']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Area (Hectares) *</label>
                <input type="number" step="0.01" min="0.01" max="999999.99" name="area_hectares" class="form-control"
                       value="<?= e($form['area_hectares']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Irrigation Type *</label>
                <select name="irrigation_id" class="form-select" required>
                    <option value="">Select Irrigation...</option>
                    <?php foreach ($irrigation_list as $irr): ?>
                        <option value="<?= (int) $irr['irrigation_id'] ?>" <?= (int) $form['irrigation_id'] === (int) $irr['irrigation_id'] ? 'selected' : '' ?>>
                            <?= e($irr['irrigation_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Soil Type</label>
                <select name="soil_id" class="form-select">
                    <option value="0">Not specified</option>
                    <?php foreach ($soil_list as $sl): ?>
                        <option value="<?= (int) $sl['soil_id'] ?>" <?= (int) $form['soil_id'] === (int) $sl['soil_id'] ? 'selected' : '' ?>>
                            <?= e($sl['soil_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-success"><?= $editing ? 'Update Farm' : 'Save Farm' ?></button>
                <?php if ($editing): ?><a href="farms.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Search and filters -->
<div class="card panel mb-3">
    <div class="card-body">
        <form method="GET" action="farms.php" class="row g-2">
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search farm, location or owner..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="irrigation" class="form-select">
                    <option value="0">All irrigation types</option>
                    <?php foreach ($irrigation_list as $irr): ?>
                        <option value="<?= (int) $irr['irrigation_id'] ?>" <?= $irrigation_filter === (int) $irr['irrigation_id'] ? 'selected' : '' ?>>
                            <?= e($irr['irrigation_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="soil" class="form-select">
                    <option value="0">All soil types</option>
                    <?php foreach ($soil_list as $sl): ?>
                        <option value="<?= (int) $sl['soil_id'] ?>" <?= $soil_filter === (int) $sl['soil_id'] ? 'selected' : '' ?>>
                            <?= e($sl['soil_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-secondary">Apply</button>
                <?php if ($search !== '' || $irrigation_filter > 0 || $soil_filter > 0): ?>
                    <a href="farms.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Farms table -->
<div class="card panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= $th('farm_id') ?></th>
                    <th><?= $th('farm_name') ?></th>
                    <th><?= $th('owner') ?></th>
                    <th><?= $th('location') ?></th>
                    <th><?= $th('area_hectares') ?></th>
                    <th><?= $th('irrigation') ?></th>
                    <th><?= $th('soil') ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$farms): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No farms found.</td></tr>
                <?php endif; ?>
                <?php foreach ($farms as $f): ?>
                    <tr>
                        <td><?= e($f['farm_id']) ?></td>
                        <td><?= e($f['farm_name']) ?></td>
                        <td><?= e($f['first_name'] . ' ' . $f['last_name']) ?></td>
                        <td><?= e($f['location']) ?></td>
                        <td><?= number_format((float) $f['area_hectares'], 2) ?> ha</td>
                        <td><?= e($f['irrigation_description'] ?? 'N/A') ?></td>
                        <td><?= e($f['soil_description'] ?? 'N/A') ?></td>
                        <td class="text-end">
                            <a href="farms.php?view=<?= (int) $f['farm_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <a href="farms.php?edit=<?= (int) $f['farm_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($can_delete): ?>
                                <a href="farms.php?delete=<?= (int) $f['farm_id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Showing <?= count($farms) ?> of <?= number_format($total_records) ?> farm(s)
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