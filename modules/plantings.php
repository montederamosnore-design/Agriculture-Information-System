<?php
/*
 * modules/plantings.php
 * Planting records: add, view, edit, delete (confirmation page),
 * search, filter by status and crop, sort by column, pagination.
 * No JavaScript. Every form has a CSRF token.
 * Staff and Administrators can add and edit. Only Administrators can delete.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title    = 'Plantings Management';
$page_subtitle = 'Schedule and monitor crop planting operations';
$active_page   = 'plantings';

$me         = current_user();
$can_delete = is_admin();
$error_msg  = '';

/** True when $d is a real date written as YYYY-MM-DD (years 2000-2100). */
function valid_date(string $d): bool
{
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d && (int) $o->format('Y') >= 2000 && (int) $o->format('Y') <= 2100;
}

try {
    $pdo           = (new Database())->connect();
    $farms_list    = $pdo->query("SELECT farm_id, farm_name, area_hectares FROM farm ORDER BY farm_name")->fetchAll();
    $crops_list    = $pdo->query("SELECT crop_id, crop_name, growing_period_days FROM crop ORDER BY crop_name")->fetchAll();
    $statuses_list = $pdo->query("SELECT status_id, status_description FROM planting_status ORDER BY status_id")->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    exit('The database could not be reached. Please try again later.');
}

$farm_area  = [];   // farm_id => area in hectares
foreach ($farms_list as $fm) {
    $farm_area[(int) $fm['farm_id']] = (float) $fm['area_hectares'];
}
$crop_days = [];    // crop_id => growing period in days
foreach ($crops_list as $cp) {
    $crop_days[(int) $cp['crop_id']] = (int) $cp['growing_period_days'];
}
$valid_statuses = array_map('intval', array_column($statuses_list, 'status_id'));

// Bootstrap color for each planting status
$status_colors = [
    'Planted' => 'info', 'Growing' => 'success', 'Ready for Harvest' => 'warning',
    'Harvested' => 'primary', 'Failed' => 'danger',
];

