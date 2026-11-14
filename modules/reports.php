<?php
/*
 * modules/reports.php
 * Six reports, all calculated from the database (nothing is hard-coded):
 *   1. Farmers and their farms          4. Harvest records
 *   2. Crops planted per farm           5. Total harvested quantity
 *   3. Planting records by status       6. Estimated harvest value
 * Every report can be filtered by date range and other fields, is paginated,
 * and can be exported to CSV. No JavaScript is used.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title    = 'System Reports';
$page_subtitle = 'Analytical summaries of farms, plantings, crop yields and estimated revenue';
$active_page   = 'reports';

/** True when $d is a real date written as YYYY-MM-DD (years 2000-2100). */
function valid_date(string $d): bool
{
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d && (int) $o->format('Y') >= 2000 && (int) $o->format('Y') <= 2100;
}

// ---------------------------------------------------------------
// Report list: title, label for the date range, and the filters it uses
// ---------------------------------------------------------------
$reports = [
    'farmers'        => ['title' => 'Farmers and Their Farms',      'date_label' => 'Date registered', 'filters' => ['address']],
    'crops_per_farm' => ['title' => 'Crops Planted per Farm',       'date_label' => 'Planting date',   'filters' => ['farm', 'crop']],
    'status'         => ['title' => 'Planting Records by Status',   'date_label' => 'Planting date',   'filters' => ['farm', 'crop']],
    'harvests'       => ['title' => 'Harvest Records',              'date_label' => 'Harvest date',    'filters' => ['farm', 'crop', 'grade']],
    'quantity'       => ['title' => 'Total Harvested Quantity',     'date_label' => 'Harvest date',    'filters' => ['farm', 'crop', 'grade']],
    'value'          => ['title' => 'Estimated Harvest Value',      'date_label' => 'Harvest date',    'filters' => ['farm', 'crop', 'grade']],
];
$report = $_GET['report'] ?? 'farmers';
if (!isset($reports[$report])) {
    $report = 'farmers';
}
$cfg  = $reports[$report];
$uses = fn(string $f): bool => in_array($f, $cfg['filters'], true);

// ---------------------------------------------------------------
// Filters from the URL (checked before they are used)
// ---------------------------------------------------------------
$filter_error = '';
$date_from    = trim($_GET['date_from'] ?? '');
$date_to      = trim($_GET['date_to'] ?? '');
if ($date_from !== '' && !valid_date($date_from)) {
    $filter_error = 'The "from" date is not valid.';
    $date_from    = '';
}
if ($date_to !== '' && !valid_date($date_to)) {
    $filter_error = 'The "to" date is not valid.';
    $date_to      = '';
}
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $filter_error = 'The "from" date cannot be later than the "to" date.';
    $date_from    = '';
    $date_to      = '';
}
$farm_f    = $uses('farm')    ? (int) ($_GET['farm'] ?? 0)  : 0;
$crop_f    = $uses('crop')    ? (int) ($_GET['crop'] ?? 0)  : 0;
$grade_f   = $uses('grade')   ? (int) ($_GET['grade'] ?? 0) : 0;
$address_f = $uses('address') ? trim($_GET['address'] ?? '') : '';

// ---------------------------------------------------------------
// Value formatting for the table cells (returns safe HTML)
// ---------------------------------------------------------------
$fmt = function ($v, string $type): string {
    if ($v === null || $v === '') {
        return '&mdash;';
    }
    switch ($type) {
        case 'int':   return number_format((float) $v);
        case 'dec':   return number_format((float) $v, 2);
        case 'money': return '&#8369;' . number_format((float) $v, 2);
        case 'pct':   return number_format((float) $v, 1) . '%';
        case 'date':  return e(date('M d, Y', strtotime($v)));
        default:      return e($v);
    }
};

$db_error = '';
$rows     = [];
$sum_row  = [];
$cards    = [];
$total_records = 0;
$page_no       = max(1, (int) ($_GET['page'] ?? 1));
$limit         = 15;
$total_pages   = 1;
$farms_list = $crops_list = $grades_list = $address_list = [];

