<?php
/*
 * modules/crops.php
 * Crops management: add, view, edit, delete (confirmation page),
 * search, filter by category and season, sort by column, pagination.
 * No JavaScript. Every form has a CSRF token.
 * Staff and Administrators can add and edit. Only Administrators can delete.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title    = 'Crops Management';
$page_subtitle = 'Manage crop varieties, categories, seasons and growing periods';
$active_page   = 'crops';

$me         = current_user();
$can_delete = is_admin();
$error_msg  = '';

try {
    $pdo        = (new Database())->connect();
    $categories = $pdo->query("SELECT category_id, category_description FROM crop_category ORDER BY category_id")->fetchAll();
    $seasons    = $pdo->query("SELECT season_id, season_description FROM season ORDER BY season_id")->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    exit('The database could not be reached. Please try again later.');
}

$valid_categories = array_map('intval', array_column($categories, 'category_id'));
$valid_seasons    = array_map('intval', array_column($seasons, 'season_id'));

// Values shown in the add/edit form
$form = [
    'crop_id' => 0, 'crop_name' => '', 'variety' => '',
    'category_id' => 0, 'season_id' => 0, 'growing_period_days' => '',
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
                redirect('modules/crops.php');
            }
            try {
                $stmt = $pdo->prepare("DELETE FROM crop WHERE crop_id = ?");
                $stmt->execute([(int) ($_POST['crop_id'] ?? 0)]);
                set_flash('success', 'Crop deleted successfully.');
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23503') {   // foreign key violation
                    set_flash('danger', 'Cannot delete this crop because it is used in planting records.');
                } else {
                    error_log($ex->getMessage());
                    set_flash('danger', 'Database error while deleting the crop.');
                }
            }
            redirect('modules/crops.php');
        }

        // ----- ADD / EDIT -----
        if ($action === 'add' || $action === 'edit') {
            $crop_id     = (int) ($_POST['crop_id'] ?? 0);
            $crop_name   = trim($_POST['crop_name'] ?? '');
            $variety     = trim($_POST['variety'] ?? '');
            $category_id = (int) ($_POST['category_id'] ?? 0);
            $season_id   = (int) ($_POST['season_id'] ?? 0);   // 0 = not specified
            $days        = trim($_POST['growing_period_days'] ?? '');

            $form = [
                'crop_id' => $crop_id, 'crop_name' => $crop_name, 'variety' => $variety,
                'category_id' => $category_id, 'season_id' => $season_id, 'growing_period_days' => $days,
            ];
            $editing = ($action === 'edit');

            // Validation
            if ($crop_name === '' || mb_strlen($crop_name) > 100) {
                $error_msg = 'Crop name is required (maximum 100 characters).';
            } elseif (mb_strlen($variety) > 100) {
                $error_msg = 'Variety can have at most 100 characters.';
            } elseif (!in_array($category_id, $valid_categories, true)) {
                $error_msg = 'Please choose a category.';
            } elseif ($season_id !== 0 && !in_array($season_id, $valid_seasons, true)) {
                $error_msg = 'Please choose a valid season.';
            } elseif (!ctype_digit($days) || (int) $days < 1 || (int) $days > 3650) {
                $error_msg = 'Growing period must be a whole number of days from 1 to 3650.';
            } elseif ($editing && $crop_id <= 0) {
                $error_msg = 'The crop to edit was not found.';
            } else {
                try {
                    $variety_value = $variety !== '' ? $variety : null;
                    $season_value  = $season_id ?: null;
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("
                            INSERT INTO crop (crop_name, variety, category_id, season_id, growing_period_days, created_by)
                            VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$crop_name, $variety_value, $category_id, $season_value, (int) $days, $me['user_id']]);
                        set_flash('success', 'Crop added successfully.');
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE crop
                            SET crop_name = ?, variety = ?, category_id = ?, season_id = ?, growing_period_days = ?
                            WHERE crop_id = ?");
                        $stmt->execute([$crop_name, $variety_value, $category_id, $season_value, (int) $days, $crop_id]);
                        set_flash('success', 'Crop updated successfully.');
                    }
                    redirect('modules/crops.php');

                } catch (PDOException $ex) {
                    if ($ex->getCode() === '23505') {   // unique violation
                        $error_msg = 'A crop with that name already exists.';
                    } else {
                        error_log($ex->getMessage());
                        $error_msg = 'Database error while saving the crop.';
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
    $stmt = $pdo->prepare("SELECT crop_id, crop_name, variety, category_id, season_id, growing_period_days
                           FROM crop WHERE crop_id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $row = $stmt->fetch();
    if ($row) {
        $row['variety']   = (string) $row['variety'];
        $row['season_id'] = (int) $row['season_id'];
        $form    = $row;
        $editing = true;
    } else {
        set_flash('danger', 'Crop not found.');
        redirect('modules/crops.php');
    }
}

$view_row       = null;
$view_plantings = [];
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT c.*, cat.category_description, s.season_description
        FROM crop c
        LEFT JOIN crop_category cat ON cat.category_id = c.category_id
        LEFT JOIN season s ON s.season_id = c.season_id
        WHERE c.crop_id = ?");
    $stmt->execute([(int) $_GET['view']]);
    $view_row = $stmt->fetch() ?: null;
    if ($view_row) {
        $stmt = $pdo->prepare("
            SELECT f.farm_name, p.planting_date, st.status_description
            FROM planting p
            JOIN farm f ON f.farm_id = p.farm_id
            JOIN planting_status st ON st.status_id = p.status_id
            WHERE p.crop_id = ?
            ORDER BY p.planting_date DESC
            LIMIT 20");
        $stmt->execute([$view_row['crop_id']]);
        $view_plantings = $stmt->fetchAll();
    }
}

$delete_row       = null;
$delete_plantings = 0;
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("SELECT crop_id, crop_name FROM crop WHERE crop_id = ?");
    $stmt->execute([(int) $_GET['delete']]);
    $delete_row = $stmt->fetch() ?: null;
    if ($delete_row) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM planting WHERE crop_id = ?");
        $stmt->execute([$delete_row['crop_id']]);
        $delete_plantings = (int) $stmt->fetchColumn();
    }
}

// ---------------------------------------------------------------
// Search, filters, sorting and pagination
// ---------------------------------------------------------------
$search          = trim($_GET['search'] ?? '');
$category_filter = (int) ($_GET['category'] ?? 0);
$season_filter   = (int) ($_GET['season'] ?? 0);

// Allowed sort columns (fixed list = safe)
$sort_columns = [
    'crop_id'             => ['label' => 'ID',            'sql' => 'c.crop_id'],
    'crop_name'           => ['label' => 'Crop Name',     'sql' => 'c.crop_name'],
    'variety'             => ['label' => 'Variety',       'sql' => 'c.variety'],
    'category'            => ['label' => 'Category',      'sql' => 'cat.category_description'],
    'season'              => ['label' => 'Season',        'sql' => 's.season_description'],
    'growing_period_days' => ['label' => 'Growing Period', 'sql' => 'c.growing_period_days'],
];
$sort = $_GET['sort'] ?? 'crop_id';
if (!array_key_exists($sort, $sort_columns)) {
    $sort = 'crop_id';
}
$dir      = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
$order_by = $sort_columns[$sort]['sql'] . " $dir, c.crop_id DESC";

$page  = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;

$conditions = [];
$params     = [];
if ($search !== '') {
    $conditions[] = "(c.crop_name ILIKE ? OR c.variety ILIKE ?)";
    array_push($params, "%$search%", "%$search%");
}
if ($category_filter > 0) {
    $conditions[] = "c.category_id = ?";
    $params[]     = $category_filter;
}
if ($season_filter > 0) {
    $conditions[] = "c.season_id = ?";
    $params[]     = $season_filter;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$joins = "
    FROM crop c
    LEFT JOIN crop_category cat ON cat.category_id = c.category_id
    LEFT JOIN season s ON s.season_id = c.season_id";

$count_stmt = $pdo->prepare("SELECT COUNT(*) $joins $where");
$count_stmt->execute($params);
$total_records = (int) $count_stmt->fetchColumn();
$total_pages   = max(1, (int) ceil($total_records / $limit));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT c.crop_id, c.crop_name, c.variety, c.growing_period_days,
           cat.category_description, s.season_description
    $joins
    $where
    ORDER BY $order_by
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$crops = $stmt->fetchAll();

// Link builder that keeps the current search, filters and sort
$base_query = [
    'search' => $search, 'category' => $category_filter ?: '', 'season' => $season_filter ?: '',
    'sort' => $sort, 'dir' => $dir, 'page' => $page,
];
$qs = function (array $override = []) use ($base_query): string {
    $q = array_filter(array_merge($base_query, $override), fn($v) => $v !== '' && $v !== null);
    return 'crops.php?' . http_build_query($q);
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
                <p class="mb-3">Only an Administrator can delete crops.</p>
                <a href="crops.php" class="btn btn-secondary">Back</a>
            <?php elseif ($delete_plantings > 0): ?>
                <p class="mb-3"><strong><?= e($delete_row['crop_name']) ?></strong> is used in
                    <?= $delete_plantings ?> planting record(s), so it cannot be deleted.</p>
                <a href="crops.php" class="btn btn-secondary">Back</a>
            <?php else: ?>
                <p>Are you sure you want to delete the crop
                    <strong><?= e($delete_row['crop_name']) ?></strong>? This cannot be undone.</p>
                <form method="POST" action="crops.php" class="d-flex gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="crop_id" value="<?= (int) $delete_row['crop_id'] ?>">
                    <button type="submit" class="btn btn-danger">Yes, delete</button>
                    <a href="crops.php" class="btn btn-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($view_row): ?>
    <!-- View details -->
    <div class="card panel mb-4">
        <div class="card-header">Crop #<?= (int) $view_row['crop_id'] ?> &mdash; <?= e($view_row['crop_name']) ?></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="text-muted small">Variety</div><?= e($view_row['variety'] ?? 'N/A') ?></div>
                <div class="col-md-3"><div class="text-muted small">Category</div><?= e($view_row['category_description'] ?? 'N/A') ?></div>
                <div class="col-md-3"><div class="text-muted small">Season</div><?= e($view_row['season_description'] ?? 'N/A') ?></div>
                <div class="col-md-3"><div class="text-muted small">Growing Period</div><?= (int) $view_row['growing_period_days'] ?> days</div>
            </div>

            <h6>Planting records (latest 20)</h6>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3">
                    <thead>
                        <tr><th>Farm</th><th>Planting Date</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$view_plantings): ?>
                            <tr><td colspan="3" class="text-muted text-center">This crop has not been planted yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($view_plantings as $vp): ?>
                            <tr>
                                <td><?= e($vp['farm_name']) ?></td>
                                <td><?= e(date('M d, Y', strtotime($vp['planting_date']))) ?></td>
                                <td><?= e($vp['status_description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <a href="crops.php" class="btn btn-secondary">Close</a>
            <a href="crops.php?edit=<?= (int) $view_row['crop_id'] ?>" class="btn btn-outline-primary">Edit</a>
        </div>
    </div>
<?php endif; ?>

<!-- Add / Edit form -->
<div class="card panel mb-4">
    <div class="card-header"><?= $editing ? 'Edit Crop #' . (int) $form['crop_id'] : 'Add New Crop' ?></div>
    <div class="card-body">
        <form method="POST" action="crops.php" class="row g-3" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="crop_id" value="<?= (int) $form['crop_id'] ?>">
            <?php endif; ?>

            <div class="col-md-3">
                <label class="form-label">Crop Name *</label>
                <input type="text" name="crop_name" class="form-control" maxlength="100"
                       value="<?= e($form['crop_name']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Variety</label>
                <input type="text" name="variety" class="form-control" maxlength="100"
                       value="<?= e($form['variety']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Category *</label>
                <select name="category_id" class="form-select" required>
                    <option value="">Select...</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['category_id'] ?>" <?= (int) $form['category_id'] === (int) $cat['category_id'] ? 'selected' : '' ?>>
                            <?= e($cat['category_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Season</label>
                <select name="season_id" class="form-select">
                    <option value="0">Not specified</option>
                    <?php foreach ($seasons as $se): ?>
                        <option value="<?= (int) $se['season_id'] ?>" <?= (int) $form['season_id'] === (int) $se['season_id'] ? 'selected' : '' ?>>
                            <?= e($se['season_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Growing Period (days) *</label>
                <input type="number" min="1" max="3650" step="1" name="growing_period_days" class="form-control"
                       value="<?= e($form['growing_period_days']) ?>" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-success"><?= $editing ? 'Update Crop' : 'Save Crop' ?></button>
                <?php if ($editing): ?><a href="crops.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Search and filters -->
<div class="card panel mb-3">
    <div class="card-body">
        <form method="GET" action="crops.php" class="row g-2">
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <input type="hidden" name="dir" value="<?= e($dir) ?>">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search crop name or variety..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="category" class="form-select">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['category_id'] ?>" <?= $category_filter === (int) $cat['category_id'] ? 'selected' : '' ?>>
                            <?= e($cat['category_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="season" class="form-select">
                    <option value="0">All seasons</option>
                    <?php foreach ($seasons as $se): ?>
                        <option value="<?= (int) $se['season_id'] ?>" <?= $season_filter === (int) $se['season_id'] ? 'selected' : '' ?>>
                            <?= e($se['season_description']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-secondary">Apply</button>
                <?php if ($search !== '' || $category_filter > 0 || $season_filter > 0): ?>
                    <a href="crops.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Crops table -->
<div class="card panel">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= $th('crop_id') ?></th>
                    <th><?= $th('crop_name') ?></th>
                    <th><?= $th('variety') ?></th>
                    <th><?= $th('category') ?></th>
                    <th><?= $th('season') ?></th>
                    <th><?= $th('growing_period_days') ?></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$crops): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No crops found.</td></tr>
                <?php endif; ?>
                <?php foreach ($crops as $c): ?>
                    <tr>
                        <td><?= e($c['crop_id']) ?></td>
                        <td><?= e($c['crop_name']) ?></td>
                        <td><?= e($c['variety'] ?? 'N/A') ?></td>
                        <td><?= e($c['category_description'] ?? 'N/A') ?></td>
                        <td><?= e($c['season_description'] ?? 'N/A') ?></td>
                        <td><?= (int) $c['growing_period_days'] ?> days</td>
                        <td class="text-end">
                            <a href="crops.php?view=<?= (int) $c['crop_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            <a href="crops.php?edit=<?= (int) $c['crop_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            <?php if ($can_delete): ?>
                                <a href="crops.php?delete=<?= (int) $c['crop_id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Showing <?= count($crops) ?> of <?= number_format($total_records) ?> crop(s)
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