// Values shown in the add/edit form
$form = [
    'planting_id' => 0, 'farm_id' => 0, 'crop_id' => 0, 'status_id' => 1,
    'planting_date' => date('Y-m-d'), 'expected_harvest_date' => '',
    'area_planted' => '', 'seed_quantity' => '',
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
                redirect('modules/plantings.php');
            }
            try {
                $stmt = $pdo->prepare("DELETE FROM planting WHERE planting_id = ?");
                $stmt->execute([(int) ($_POST['planting_id'] ?? 0)]);
                set_flash('success', 'Planting record deleted successfully.');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23503') {   // foreign key violation
                    set_flash('danger', 'Cannot delete this planting because it has harvest records.');
                } else {
                    error_log($ex->getMessage());
                    set_flash('danger', 'Database error while deleting the planting record.');
                }
            }
            redirect('modules/plantings.php');
        }

        // ----- ADD / EDIT -----
        if ($action === 'add' || $action === 'edit') {
            $planting_id           = (int) ($_POST['planting_id'] ?? 0);
            $farm_id               = (int) ($_POST['farm_id'] ?? 0);
            $crop_id               = (int) ($_POST['crop_id'] ?? 0);
            $status_id             = (int) ($_POST['status_id'] ?? 0);
            $planting_date         = trim($_POST['planting_date'] ?? '');
            $expected_harvest_date = trim($_POST['expected_harvest_date'] ?? '');
            $area_planted          = trim($_POST['area_planted'] ?? '');
            $seed_quantity         = trim($_POST['seed_quantity'] ?? '');

            $form = [
                'planting_id' => $planting_id, 'farm_id' => $farm_id, 'crop_id' => $crop_id, 'status_id' => $status_id,
                'planting_date' => $planting_date, 'expected_harvest_date' => $expected_harvest_date,
                'area_planted' => $area_planted, 'seed_quantity' => $seed_quantity,
            ];
            $editing = ($action === 'edit');

            // Validation
            if (!isset($farm_area[$farm_id])) {
                $error_msg = 'Please choose a valid farm.';
            } elseif (!isset($crop_days[$crop_id])) {
                $error_msg = 'Please choose a valid crop.';
            } elseif (!in_array($status_id, $valid_statuses, true)) {
                $error_msg = 'Please choose a status.';
            } elseif (!valid_date($planting_date)) {
                $error_msg = 'Please enter a valid planting date.';
            } elseif ($expected_harvest_date !== '' && !valid_date($expected_harvest_date)) {
                $error_msg = 'Please enter a valid expected harvest date.';
            } elseif ($expected_harvest_date !== '' && $expected_harvest_date < $planting_date) {
                $error_msg = 'Expected harvest date cannot be earlier than the planting date.';
            } elseif (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $area_planted) || (float) $area_planted <= 0) {
                $error_msg = 'Area planted must be a number greater than 0, with at most 2 decimal places.';
            } elseif ((float) $area_planted > $farm_area[$farm_id]) {
                $error_msg = 'Area planted (' . number_format((float) $area_planted, 2)
                           . ' ha) cannot be bigger than the farm area (' . number_format($farm_area[$farm_id], 2) . ' ha).';
            } elseif ($seed_quantity !== '' && !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $seed_quantity)) {
                $error_msg = 'Seed quantity must be a number of 0 or more, with at most 2 decimal places.';
            } elseif ($editing && $planting_id <= 0) {
                $error_msg = 'The planting record to edit was not found.';
            } else {
                // An edited planting cannot end up after its own harvests.
                if ($editing) {
                    $stmt = $pdo->prepare("SELECT MIN(harvest_date) FROM harvest WHERE planting_id = ?");
                    $stmt->execute([$planting_id]);
                    $first_harvest = $stmt->fetchColumn();
                    if ($first_harvest && $first_harvest < $planting_date) {
                        $error_msg = 'Planting date cannot be later than its first harvest (' . date('M d, Y', strtotime($first_harvest)) . ').';
                    }
                }
            }

            if ($error_msg === '') {
                // Blank expected date = planting date + the crop's growing period.
                if ($expected_harvest_date === '') {
                    $calc = new DateTime($planting_date);
                    $calc->modify('+' . $crop_days[$crop_id] . ' days');
                    $expected_harvest_date = $calc->format('Y-m-d');
                }
                $seed_value = $seed_quantity !== '' ? $seed_quantity : null;

                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("
                            INSERT INTO planting (farm_id, crop_id, planting_date, expected_harvest_date,
                                                  area_planted, seed_quantity, status_id, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$farm_id, $crop_id, $planting_date, $expected_harvest_date,
                                        $area_planted, $seed_value, $status_id, $me['user_id']]);
                        set_flash('success', 'Planting record added successfully.');
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE planting
                            SET farm_id = ?, crop_id = ?, planting_date = ?, expected_harvest_date = ?,
                                area_planted = ?, seed_quantity = ?, status_id = ?
                            WHERE planting_id = ?");
                        $stmt->execute([$farm_id, $crop_id, $planting_date, $expected_harvest_date,
                                        $area_planted, $seed_value, $status_id, $planting_id]);
                        set_flash('success', 'Planting record updated successfully.');
                    }
                    redirect('modules/plantings.php');

                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    $error_msg = 'Database error while saving the planting record.';
                }
            }
        }
    }
}

// ---------------------------------------------------------------
// GET: edit form, view page and delete confirmation
// ---------------------------------------------------------------
if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT planting_id, farm_id, crop_id, status_id, planting_date, expected_harvest_date,
                                  area_planted, seed_quantity
                           FROM planting WHERE planting_id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $row = $stmt->fetch();
    if ($row) {
        $row['seed_quantity'] = (string) $row['seed_quantity'];
        $form    = $row;
        $editing = true;
    } else {
        set_flash('danger', 'Planting record not found.');
        redirect('modules/plantings.php');
    }
}

