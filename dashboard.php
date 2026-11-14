<?php
/*
 * dashboard.php
 * Summary cards, plantings by status (donut), harvested quantity per crop (bars)
 * and recent records. All numbers come from the Supabase database.
 * No JavaScript is used: the charts are made with plain HTML and CSS.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_login();

$page_title    = 'Dashboard';
$page_subtitle = 'Overview of farmers, farms, plantings and harvests';
$active_page   = 'dashboard';

// Default values, in case the database cannot be read.
$totals = [
    'farmers' => 0, 'farms' => 0, 'crops' => 0, 'plantings' => 0, 'harvests' => 0,
    'total_kg' => 0.0, 'total_value' => 0.0,
];
$status_rows      = [];
$crop_rows        = [];
$recent_plantings = [];
$recent_harvests  = [];
$db_error         = '';

try {
    $pdo = (new Database())->connect();

    // 1. All the totals in one query
    $row = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM farmer)   AS farmers,
            (SELECT COUNT(*) FROM farm)     AS farms,
            (SELECT COUNT(*) FROM crop)     AS crops,
            (SELECT COUNT(*) FROM planting) AS plantings,
            (SELECT COUNT(*) FROM harvest)  AS harvests,
            (SELECT COALESCE(SUM(quantity_kg), 0) FROM harvest) AS total_kg,
            (SELECT COALESCE(SUM(quantity_kg * selling_price_per_kg), 0) FROM harvest) AS total_value
    ")->fetch();

    $totals = [
        'farmers'     => (int) $row['farmers'],
        'farms'       => (int) $row['farms'],
        'crops'       => (int) $row['crops'],
        'plantings'   => (int) $row['plantings'],
        'harvests'    => (int) $row['harvests'],
        'total_kg'    => (float) $row['total_kg'],
        'total_value' => (float) $row['total_value'],
    ];

    // 2. Plantings by status (LEFT JOIN keeps statuses that have zero plantings)
    $status_rows = $pdo->query("
        SELECT s.status_description, COUNT(p.planting_id) AS total
        FROM planting_status s
        LEFT JOIN planting p ON p.status_id = s.status_id
        GROUP BY s.status_id, s.status_description
        ORDER BY s.status_id
    ")->fetchAll();

    // 3. Harvested quantity per crop (up to 10 crops, biggest first)
    $crop_rows = $pdo->query("
        SELECT c.crop_name,
               SUM(h.quantity_kg) AS total_kg,
               SUM(h.quantity_kg * h.selling_price_per_kg) AS total_value
        FROM harvest h
        JOIN planting p ON p.planting_id = h.planting_id
        JOIN crop c     ON c.crop_id = p.crop_id
        GROUP BY c.crop_id, c.crop_name
        ORDER BY total_kg DESC
        LIMIT 10
    ")->fetchAll();

    // 4. Five newest planting records
    $recent_plantings = $pdo->query("
        SELECT p.planting_id, f.farm_name, c.crop_name, p.planting_date, s.status_description
        FROM planting p
        JOIN farm f            ON f.farm_id = p.farm_id
        JOIN crop c            ON c.crop_id = p.crop_id
        JOIN planting_status s ON s.status_id = p.status_id
        ORDER BY p.planting_date DESC, p.planting_id DESC
        LIMIT 5
    ")->fetchAll();

    // 5. Five newest harvest records
    $recent_harvests = $pdo->query("
        SELECT h.harvest_id, h.harvest_date, c.crop_name, f.farm_name,
               h.quantity_kg, g.grade_description
        FROM harvest h
        JOIN planting p      ON p.planting_id = h.planting_id
        JOIN farm f          ON f.farm_id = p.farm_id
        JOIN crop c          ON c.crop_id = p.crop_id
        JOIN quality_grade g ON g.grade_id = h.grade_id
        ORDER BY h.harvest_date DESC, h.harvest_id DESC
        LIMIT 5
    ")->fetchAll();

} catch (PDOException $ex) {
    // The real error goes to the server log, not to the screen.
    error_log($ex->getMessage());
    $db_error = 'The dashboard data could not be loaded. Please try again later.';
}

// Bootstrap color for each planting status (badges)
$status_colors = [
    'Planted'           => 'info',
    'Growing'           => 'success',
    'Ready for Harvest' => 'warning',
    'Harvested'         => 'primary',
    'Failed'            => 'danger',
];

// Hex color for each planting status (donut chart and legend)
$status_hex = [
    'Planted'           => '#0d6efd',
    'Growing'           => '#198754',
    'Ready for Harvest' => '#fd7e14',
    'Harvested'         => '#ffc107',
    'Failed'            => '#dc3545',
];

// ---------- Donut chart: build the CSS conic-gradient ----------
$status_total = 0;
foreach ($status_rows as $s) {
    $status_total += (int) $s['total'];
}

$donut_parts = [];
$cumulative  = 0.0;
foreach ($status_rows as $s) {
    $count = (int) $s['total'];
    if ($count === 0 || $status_total === 0) {
        continue;
    }
    $hex   = $status_hex[$s['status_description']] ?? '#6c757d';
    $start = $cumulative;
    $cumulative += $count / $status_total * 100;
    $donut_parts[] = $hex . ' ' . number_format($start, 2, '.', '') . '% ' . number_format($cumulative, 2, '.', '') . '%';
}
$donut_bg = $donut_parts ? 'conic-gradient(' . implode(', ', $donut_parts) . ')'
                         : 'conic-gradient(#dee2e6 0% 100%)';

// ---------- Bar chart: the biggest crop quantity sizes the bars ----------
$max_crop_kg = 0;
foreach ($crop_rows as $c) {
    $max_crop_kg = max($max_crop_kg, (float) $c['total_kg']);
}

require __DIR__ . '/includes/header.php';
?>

<style>
    /* Dashboard charts (plain CSS, no JavaScript) */
    .dash-card-head {
        display: flex; justify-content: space-between; align-items: center;
    }
    .dash-card-head a { font-size: .8rem; text-decoration: none; }

    /* Donut */
    .donut-wrap { display: flex; justify-content: center; padding: .5rem 0 1rem; }
    .donut {
        position: relative; width: 210px; height: 210px; border-radius: 50%;
    }
    .donut::after {
        content: ''; position: absolute; inset: 24%;
        border-radius: 50%; background: var(--bs-card-bg, #fff);
    }
    .donut-center {
        position: absolute; inset: 0; z-index: 1;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        line-height: 1.1;
    }
    .donut-center strong { font-size: 1.8rem; }
    .donut-center span   { font-size: .75rem; color: #6c757d; }

    .legend { display: flex; flex-wrap: wrap; justify-content: center; gap: .4rem 1rem; margin-bottom: 1rem; font-size: .8rem; }
    .legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }

    /* Bar chart */
    .bar-chart { display: flex; align-items: stretch; gap: 10px; height: 290px; padding-top: 10px; }
    .bar-col   { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .bar-area  {
        flex: 1; display: flex; align-items: flex-end; justify-content: center;
        background-image: repeating-linear-gradient(to top, #e9ecef 0, #e9ecef 1px, transparent 1px, transparent 25%);
    }
    .bar       { width: 70%; max-width: 46px; background: #198754; border-radius: 4px 4px 0 0; min-height: 3px; }
    .bar-col:hover .bar { background: #146c43; }
    .bar-label { text-align: center; font-size: .7rem; line-height: 1.2; margin-top: 6px; height: 2.6rem; overflow: hidden; }
    .bar-label span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bar-label .kg  { color: #6c757d; }
</style>

<?php if ($db_error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($db_error) ?></div>
<?php endif; ?>

<!-- ========== Summary cards ========== -->
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
    <div class="col">
        <a class="stat-card" href="<?= BASE_URL ?>/modules/farmers.php">
            <div class="stat-label">Farmers</div>
            <div class="stat-value"><?= number_format($totals['farmers']) ?></div>
        </a>
    </div>
    <div class="col">
        <a class="stat-card" href="<?= BASE_URL ?>/modules/farms.php">
            <div class="stat-label">Farms</div>
            <div class="stat-value"><?= number_format($totals['farms']) ?></div>
        </a>
    </div>
    <div class="col">
        <a class="stat-card" href="<?= BASE_URL ?>/modules/crops.php">
            <div class="stat-label">Crops</div>
            <div class="stat-value"><?= number_format($totals['crops']) ?></div>
        </a>
    </div>
    <div class="col">
        <a class="stat-card" href="<?= BASE_URL ?>/modules/plantings.php">
            <div class="stat-label">Plantings</div>
            <div class="stat-value"><?= number_format($totals['plantings']) ?></div>
        </a>
    </div>
    <div class="col">
        <a class="stat-card" href="<?= BASE_URL ?>/modules/harvests.php">
            <div class="stat-label">Harvests</div>
            <div class="stat-value"><?= number_format($totals['harvests']) ?></div>
        </a>
    </div>
</div>

<!-- ========== Harvest totals ========== -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="stat-card stat-card-dark">
            <div class="stat-label">Total harvested quantity</div>
            <div class="stat-value"><?= number_format($totals['total_kg'], 2) ?> <small>kg</small></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card stat-card-dark">
            <div class="stat-label">Estimated harvest value</div>
            <div class="stat-value">&#8369;<?= number_format($totals['total_value'], 2) ?></div>
        </div>
    </div>
</div>

<!-- ========== Charts (HTML and CSS only) ========== -->
<div class="row g-3 mb-4">

    <!-- Plantings by status: donut -->
    <div class="col-lg-5">
        <div class="card panel h-100">
            <div class="card-header">Plantings by status</div>
            <div class="card-body">
                <?php if (!$status_rows): ?>
                    <p class="text-muted mb-0">No records yet.</p>
                <?php else: ?>
                    <div class="donut-wrap">
                        <div class="donut" style="background: <?= e($donut_bg) ?>;"
                             role="img" aria-label="Plantings by status chart">
                            <div class="donut-center">
                                <strong><?= number_format($status_total) ?></strong>
                                <span>plantings</span>
                            </div>
                        </div>
                    </div>

                    <div class="legend">
                        <?php foreach ($status_rows as $s):
                            $hex = $status_hex[$s['status_description']] ?? '#6c757d';
                        ?>
                            <span><span class="legend-dot" style="background: <?= e($hex) ?>;"></span><?= e($s['status_description']) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <ul class="list-group list-group-flush">
                        <?php foreach ($status_rows as $s):
                            $count = (int) $s['total'];
                            $pct   = $status_total > 0 ? round($count / $status_total * 100) : 0;
                            $color = $status_colors[$s['status_description']] ?? 'secondary';
                        ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><?= e($s['status_description']) ?></span>
                                <span><span class="text-muted small me-2"><?= $pct ?>%</span>
                                    <span class="badge text-bg-<?= e($color) ?>"><?= $count ?></span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Harvested quantity per crop: bars -->
    <div class="col-lg-7">
        <div class="card panel h-100">
            <div class="card-header">Harvested quantity per crop (kg)</div>
            <div class="card-body">
                <?php if (!$crop_rows): ?>
                    <p class="text-muted mb-0">No harvest records yet.</p>
                <?php else: ?>
                    <div class="bar-chart">
                        <?php foreach ($crop_rows as $c):
                            $kg     = (float) $c['total_kg'];
                            $height = $max_crop_kg > 0 ? round($kg / $max_crop_kg * 100) : 0;
                            $tip    = $c['crop_name'] . ': ' . number_format($kg, 2) . ' kg (PHP ' . number_format((float) $c['total_value'], 2) . ')';
                        ?>
                            <div class="bar-col" title="<?= e($tip) ?>">
                                <div class="bar-area">
                                    <div class="bar" style="height: <?= $height ?>%;"></div>
                                </div>
                                <div class="bar-label">
                                    <span><?= e($c['crop_name']) ?></span>
                                    <span class="kg"><?= number_format($kg) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ========== Recent records ========== -->
<div class="row g-3">

    <div class="col-xl-6">
        <div class="card panel">
            <div class="card-header dash-card-head">
                <span>Recent planting records</span>
                <a href="<?= BASE_URL ?>/modules/plantings.php">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Farm</th>
                            <th>Crop</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recent_plantings): ?>
                            <tr><td colspan="4" class="text-muted text-center py-3">No records yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recent_plantings as $p):
                            $color = $status_colors[$p['status_description']] ?? 'secondary';
                        ?>
                            <tr>
                                <td><?= e(date('M d, Y', strtotime($p['planting_date']))) ?></td>
                                <td><?= e($p['farm_name']) ?></td>
                                <td><?= e($p['crop_name']) ?></td>
                                <td><span class="badge text-bg-<?= e($color) ?>"><?= e($p['status_description']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card panel">
            <div class="card-header dash-card-head">
                <span>Recent harvest records</span>
                <a href="<?= BASE_URL ?>/modules/harvests.php">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Crop</th>
                            <th>Farm</th>
                            <th class="text-end">Quantity</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recent_harvests): ?>
                            <tr><td colspan="5" class="text-muted text-center py-3">No records yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recent_harvests as $h): ?>
                            <tr>
                                <td><?= e(date('M d, Y', strtotime($h['harvest_date']))) ?></td>
                                <td><?= e($h['crop_name']) ?></td>
                                <td><?= e($h['farm_name']) ?></td>
                                <td class="text-end"><?= number_format((float) $h['quantity_kg'], 2) ?> kg</td>
                                <td><?= e($h['grade_description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>