try {
    $pdo = (new Database())->connect();

    $farms_list   = $pdo->query("SELECT farm_id, farm_name FROM farm ORDER BY farm_name")->fetchAll();
    $crops_list   = $pdo->query("SELECT crop_id, crop_name FROM crop ORDER BY crop_name")->fetchAll();
    $grades_list  = $pdo->query("SELECT grade_id, grade_description FROM quality_grade ORDER BY grade_id")->fetchAll();
    $address_list = $pdo->query("SELECT DISTINCT address FROM farmer ORDER BY address")->fetchAll(PDO::FETCH_COLUMN);

    // ---------- Build the WHERE conditions ----------
    $conds  = [];
    $params = [];
    $cond   = function (string $sql, $value) use (&$conds, &$params) {
        $conds[]  = $sql;
        $params[] = $value;
    };

    switch ($report) {
        case 'farmers':
            $date_col = 'fr.date_registered';
            if ($address_f !== '') { $cond('fr.address = ?', $address_f); }
            break;
        case 'crops_per_farm':
        case 'status':
            $date_col = 'p.planting_date';
            if ($farm_f > 0) { $cond('p.farm_id = ?', $farm_f); }
            if ($crop_f > 0) { $cond('p.crop_id = ?', $crop_f); }
            break;
        default:   // harvests, quantity, value
            $date_col = 'h.harvest_date';
            if ($farm_f > 0)  { $cond('p.farm_id = ?', $farm_f); }
            if ($crop_f > 0)  { $cond('p.crop_id = ?', $crop_f); }
            if ($grade_f > 0) { $cond('h.grade_id = ?', $grade_f); }
    }
    if ($date_from !== '') { $cond("$date_col >= ?", $date_from); }
    if ($date_to !== '')   { $cond("$date_col <= ?", $date_to); }

    $where        = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    $data_where   = $where;
    $count_params = $params;
    $group        = '';

    // ---------- Each report: its SQL, columns and summary cards ----------
    $post = fn(array $r): array => $r;   // optional calculation after the query (percentages)

    switch ($report) {

        case 'farmers':
            $from = "FROM farmer fr
                     LEFT JOIN farm f ON f.farmer_id = fr.farmer_id
                     LEFT JOIN irrigation_type i ON i.irrigation_id = f.irrigation_id
                     LEFT JOIN soil_type s ON s.soil_id = f.soil_id";
            $select = "fr.last_name || ', ' || fr.first_name AS farmer, fr.address, fr.date_registered,
                       f.farm_name, f.location, f.area_hectares, i.irrigation_description AS irrigation,
                       s.soil_description AS soil";
            $order  = "fr.last_name, fr.first_name, f.farm_name";
            $count_sql   = "SELECT COUNT(*) $from $where";
            $summary_sql = "SELECT COUNT(DISTINCT fr.farmer_id) AS farmers, COUNT(f.farm_id) AS farms,
                                   COALESCE(SUM(f.area_hectares), 0) AS area $from $where";
            $columns = [
                ['farmer', 'Farmer', 'text'], ['address', 'Address', 'text'], ['date_registered', 'Registered', 'date'],
                ['farm_name', 'Farm', 'text'], ['location', 'Location', 'text'], ['area_hectares', 'Area (ha)', 'dec'],
                ['irrigation', 'Irrigation', 'text'], ['soil', 'Soil', 'text'],
            ];
            $make_cards = fn($s) => [['Farmers', $fmt($s['farmers'], 'int')], ['Farms', $fmt($s['farms'], 'int')],
                                     ['Total farm area (ha)', $fmt($s['area'], 'dec')]];
            break;

        case 'crops_per_farm':
            $from = "FROM planting p
                     JOIN farm f ON f.farm_id = p.farm_id
                     JOIN crop c ON c.crop_id = p.crop_id";
            $select = "f.farm_name, c.crop_name, COUNT(*) AS plantings, SUM(p.area_planted) AS area,
                       MIN(p.planting_date) AS first_planted, MAX(p.planting_date) AS last_planted";
            $group  = "GROUP BY f.farm_id, f.farm_name, c.crop_id, c.crop_name";
            $order  = "f.farm_name, c.crop_name";
            $count_sql   = "SELECT COUNT(*) FROM (SELECT 1 $from $where $group) t";
            $summary_sql = "SELECT COUNT(*) AS plantings, COUNT(DISTINCT p.farm_id) AS farms,
                                   COUNT(DISTINCT p.crop_id) AS crops, COALESCE(SUM(p.area_planted), 0) AS area
                            $from $where";
            $columns = [
                ['farm_name', 'Farm', 'text'], ['crop_name', 'Crop', 'text'], ['plantings', 'Plantings', 'int'],
                ['area', 'Area Planted (ha)', 'dec'], ['first_planted', 'First Planted', 'date'], ['last_planted', 'Last Planted', 'date'],
            ];
            $make_cards = fn($s) => [['Planting records', $fmt($s['plantings'], 'int')], ['Farms', $fmt($s['farms'], 'int')],
                                     ['Crops', $fmt($s['crops'], 'int')], ['Area planted (ha)', $fmt($s['area'], 'dec')]];
            break;

        case 'status':
            // Conditions go in the JOIN so statuses with 0 plantings still appear.
            $on   = $conds ? ' AND ' . implode(' AND ', $conds) : '';
            $from = "FROM planting_status s LEFT JOIN planting p ON p.status_id = s.status_id$on";
            $data_where   = '';
            $select = "s.status_description AS status, COUNT(p.planting_id) AS plantings,
                       COALESCE(SUM(p.area_planted), 0) AS area";
            $group  = "GROUP BY s.status_id, s.status_description";
            $order  = "s.status_id";
            $count_sql    = "SELECT COUNT(*) FROM planting_status";
            $count_params = [];
            $summary_sql  = "SELECT COUNT(*) AS plantings, COALESCE(SUM(p.area_planted), 0) AS area FROM planting p $where";
            $columns = [
                ['status', 'Status', 'text'], ['plantings', 'Planting Records', 'int'],
                ['area', 'Area Planted (ha)', 'dec'], ['pct', '% of Plantings', 'pct'],
            ];
            $post = function (array $r) use (&$sum_row): array {
                $total = (float) ($sum_row['plantings'] ?? 0);
                foreach ($r as &$x) {
                    $x['pct'] = $total > 0 ? (float) $x['plantings'] / $total * 100 : 0;
                }
                return $r;
            };
            $make_cards = fn($s) => [['Planting records', $fmt($s['plantings'], 'int')], ['Area planted (ha)', $fmt($s['area'], 'dec')]];
            break;

        case 'harvests':
            $from = "FROM harvest h
                     JOIN planting p ON p.planting_id = h.planting_id
                     JOIN farm f ON f.farm_id = p.farm_id
                     JOIN crop c ON c.crop_id = p.crop_id
                     JOIN quality_grade g ON g.grade_id = h.grade_id
                     JOIN harvest_remark r ON r.remark_id = h.remark_id";
            $select = "h.harvest_date, f.farm_name, c.crop_name, h.quantity_kg, h.selling_price_per_kg,
                       (h.quantity_kg * h.selling_price_per_kg) AS total_value,
                       g.grade_description AS grade, r.remark_description AS remark";
            $order  = "h.harvest_date DESC, h.harvest_id DESC";
            $count_sql   = "SELECT COUNT(*) $from $where";
            $summary_sql = "SELECT COUNT(*) AS harvests, COALESCE(SUM(h.quantity_kg), 0) AS qty,
                                   COALESCE(SUM(h.quantity_kg * h.selling_price_per_kg), 0) AS value $from $where";
            $columns = [
                ['harvest_date', 'Date', 'date'], ['farm_name', 'Farm', 'text'], ['crop_name', 'Crop', 'text'],
                ['quantity_kg', 'Quantity (kg)', 'dec'], ['selling_price_per_kg', 'Price / kg', 'money'],
                ['total_value', 'Total Value', 'money'], ['grade', 'Grade', 'text'], ['remark', 'Remarks', 'text'],
            ];
            $make_cards = fn($s) => [['Harvest records', $fmt($s['harvests'], 'int')], ['Total quantity (kg)', $fmt($s['qty'], 'dec')],
                                     ['Estimated value', $fmt($s['value'], 'money')]];
            break;

        case 'quantity':
            $from = "FROM harvest h
                     JOIN planting p ON p.planting_id = h.planting_id
                     JOIN crop c ON c.crop_id = p.crop_id";
            $select = "c.crop_name, COUNT(*) AS harvests, SUM(h.quantity_kg) AS qty";
            $group  = "GROUP BY c.crop_id, c.crop_name";
            $order  = "SUM(h.quantity_kg) DESC, c.crop_name";
            $count_sql   = "SELECT COUNT(*) FROM (SELECT 1 $from $where $group) t";
            $summary_sql = "SELECT COUNT(*) AS harvests, COALESCE(SUM(h.quantity_kg), 0) AS qty $from $where";
            $columns = [
                ['crop_name', 'Crop', 'text'], ['harvests', 'Harvest Records', 'int'],
                ['qty', 'Total Quantity (kg)', 'dec'], ['avg', 'Average per Harvest (kg)', 'dec'], ['pct', '% of Total', 'pct'],
            ];
            $post = function (array $r) use (&$sum_row): array {
                $total = (float) ($sum_row['qty'] ?? 0);
                foreach ($r as &$x) {
                    $x['avg'] = (int) $x['harvests'] > 0 ? (float) $x['qty'] / (int) $x['harvests'] : 0;
                    $x['pct'] = $total > 0 ? (float) $x['qty'] / $total * 100 : 0;
                }
                return $r;
            };
            $make_cards = fn($s) => [['Harvest records', $fmt($s['harvests'], 'int')], ['Total harvested quantity (kg)', $fmt($s['qty'], 'dec')]];
            break;

        default:   // 'value'
            $from = "FROM harvest h
                     JOIN planting p ON p.planting_id = h.planting_id
                     JOIN crop c ON c.crop_id = p.crop_id";
            $select = "c.crop_name, SUM(h.quantity_kg) AS qty,
                       SUM(h.quantity_kg * h.selling_price_per_kg) / NULLIF(SUM(h.quantity_kg), 0) AS avg_price,
                       SUM(h.quantity_kg * h.selling_price_per_kg) AS value";
            $group  = "GROUP BY c.crop_id, c.crop_name";
            $order  = "SUM(h.quantity_kg * h.selling_price_per_kg) DESC, c.crop_name";
            $count_sql   = "SELECT COUNT(*) FROM (SELECT 1 $from $where $group) t";
            $summary_sql = "SELECT COALESCE(SUM(h.quantity_kg), 0) AS qty,
                                   COALESCE(SUM(h.quantity_kg * h.selling_price_per_kg), 0) AS value $from $where";
            $columns = [
                ['crop_name', 'Crop', 'text'], ['qty', 'Total Quantity (kg)', 'dec'], ['avg_price', 'Average Price / kg', 'money'],
                ['value', 'Estimated Value', 'money'], ['pct', '% of Total Value', 'pct'],
            ];
            $post = function (array $r) use (&$sum_row): array {
                $total = (float) ($sum_row['value'] ?? 0);
                foreach ($r as &$x) {
                    $x['pct'] = $total > 0 ? (float) $x['value'] / $total * 100 : 0;
                }
                return $r;
            };
            $make_cards = fn($s) => [['Total harvested quantity (kg)', $fmt($s['qty'], 'dec')],
                                     ['Estimated harvest value', $fmt($s['value'], 'money')]];
    }

    // ---------- Run the queries ----------
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($count_params);
    $total_records = (int) $stmt->fetchColumn();
    $total_pages   = max(1, (int) ceil($total_records / $limit));
    $page_no       = min($page_no, $total_pages);

    $stmt = $pdo->prepare($summary_sql);
    $stmt->execute($params);
    $sum_row = $stmt->fetch() ?: [];
    $cards   = $make_cards($sum_row);

    $data_sql = "SELECT $select $from $data_where $group ORDER BY $order";
    $exporting = (($_GET['export'] ?? '') === 'csv');
    if (!$exporting) {
        $data_sql .= " LIMIT $limit OFFSET " . (($page_no - 1) * $limit);
    }
    $stmt = $pdo->prepare($data_sql);
    $stmt->execute($params);
    $rows = $post($stmt->fetchAll());
    unset($x);

} catch (PDOException $ex) {
    // The real error goes to the server log, not to the screen.
    error_log($ex->getMessage());
    $db_error  = 'The report could not be generated. Please try again later.';
    $exporting = false;
}