$view_row      = null;
$view_harvests = [];
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT p.*, f.farm_name, c.crop_name, s.status_description
        FROM planting p
        JOIN farm f ON f.farm_id = p.farm_id
        JOIN crop c ON c.crop_id = p.crop_id
        JOIN planting_status s ON s.status_id = p.status_id
        WHERE p.planting_id = ?");
    $stmt->execute([(int) $_GET['view']]);
    $view_row = $stmt->fetch() ?: null;
    if ($view_row) {
        $stmt = $pdo->prepare("
            SELECT h.harvest_date, h.quantity_kg, h.selling_price_per_kg, g.grade_description, r.remark_description
            FROM harvest h
            JOIN quality_grade g ON g.grade_id = h.grade_id
            JOIN harvest_remark r ON r.remark_id = h.remark_id
            WHERE h.planting_id = ?
            ORDER BY h.harvest_date DESC");
        $stmt->execute([$view_row['planting_id']]);
        $view_harvests = $stmt->fetchAll();
    }
}

$delete_row      = null;
$delete_harvests = 0;
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("
        SELECT p.planting_id, f.farm_name, c.crop_name
        FROM planting p
        JOIN farm f ON f.farm_id = p.farm_id
        JOIN crop c ON c.crop_id = p.crop_id
        WHERE p.planting_id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    $delete_row = $stmt->fetch() ?: null;
    if ($delete_row) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM harvest WHERE planting_id = ?");
        $stmt->execute([$delete_row['planting_id']]);
        $delete_harvests = (int) $stmt->fetchColumn();
    }
}

// ---------------------------------------------------------------
// Search, filters, sorting and pagination
// ---------------------------------------------------------------
$search        = trim($_GET['search'] ?? '');
$status_filter = (int) ($_GET['status'] ?? 0);
$crop_filter   = (int) ($_GET['crop'] ?? 0);

// Allowed sort columns (fixed list = safe)
$sort_columns = [
    'planting_id'           => ['label' => 'ID',               'sql' => 'p.planting_id'],
    'farm'                  => ['label' => 'Farm',             'sql' => 'f.farm_name'],
    'crop'                  => ['label' => 'Crop',             'sql' => 'c.crop_name'],
    'planting_date'         => ['label' => 'Planted Date',     'sql' => 'p.planting_date'],
    'expected_harvest_date' => ['label' => 'Expected Harvest', 'sql' => 'p.expected_harvest_date'],
    'area_planted'          => ['label' => 'Area',             'sql' => 'p.area_planted'],
    'status'                => ['label' => 'Status',           'sql' => 's.status_id'],
];
$sort = $_GET['sort'] ?? 'planting_date';
if (!array_key_exists($sort, $sort_columns)) {
    $sort = 'planting_date';
}
$dir      = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
$order_by = $sort_columns[$sort]['sql'] . " $dir, p.planting_id DESC";

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;

