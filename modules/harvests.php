<?php
/*
 * modules/harvests.php
 * Harvest records: add, view, edit, delete (confirmation page),
 * search, filter by quality grade and remarks, sort by column, pagination.
 * No JavaScript. Every form has a CSRF token.
 * Staff and Administrators can add and edit. Only Administrators can delete.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title    = 'Harvests Management';
$page_subtitle = 'Record yields, quality grades, selling prices and remarks';
$active_page   = 'harvests';

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
    $pdo = (new Database())->connect();

    $plantings_list = $pdo->query("
        SELECT p.planting_id, p.planting_date, f.farm_name, c.crop_name
        FROM planting p
        JOIN farm f ON f.farm_id = p.farm_id
        JOIN crop c ON c.crop_id = p.crop_id
        ORDER BY p.planting_id DESC
    ")->fetchAll();
    $grades_list  = $pdo->query("SELECT grade_id, grade_description FROM quality_grade ORDER BY grade_id")->fetchAll();
    $remarks_list = $pdo->query("SELECT remark_id, remark_description FROM harvest_remark ORDER BY remark_id")->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    exit('The database could not be reached. Please try again later.');
}

$planting_dates = [];   // planting_id => planting date
foreach ($plantings_list as $pl) {
    $planting_dates[(int) $pl['planting_id']] = $pl['planting_date'];
}
$valid_grades  = array_map('intval', array_column($grades_list, 'grade_id'));
$valid_remarks = array_map('intval', array_column($remarks_list, 'remark_id'));

// Values shown in the add/edit form
$form = [
    'harvest_id' => 0, 'planting_id' => 0, 'harvest_date' => date('Y-m-d'),
    'quantity_kg' => '', 'selling_price_per_kg' => '', 'grade_id' => 0, 'remark_id' => 0,
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
                redirect('modules/harvests.php');
            }
            try {
                $stmt = $pdo->prepare("DELETE FROM harvest WHERE harvest_id = ?");
                $stmt->execute([(int) ($_POST['harvest_id'] ?? 0)]);
                set_flash('success', 'Harvest record deleted successfully.');
            } catch (PDOException $ex) {
                error_log($ex->getMessage());
                set_flash('danger', 'Database error while deleting the harvest record.');
            }
            redirect('modules/harvests.php');
        }

        // ----- ADD / EDIT -----
        if ($action === 'add' || $action === 'edit') {
            $harvest_id           = (int) ($_POST['harvest_id'] ?? 0);
            $planting_id          = (int) ($_POST['planting_id'] ?? 0);
            $harvest_date         = trim($_POST['harvest_date'] ?? '');
            $quantity_kg          = trim($_POST['quantity_kg'] ?? '');
            $selling_price_per_kg = trim($_POST['selling_price_per_kg'] ?? '');
            $grade_id             = (int) ($_POST['grade_id'] ?? 0);
            $remark_id            = (int) ($_POST['remark_id'] ?? 0);

            $form = [
                'harvest_id' => $harvest_id, 'planting_id' => $planting_id, 'harvest_date' => $harvest_date,
                'quantity_kg' => $quantity_kg, 'selling_price_per_kg' => $selling_price_per_kg,
                'grade_id' => $grade_id, 'remark_id' => $remark_id,
            ];
            $editing = ($action === 'edit');

            // Validation
            if (!isset($planting_dates[$planting_id])) {
                $error_msg = 'Please choose a valid planting record.';
            } elseif (!valid_date($harvest_date)) {
                $error_msg = 'Please enter a valid harvest date.';
            } elseif ($harvest_date < $planting_dates[$planting_id]) {
                $error_msg = 'Harvest date cannot be earlier than the planting date ('
                           . date('M d, Y', strtotime($planting_dates[$planting_id])) . ').';
            } elseif ($harvest_date > date('Y-m-d')) {
                $error_msg = 'Harvest date cannot be in the future.';
            } elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $quantity_kg) || (float) $quantity_kg <= 0) {
                $error_msg = 'Quantity must be a number greater than 0, with at most 2 decimal places.';
            } elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $selling_price_per_kg)) {
                $error_msg = 'Selling price must be a number of 0 or more, with at most 2 decimal places.';
            } elseif (!in_array($grade_id, $valid_grades, true)) {
                $error_msg = 'Please choose a quality grade.';
            } elseif (!in_array($remark_id, $valid_remarks, true)) {
                $error_msg = 'Please choose a remark.';
            } elseif ($editing && $harvest_id <= 0) {
                $error_msg = 'The harvest record to edit was not found.';
            } else {
                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("
                            INSERT INTO harvest (planting_id, harvest_date, quantity_kg, selling_price_per_kg,
                                                 grade_id, remark_id, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$planting_id, $harvest_date, $quantity_kg, $selling_price_per_kg,
                                        $grade_id, $remark_id, $me['user_id']]);
                        set_flash('success', 'Harvest recorded successfully.');
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE harvest
                            SET planting_id = ?, harvest_date = ?, quantity_kg = ?, selling_price_per_kg = ?,
                                grade_id = ?, remark_id = ?
                            WHERE harvest_id = ?");
                        $stmt->execute([$planting_id, $harvest_date, $quantity_kg, $selling_price_per_kg,
                                        $grade_id, $remark_id, $harvest_id]);
                        set_flash('success', 'Harvest record updated successfully.');
                    }
                    redirect('modules/harvests.php');

                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    if ($ex->getCode() === 'P0001') {   // the database trigger rejected the date
                        $error_msg = 'Harvest date cannot be earlier than the planting date.';
                    } else {
                        $error_msg = 'Database error while saving the harvest record.';
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
    $stmt = $pdo->prepare("SELECT harvest_id, planting_id, harvest_date, quantity_kg, selling_price_per_kg, grade_id, remark_id
                           FROM harvest WHERE harvest_id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $row = $stmt->fetch();
    if ($row) {
        $form    = $row;
        $editing = true;
    } else {
        set_flash('danger', 'Harvest record not found.');
        redirect('modules/harvests.php');
    }
}

$view_row = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT h.*, f.farm_name, c.crop_name, p.planting_date, g.grade_description, r.remark_description
        FROM harvest h
        JOIN planting p ON p.planting_id = h.planting_id
        JOIN farm f ON f.farm_id = p.farm_id
        JOIN crop c ON c.crop_id = p.crop_id
        JOIN quality_grade g ON g.grade_id = h.grade_id
        JOIN harvest_remark r ON r.remark_id = h.remark_id
        WHERE h.harvest_id = ?");
    $stmt->execute([(int) $_GET['view']]);
    $view_row = $stmt->fetch() ?: null;
}

$delete_row = null;
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("
        SELECT h.harvest_id, h.harvest_date, f.farm_name, c.crop_name
        FROM harvest h
        JOIN planting p ON p.planting_id = h.planting_id
        JOIN farm f ON f.farm_id = p.farm_id
        JOIN crop c ON c.crop_id = p.crop_id
        WHERE h.harvest_id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    $delete_row = $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------
// Search, filters, sorting and pagination
// ---------------------------------------------------------------
$search       = trim($_GET['search'] ?? '');
$grade_filter = (int) ($_GET['grade'] ?? 0);
$remark_filter = (int) ($_GET['remark'] ?? 0);

// Allowed sort columns (fixed list = safe)
$sort_columns = [
    'harvest_id'           => ['label' => 'ID',          'sql' => ['h.harvest_id']],
    'harvest_date'         => ['label' => 'Date',        'sql' => ['h.harvest_date']],
    'farm_crop'            => ['label' => 'Farm & Crop', 'sql' => ['f.farm_name', 'c.crop_name']],
    'quantity_kg'          => ['label' => 'Quantity',    'sql' => ['h.quantity_kg']],
    'selling_price_per_kg' => ['label' => 'Price/kg',    'sql' => ['h.selling_price_per_kg']],
    'total_value'          => ['label' => 'Total Value', 'sql' => ['(h.quantity_kg * h.selling_price_per_kg)']],
    'grade'                => ['label' => 'Grade',       'sql' => ['g.grade_id']],
    'remark'               => ['label' => 'Remarks',     'sql' => ['r.remark_id']],
];
$sort = $_GET['sort'] ?? 'harvest_date';
if (!array_key_exists($sort, $sort_columns)) {
    $sort = 'harvest_date';
}
$dir      = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
$order_by = implode(', ', array_map(fn($x) => "$x $dir", $sort_columns[$sort]['sql'])) . ', h.harvest_id DESC';

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;

$conditions = [];
$params     = [];
if ($search !== '') {
    $conditions[] = "(f.farm_name ILIKE ? OR c.crop_name ILIKE ?)";
    array_push($params, "%$search%", "%$search%");
}
if ($grade_filter > 0) {
    $conditions[] = "h.grade_id = ?";
    $params[]     = $grade_filter;
}
if ($remark_filter > 0) {
    $conditions[] = "h.remark_id = ?";
    $params[]     = $remark_filter;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$joins = "
    FROM harvest h
    JOIN planting p ON p.planting_id = h.planting_id
    JOIN farm f ON f.farm_id = p.farm_id
    JOIN crop c ON c.crop_id = p.crop_id
    JOIN quality_grade g ON g.grade_id = h.grade_id
    JOIN harvest_remark r ON r.remark_id = h.remark_id";

// Record count plus totals for the filtered records
$count_stmt = $pdo->prepare("
    SELECT COUNT(*), COALESCE(SUM(h.quantity_kg), 0), COALESCE(SUM(h.quantity_kg * h.selling_price_per_kg), 0)
    $joins $where");
$count_stmt->execute($params);
[$total_records, $sum_kg, $sum_value] = $count_stmt->fetch(PDO::FETCH_NUM);
$total_records = (int) $total_records;
$total_pages   = max(1, (int) ceil($total_records / $limit));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT h.harvest_id, h.harvest_date, h.quantity_kg, h.selling_price_per_kg,
           f.farm_name, c.crop_name, g.grade_description, r.remark_description
    $joins
    $where
    ORDER BY $order_by
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$harvests = $stmt->fetchAll();

// Link builder that keeps the current search, filters and sort
$base_query = [
    'search' => $search, 'grade' => $grade_filter ?: '', 'remark' => $remark_filter ?: '',
    'sort' => $sort, 'dir' => $dir, 'page' => $page,
];
$qs = function (array $override = []) use ($base_query): string {
    $q = array_filter(array_merge($base_query, $override), fn($v) => $v !== '' && $v !== null);
    return 'harvests.php?' . http_build_query($q);
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
                <p class="mb-3">Only an Administrator can delete harvest records.</p>
                <a href="harvests.php" class="btn btn-secondary">Back</a>
            <?php else: ?>
                <p>Are you sure you want to delete harvest #<?= (int) $delete_row['harvest_id'] ?>
                    (<?= e($delete_row['farm_name'] . ' - ' . $delete_row['crop_name'] . ', ' . date('M d, Y', strtotime($delete_row['harvest_date']))) ?>)?
                    This cannot be undone.</p>
                <form method="POST" action="harvests.php" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="harvest_id" value="<?= (int) $delete_row['harvest_id'] ?>">
                    <button type="submit" class="btn btn-danger">Yes, delete</button>
                    <a href="harvests.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($view_row): ?>
    <!-- View details -->
    <div class="card panel mb-4">
        <div class="card-header">Harvest #<?= (int) $view_row['harvest_id'] ?> &mdash;
            <?= e($view_row['farm_name'] . ' / ' . $view_row['crop_name']) ?></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-2"><div class="text-muted small">Planted On</div><?= e(date('M d, Y', strtotime($view_row['planting_date']))) ?></div>
                <div class="col-md-2"><div class="text-muted small">Harvest Date</div><?= e(date('M d, Y', strtotime($view_row['harvest_date']))) ?></div>
                <div class="col-md-2"><div class="text-muted small">Quantity</div><?= number_format((float) $view_row['quantity_kg'], 2) ?> kg</div>
                <div class="col-md-2"><div class="text-muted small">Price per kg</div>&#8369;<?= number_format((float) $view_row['selling_price_per_kg'], 2) ?></div>
                <div class="col-md-2"><div class="text-muted small">Total Value</div>
                    <strong>&#8369;<?= number_format((float) $view_row['quantity_kg'] * (float) $view_row['selling_price_per_kg'], 2) ?></strong></div>
                <div class="col-md-2"><div class="text-muted small">Grade / Remarks</div>
                    <?= e($view_row['grade_description']) ?> / <?= e($view_row['remark_description']) ?></div>
            </div>
            <a href="harvests.php" class="btn btn-secondary">Close</a>
            <a href="harvests.php?edit=<?= (int) $view_row['harvest_id'] ?>" class="btn btn-outline-primary">Edit</a>
        </div>
    </div>
<?php endif; ?>

<!-- Add / Edit form -->
<div class="card panel mb-4">
    <div class="card-header"><?= $editing ? 'Edit Harvest #' . (int) $form['harvest_id'] : 'Record New Harvest' ?></div>
    <div class="card-body">
        <form method="POST" action="harvests.php" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="harvest_id" value="<?= (int) $form['harvest_id'] ?>">
            <?php endif; ?>

            <div class="col-md-6">
                <label class="form-label">Planting Record (Farm &amp; Crop) *</label>
                <select name="planting_id" class="form-select" required>
                    <option value="">Select Planting Record...</option>
                    <?php foreach ($plantings_list as $pl): ?>
                        <option value="<?= (int) $pl['planting_id'] ?>" <?= (int) $form['planting_id'] === (int) $pl['planting_id'] ? 'selected' : '' ?>>
                            #<?= (int) $pl['planting_id'] ?> - <?= e($pl['farm_name'] . ' (' . $pl['crop_name'] . ', planted ' . $pl['planting_date'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Harvest Date *</label>
                <input type="date" name="harvest_date" class="form-control" max="<?= e(date('Y-m-d')) ?>"
                       value="<?= e($form['harvest_date']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Quantity (kg) *</label>
                <input type="number" step="0.01" min="0.01" name="quantity_kg" class="form-control"
                       value="<?= e($form['quantity_kg']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Selling Price / kg (&#8369;) *</label>
                <input type="number" step="0.01" min="0" name="selling_price_per_kg" class="form-control"
                       value="<?= e($form['selling_price_per_kg']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Quality Grade *</label>
                <select name="grade_id" class="form-select" required>
                    <option value="">Select Grade...</option>
                    <?php foreach ($grades_list as $gr): ?>
                        <option value="<?= (int) $gr['grade_id'] ?>" <?= (int) $form['grade_id'] === (int) $gr['grade_id'] ? 'selected' : '' ?>>
                            <?= e($gr['grade_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Remarks *</label>
                <select name="remark_id" class="form-select" required>
                    <option value="">Select Remark...</option>
                    <?php foreach ($remarks_list as $rm): ?>
                        <option value="<?= (int) $rm['remark_id'] ?>" <?= (int) $form['remark_id'] === (int) $rm['remark_id'] ? 'selected' : '' ?>>
                            <?= e($rm['remark_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success"><?= $editing ? 'Update Harvest' : 'Save Harvest' ?></button>
                <?php if ($editing): ?><a href="harvests.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Search and filters -->
<div class="card panel mb-3">
    <div class="card-body">
        <form method="GET" action="harvests.php" class="row g-2">
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search farm or crop..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="grade" class="form-select">
                    <option value="0">All quality grades</option>
                    <?php foreach ($grades_list as $gr): ?>
                        <option value="<?= (int) $gr['grade_id'] ?>" <?= $grade_filter === (int) $gr['grade_id'] ? 'selected' : '' ?>>
                            <?= e($gr['grade_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="remark" class="form-select">
                    <option value="0">All remarks</option>
                    <?php foreach ($remarks_list as $rm): ?>
                        <option value="<?= (int) $rm['remark_id'] ?>" <?= $remark_filter === (int) $rm['remark_id'] ? 'selected' : '' ?>>
                            <?= e($rm['remark_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-secondary">Apply</button>
                <?php if ($search !== '' || $grade_filter > 0 || $remark_filter > 0): ?>
                    <a href="harvests.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Harvests table -->
<div class="card panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= $th('harvest_id') ?></th>
                    <th><?= $th('harvest_date') ?></th>
                    <th><?= $th('farm_crop') ?></th>
                    <th class="text-end"><?= $th('quantity_kg') ?></th>
                    <th class="text-end"><?= $th('selling_price_per_kg') ?></th>
                    <th class="text-end"><?= $th('total_value') ?></th>
                    <th><?= $th('grade') ?></th>
                    <th><?= $th('remark') ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$harvests): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No harvest records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($harvests as $h):
                    $val = (float) $h['quantity_kg'] * (float) $h['selling_price_per_kg'];
                ?>
                    <tr>
                        <td><?= e($h['harvest_id']) ?></td>
                        <td><?= e(date('M d, Y', strtotime($h['harvest_date']))) ?></td>
                        <td><?= e($h['farm_name']) ?> &mdash; <strong><?= e($h['crop_name']) ?></strong></td>
                        <td class="text-end"><?= number_format((float) $h['quantity_kg'], 2) ?> kg</td>
                        <td class="text-end">&#8369;<?= number_format((float) $h['selling_price_per_kg'], 2) ?></td>
                        <td class="text-end fw-bold">&#8369;<?= number_format($val, 2) ?></td>
                        <td><?= e($h['grade_description']) ?></td>
                        <td><?= e($h['remark_description']) ?></td>
                        <td class="text-end">
                            <a href="harvests.php?view=<?= (int) $h['harvest_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <a href="harvests.php?edit=<?= (int) $h['harvest_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($can_delete): ?>
                                <a href="harvests.php?delete=<?= (int) $h['harvest_id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small d-flex flex-wrap justify-content-between">
        <span>Showing <?= count($harvests) ?> of <?= number_format($total_records) ?> harvest record(s)</span>
        <span>Total: <?= number_format((float) $sum_kg, 2) ?> kg &middot; &#8369;<?= number_format((float) $sum_value, 2) ?></span>
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