// ---------------------------------------------------------------
// CSV export (the same filters, all rows, no pagination)
// ---------------------------------------------------------------
if (!empty($exporting) && $db_error === '') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report_' . $report . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file as UTF-8
    fputcsv($out, array_column($columns, 1));
    foreach ($rows as $r) {
        $line = [];
        foreach ($columns as $col) {
            $v = $r[$col[0]] ?? '';
            if ($col[2] === 'pct' && $v !== '') { $v = round((float) $v, 1); }
            if (is_string($v) && preg_match('/^[=+\-@]/', $v)) { $v = "'" . $v; }   // stops spreadsheet formulas
            $line[] = $v;
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

// Link builder that keeps the report and all filters
$base_query = [
    'report' => $report, 'date_from' => $date_from, 'date_to' => $date_to,
    'farm' => $farm_f ?: '', 'crop' => $crop_f ?: '', 'grade' => $grade_f ?: '',
    'address' => $address_f, 'page' => $page_no,
];
$qs = function (array $override = []) use ($base_query): string {
    $q = array_filter(array_merge($base_query, $override), fn($v) => $v !== '' && $v !== null);
    return 'reports.php?' . http_build_query($q);
};
$has_filters = ($date_from !== '' || $date_to !== '' || $farm_f > 0 || $crop_f > 0 || $grade_f > 0 || $address_f !== '');

require __DIR__ . '/../includes/header.php';
?>

<!-- Report selector -->
<ul class="nav nav-pills mb-3 flex-wrap gap-1">
    <?php foreach ($reports as $key => $r): ?>
        <li class="nav-item">
            <a class="nav-link <?= $key === $report ? 'active bg-success' : 'text-success' ?>"
               href="reports.php?report=<?= e($key) ?>"><?= e($r['title']) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($db_error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($db_error) ?></div>
<?php endif; ?>
<?php if ($filter_error !== ''): ?>
    <div class="alert alert-warning" role="alert"><?= e($filter_error) ?> The filter was ignored.</div>
<?php endif; ?>

<!-- Filters -->
<div class="card panel mb-3">
    <div class="card-header"><?= e($cfg['title']) ?> &mdash; Filters</div>
    <div class="card-body">
        <form method="GET" action="reports.php" class="row g-2 align-items-end">
            <input type="hidden" name="report" value="<?= e($report) ?>">

            <div class="col-md-2">
                <label class="form-label small mb-1"><?= e($cfg['date_label']) ?>: from</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">to</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
            </div>

            <?php if ($uses('address')): ?>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Address</label>
                    <select name="address" class="form-select">
                        <option value="">All addresses</option>
                        <?php foreach ($address_list as $addr): ?>
                            <option value="<?= e($addr) ?>" <?= $address_f === $addr ? 'selected' : '' ?>><?= e($addr) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($uses('farm')): ?>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Farm</label>
                    <select name="farm" class="form-select">
                        <option value="0">All farms</option>
                        <?php foreach ($farms_list as $fm): ?>
                            <option value="<?= (int) $fm['farm_id'] ?>" <?= $farm_f === (int) $fm['farm_id'] ? 'selected' : '' ?>><?= e($fm['farm_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($uses('crop')): ?>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Crop</label>
                    <select name="crop" class="form-select">
                        <option value="0">All crops</option>
                        <?php foreach ($crops_list as $cp): ?>
                            <option value="<?= (int) $cp['crop_id'] ?>" <?= $crop_f === (int) $cp['crop_id'] ? 'selected' : '' ?>><?= e($cp['crop_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($uses('grade')): ?>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Quality grade</label>
                    <select name="grade" class="form-select">
                        <option value="0">All grades</option>
                        <?php foreach ($grades_list as $gr): ?>
                            <option value="<?= (int) $gr['grade_id'] ?>" <?= $grade_f === (int) $gr['grade_id'] ? 'selected' : '' ?>><?= e($gr['grade_description']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-success">Generate</button>
                <?php if ($has_filters): ?>
                    <a href="reports.php?report=<?= e($report) ?>" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
                <a href="<?= e($qs(['export' => 'csv', 'page' => ''])) ?>" class="btn btn-outline-success">Export CSV</a>
            </div>
        </form>
    </div>
</div>

<!-- Summary -->
<div class="row g-3 mb-3">
    <?php foreach ($cards as [$label, $value]): ?>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label"><?= e($label) ?></div>
                <div class="stat-value" style="font-size: 1.4rem;"><?= $value ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Report table -->
<div class="card panel">
    <div class="card-header"><?= e($cfg['title']) ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <?php foreach ($columns as [$key, $label, $type]): ?>
                        <th class="<?= in_array($type, ['int', 'dec', 'money', 'pct'], true) ? 'text-end' : '' ?>"><?= e($label) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-4">No records match the selected filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <?php foreach ($columns as [$key, $label, $type]): ?>
                            <td class="<?= in_array($type, ['int', 'dec', 'money', 'pct'], true) ? 'text-end' : '' ?>"><?= $fmt($r[$key] ?? null, $type) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Showing <?= count($rows) ?> of <?= number_format($total_records) ?> row(s) &middot; generated <?= e(date('M d, Y h:i A')) ?>
    </div>
</div>

<?php if ($total_pages > 1): ?>
    <nav class="mt-3"><ul class="pagination justify-content-center flex-wrap">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i === $page_no ? 'active' : '' ?>">
                <a class="page-link" href="<?= e($qs(['page' => $i])) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>