$conditions = [];
$params     = [];
if ($search !== '') {
    $conditions[] = "(f.farm_name ILIKE ? OR c.crop_name ILIKE ?)";
    array_push($params, "%$search%", "%$search%");
}
if ($status_filter > 0) {
    $conditions[] = "p.status_id = ?";
    $params[]     = $status_filter;
}
if ($crop_filter > 0) {
    $conditions[] = "p.crop_id = ?";
    $params[]     = $crop_filter;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$joins = "
    FROM planting p
    JOIN farm f ON f.farm_id = p.farm_id
    JOIN crop c ON c.crop_id = p.crop_id
    JOIN planting_status s ON s.status_id = p.status_id";

$count_stmt = $pdo->prepare("SELECT COUNT(*) $joins $where");
$count_stmt->execute($params);
$total_records = (int) $count_stmt->fetchColumn();
$total_pages   = max(1, (int) ceil($total_records / $limit));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT p.planting_id, p.planting_date, p.expected_harvest_date, p.area_planted,
           f.farm_name, c.crop_name, s.status_description
    $joins
    $where
    ORDER BY $order_by
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$plantings = $stmt->fetchAll();

// Link builder that keeps the current search, filters and sort
$base_query = [
    'search' => $search, 'status' => $status_filter ?: '', 'crop' => $crop_filter ?: '',
    'sort' => $sort, 'dir' => $dir, 'page' => $page,
];
$qs = function (array $override = []) use ($base_query): string {
    $q = array_filter(array_merge($base_query, $override), fn($v) => $v !== '' && $v !== null);
    return 'plantings.php?' . http_build_query($q);
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
                <p class="mb-3">Only an Administrator can delete planting records.</p>
                <a href="plantings.php" class="btn btn-secondary">Back</a>
            <?php elseif ($delete_harvests > 0): ?>
                <p class="mb-3">Planting #<?= (int) $delete_row['planting_id'] ?>
                    (<?= e($delete_row['farm_name'] . ' - ' . $delete_row['crop_name']) ?>) has
                    <?= $delete_harvests ?> harvest record(s), so it cannot be deleted. Delete those harvests first.</p>
                <a href="plantings.php" class="btn btn-secondary">Back</a>
            <?php else: ?>
                <p>Are you sure you want to delete planting #<?= (int) $delete_row['planting_id'] ?>
                    (<?= e($delete_row['farm_name'] . ' - ' . $delete_row['crop_name']) ?>)? This cannot be undone.</p>
                <form method="POST" action="plantings.php" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="planting_id" value="<?= (int) $delete_row['planting_id'] ?>">
                    <button type="submit" class="btn btn-danger">Yes, delete</button>
                    <a href="plantings.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($view_row): ?>
    <!-- View details -->
    <div class="card panel mb-4">
        <div class="card-header">Planting #<?= (int) $view_row['planting_id'] ?> &mdash;
            <?= e($view_row['farm_name'] . ' / ' . $view_row['crop_name']) ?></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-2"><div class="text-muted small">Planting Date</div><?= e(date('M d, Y', strtotime($view_row['planting_date']))) ?></div>
                <div class="col-md-2"><div class="text-muted small">Expected Harvest</div><?= e(date('M d, Y', strtotime($view_row['expected_harvest_date']))) ?></div>
                <div class="col-md-2"><div class="text-muted small">Area Planted</div><?= number_format((float) $view_row['area_planted'], 2) ?> ha</div>
                <div class="col-md-2"><div class="text-muted small">Seed Quantity</div><?= $view_row['seed_quantity'] !== null ? number_format((float) $view_row['seed_quantity'], 2) . ' kg' : 'N/A' ?></div>
                <div class="col-md-2"><div class="text-muted small">Status</div>
                    <span class="badge text-bg-<?= e($status_colors[$view_row['status_description']] ?? 'secondary') ?>"><?= e($view_row['status_description']) ?></span></div>
            </div>

            <h6>Harvest records</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3">
                    <thead>
                        <tr><th>Date</th><th class="text-end">Quantity</th><th class="text-end">Price/kg</th><th>Grade</th><th>Remarks</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$view_harvests): ?>
                            <tr><td colspan="5" class="text-muted text-center">No harvests recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($view_harvests as $vh): ?>
                            <tr>
                                <td><?= e(date('M d, Y', strtotime($vh['harvest_date']))) ?></td>
                                <td class="text-end"><?= number_format((float) $vh['quantity_kg'], 2) ?> kg</td>
                                <td class="text-end">&#8369;<?= number_format((float) $vh['selling_price_per_kg'], 2) ?></td>
                                <td><?= e($vh['grade_description']) ?></td>
                                <td><?= e($vh['remark_description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <a href="plantings.php" class="btn btn-secondary">Close</a>
            <a href="plantings.php?edit=<?= (int) $view_row['planting_id'] ?>" class="btn btn-outline-primary">Edit</a>
        </div>
    </div>
<?php endif; ?>

<!-- Add / Edit form -->
<div class="card panel mb-4">
    <div class="card-header"><?= $editing ? 'Edit Planting #' . (int) $form['planting_id'] : 'Add New Planting' ?></div>
    <div class="card-body">
        <form method="POST" action="plantings.php" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="planting_id" value="<?= (int) $form['planting_id'] ?>">
            <?php endif; ?>

            <div class="col-md-4">
                <label class="form-label">Farm *</label>
                <select name="farm_id" class="form-select" required>
                    <option value="">Select Farm...</option>
                    <?php foreach ($farms_list as $fm): ?>
                        <option value="<?= (int) $fm['farm_id'] ?>" <?= (int) $form['farm_id'] === (int) $fm['farm_id'] ? 'selected' : '' ?>>
                            <?= e($fm['farm_name']) ?> (<?= number_format((float) $fm['area_hectares'], 2) ?> ha)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Crop *</label>
                <select name="crop_id" class="form-select" required>
                    <option value="">Select Crop...</option>
                    <?php foreach ($crops_list as $cp): ?>
                        <option value="<?= (int) $cp['crop_id'] ?>" <?= (int) $form['crop_id'] === (int) $cp['crop_id'] ? 'selected' : '' ?>>
                            <?= e($cp['crop_name']) ?> (<?= (int) $cp['growing_period_days'] ?> days)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Status *</label>
                <select name="status_id" class="form-select" required>
                    <?php foreach ($statuses_list as $st): ?>
                        <option value="<?= (int) $st['status_id'] ?>" <?= (int) $form['status_id'] === (int) $st['status_id'] ? 'selected' : '' ?>>
                            <?= e($st['status_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Planting Date *</label>
                <input type="date" name="planting_date" class="form-control"
                       value="<?= e($form['planting_date']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Expected Harvest Date</label>
                <input type="date" name="expected_harvest_date" class="form-control"
                       value="<?= e($form['expected_harvest_date']) ?>">
                <div class="form-text">Leave blank to calculate it from the crop's growing period.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Area Planted (ha) *</label>
                <input type="number" step="0.01" min="0.01" max="999999.99" name="area_planted" class="form-control"
                       value="<?= e($form['area_planted']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Seed Quantity (kg)</label>
                <input type="number" step="0.01" min="0" name="seed_quantity" class="form-control"
                       value="<?= e($form['seed_quantity']) ?>">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success"><?= $editing ? 'Update Planting' : 'Save Planting' ?></button>
                <?php if ($editing): ?><a href="plantings.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Search and filters -->
<div class="card panel mb-3">
    <div class="card-body">
        <form method="GET" action="plantings.php" class="row g-2">
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search farm or crop..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="0">All statuses</option>
                    <?php foreach ($statuses_list as $st): ?>
                        <option value="<?= (int) $st['status_id'] ?>" <?= $status_filter === (int) $st['status_id'] ? 'selected' : '' ?>>
                            <?= e($st['status_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="crop" class="form-select">
                    <option value="0">All crops</option>
                    <?php foreach ($crops_list as $cp): ?>
                        <option value="<?= (int) $cp['crop_id'] ?>" <?= $crop_filter === (int) $cp['crop_id'] ? 'selected' : '' ?>>
                            <?= e($cp['crop_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-secondary">Apply</button>
                <?php if ($search !== '' || $status_filter > 0 || $crop_filter > 0): ?>
                    <a href="plantings.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Plantings table -->
<div class="card panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= $th('planting_id') ?></th>
                    <th><?= $th('farm') ?></th>
                    <th><?= $th('crop') ?></th>
                    <th><?= $th('planting_date') ?></th>
                    <th><?= $th('expected_harvest_date') ?></th>
                    <th><?= $th('area_planted') ?></th>
                    <th><?= $th('status') ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$plantings): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No planting records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($plantings as $p): ?>
                    <tr>
                        <td><?= e($p['planting_id']) ?></td>
                        <td><?= e($p['farm_name']) ?></td>
                        <td><?= e($p['crop_name']) ?></td>
                        <td><?= e(date('M d, Y', strtotime($p['planting_date']))) ?></td>
                        <td><?= e(date('M d, Y', strtotime($p['expected_harvest_date']))) ?></td>
                        <td><?= number_format((float) $p['area_planted'], 2) ?> ha</td>
                        <td><span class="badge text-bg-<?= e($status_colors[$p['status_description']] ?? 'secondary') ?>"><?= e($p['status_description']) ?></span></td>
                        <td class="text-end">
                            <a href="plantings.php?view=<?= (int) $p['planting_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <a href="plantings.php?edit=<?= (int) $p['planting_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($can_delete): ?>
                                <a href="plantings.php?delete=<?= (int) $p['planting_id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Showing <?= count($plantings) ?> of <?= number_format($total_records) ?> planting record(s)
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